<?php

declare(strict_types=1);

/**
 * Heatit WiFi6 / WiFi7 Thermostat – lokale HTTP-API (ohne Authentifizierung).
 *
 *   GET    /api/status                   -> Status + "parameters"
 *   POST   /api/parameters               -> JSON {"<parameter>": <wert>}  (Antwort: {"status":"Success"})
 *   DELETE /api/reset/kwh?reset=reset    -> Energiezähler zurücksetzen
 */
class HeatItWiFiThermostat extends IPSModule
{
    private const NTC_FAULT = 100.0;      // Fühler nicht angeschlossen / Kurzschluss
    private const SENSORMODE_RELAY = 7;   // nur WiFi7

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 80);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('Timeout', 5);
        $this->RegisterPropertyBoolean('ShowFloor', false);
        $this->RegisterPropertyBoolean('ShowExternal', false);
        $this->RegisterPropertyBoolean('ShowAdvanced', false);

        $this->RegisterAttributeBoolean('IsWifi7', false);
        $this->RegisterAttributeString('ParamKeys', '[]');

        $this->RegisterTimer('UpdateTimer', 0, 'HEATITWIFI_Update($_IPS[\'TARGET\']);');

        $this->CreateProfiles();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // --- Standard-Variablen -------------------------------------------------
        $this->RegisterVariableInteger('OperatingMode', 'Betriebsmodus', 'HEATITWIFI.Mode', 10);
        $this->EnableAction('OperatingMode');
        $this->RegisterVariableFloat('Setpoint', 'Solltemperatur (aktiver Modus)', 'HEATITWIFI.Setpoint', 20);
        $this->EnableAction('Setpoint');
        $this->RegisterVariableFloat('Temperature', 'Ist-Temperatur', '~Temperature', 30);
        $this->RegisterVariableInteger('State', 'Status', 'HEATITWIFI.State', 40);
        $this->RegisterVariableInteger('Power', 'Leistung', 'HEATITWIFI.Power', 50);
        $this->RegisterVariableFloat('Energy', 'Energieverbrauch', 'HEATITWIFI.Energy', 60);
        $this->RegisterVariableFloat('HeatingSetpoint', 'Solltemperatur Heizen', 'HEATITWIFI.Setpoint', 70);
        $this->EnableAction('HeatingSetpoint');
        $this->RegisterVariableFloat('CoolingSetpoint', 'Solltemperatur Kühlen', 'HEATITWIFI.Setpoint', 71);
        $this->EnableAction('CoolingSetpoint');
        $this->RegisterVariableFloat('EcoSetpoint', 'Solltemperatur Eco', 'HEATITWIFI.Setpoint', 72);
        $this->EnableAction('EcoSetpoint');
        $this->RegisterVariableBoolean('OpenWindowActive', 'Fenster-offen erkannt', '~Alert', 80);
        $this->RegisterVariableInteger('WifiSignal', 'WLAN-Signal', 'HEATITWIFI.Rssi', 90);
        $this->RegisterVariableString('Firmware', 'Firmware', '', 91);

        // --- Optionale Variablen ----------------------------------------------
        $this->MaintainVariable('FloorTemperature', 'Bodentemperatur', VARIABLETYPE_FLOAT, '~Temperature', 31, $this->ReadPropertyBoolean('ShowFloor'));
        $this->MaintainVariable('ExternalTemperature', 'Externe Temperatur', VARIABLETYPE_FLOAT, '~Temperature', 32, $this->ReadPropertyBoolean('ShowExternal'));

        $adv = $this->ReadPropertyBoolean('ShowAdvanced');
        $this->MaintainVariable('ChildLock', 'Kindersicherung', VARIABLETYPE_BOOLEAN, '~Switch', 100, $adv);
        $this->MaintainVariable('OpenWindowDetection', 'Fenster-offen-Erkennung', VARIABLETYPE_BOOLEAN, '~Switch', 101, $adv);
        $this->MaintainVariable('DisplayTemperature', 'Display zeigt Ist-Temperatur (Standby)', VARIABLETYPE_BOOLEAN, '~Switch', 102, $adv);
        $this->MaintainVariable('BrightnessActive', 'Display-Helligkeit aktiv', VARIABLETYPE_INTEGER, 'HEATITWIFI.Brightness', 103, $adv);
        $this->MaintainVariable('BrightnessStandby', 'Display-Helligkeit Standby', VARIABLETYPE_INTEGER, 'HEATITWIFI.Brightness', 104, $adv);
        $this->MaintainVariable('SensorMode', 'Fühlermodus', VARIABLETYPE_INTEGER, 'HEATITWIFI.SensorMode', 105, $adv);
        $this->MaintainVariable('RegulationMode', 'Regelart', VARIABLETYPE_INTEGER, 'HEATITWIFI.Regulation', 106, $adv);
        $this->MaintainVariable('Hysteresis', 'Hysterese', VARIABLETYPE_FLOAT, 'HEATITWIFI.Hysteresis', 107, $adv);
        if ($adv) {
            foreach (['ChildLock', 'OpenWindowDetection', 'DisplayTemperature', 'BrightnessActive', 'BrightnessStandby', 'SensorMode', 'RegulationMode', 'Hysteresis'] as $ident) {
                $this->EnableAction($ident);
            }
        }

        // --- Verbindung ---------------------------------------------------------
        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $this->SetStatus(102);
        // Erste Abfrage asynchron über den Timer, damit ApplyChanges nicht blockiert.
        $this->SetTimerInterval('UpdateTimer', 1000);
    }

    // =====================================================================
    // Öffentliche Funktionen
    // =====================================================================

    /** Status vom Thermostat lesen und Variablen aktualisieren. */
    public function Update(): bool
    {
        $interval = max(10, $this->ReadPropertyInteger('UpdateInterval')) * 1000;
        $this->SetTimerInterval('UpdateTimer', $interval);

        if (trim($this->ReadPropertyString('Host')) === '') {
            return false;
        }

        $data = $this->Request('GET', '/api/status');
        if ($data === null) {
            $this->SetStatus(201);
            return false;
        }
        $this->SetStatus(102);
        $this->ProcessStatus($data);
        return true;
    }

    /** Betriebsmodus setzen: 0 = Aus, 1 = Heizen, 2 = Kühlen, 3 = Eco. */
    public function SetOperatingMode(int $Mode): bool
    {
        if ($Mode < 0 || $Mode > 3) {
            $this->LogMessage('Ungültiger Betriebsmodus: ' . $Mode, KL_WARNING);
            return false;
        }
        if (!$this->WriteParameter('operatingMode', $Mode)) {
            return false;
        }
        $this->SetValue('OperatingMode', $Mode);
        $this->UpdateActiveSetpoint();
        return true;
    }

    /** Solltemperatur des aktuell aktiven Modus setzen (Aus => Heizen-Sollwert). */
    public function SetTargetTemperature(float $Temperature): bool
    {
        switch ($this->GetValue('OperatingMode')) {
            case 2:
                return $this->SetCoolingSetpoint($Temperature);
            case 3:
                return $this->SetEcoSetpoint($Temperature);
            default:
                return $this->SetHeatingSetpoint($Temperature);
        }
    }

    public function SetHeatingSetpoint(float $Temperature): bool
    {
        return $this->WriteSetpoint('heatingSetpoint', 'HeatingSetpoint', $Temperature);
    }

    public function SetCoolingSetpoint(float $Temperature): bool
    {
        return $this->WriteSetpoint('coolingSetpoint', 'CoolingSetpoint', $Temperature);
    }

    public function SetEcoSetpoint(float $Temperature): bool
    {
        return $this->WriteSetpoint('ecoSetpoint', 'EcoSetpoint', $Temperature);
    }

    /**
     * Beliebigen Parameter der API setzen (siehe Heatit OpenAPI-Spezifikation).
     * Der Wert wird als JSON übergeben, z. B. SetParameter('openWindowDetection', 'true')
     * oder SetParameter('internalCalibration', '-1.5').
     */
    public function SetParameter(string $Parameter, string $ValueJson): bool
    {
        $value = json_decode($ValueJson, true);
        if ($value === null && strtolower(trim($ValueJson)) !== 'null') {
            $this->LogMessage('Wert ist kein gültiges JSON: ' . $ValueJson, KL_WARNING);
            return false;
        }
        return $this->WriteParameter($Parameter, $value);
    }

    /** Roh-Status des Thermostats als JSON-String (zur Diagnose). */
    public function GetStatusJSON(): string
    {
        $data = $this->Request('GET', '/api/status');
        return $data === null ? '' : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** Relais schalten (nur WiFi7 im Fühlermodus "Relais"). */
    public function SetRelay(bool $State): bool
    {
        if (!$this->WriteParameter('onOff', $State)) {
            return false;
        }
        $this->SetValue('Relay', $State);
        return true;
    }

    /** Energiezähler im Gerät auf 0 setzen. */
    public function ResetEnergy(): bool
    {
        $r = $this->Request('DELETE', '/api/reset/kwh?reset=reset');
        if ($r !== null && strtolower((string) ($r['status'] ?? '')) === 'success') {
            $this->Update();
            return true;
        }
        $this->LogMessage('Zurücksetzen des Energiezählers fehlgeschlagen', KL_ERROR);
        return false;
    }

    // =====================================================================
    // Aktionen aus dem WebFront
    // =====================================================================

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'OperatingMode':
                $this->SetOperatingMode((int) $Value);
                break;
            case 'Setpoint':
                $this->SetTargetTemperature((float) $Value);
                break;
            case 'HeatingSetpoint':
                $this->SetHeatingSetpoint((float) $Value);
                break;
            case 'CoolingSetpoint':
                $this->SetCoolingSetpoint((float) $Value);
                break;
            case 'EcoSetpoint':
                $this->SetEcoSetpoint((float) $Value);
                break;
            case 'Relay':
                $this->SetRelay((bool) $Value);
                break;
            case 'ChildLock':
                // Die WiFi7-Firmware akzeptiert nur 0/1, keine echten Booleans.
                $this->WriteAndStore('disableButtons', (int) (bool) $Value, $Ident, (bool) $Value);
                break;
            case 'OpenWindowDetection':
                $this->WriteAndStore('openWindowDetection', (bool) $Value, $Ident, (bool) $Value);
                break;
            case 'DisplayTemperature':
                // WiFi7 hat den Parameter in "displayInformation" umbenannt.
                $this->WriteAndStore($this->ParamName('temperatureDisplay', 'displayInformation'), (int) (bool) $Value, $Ident, (bool) $Value);
                break;
            case 'BrightnessActive':
                $this->WriteAndStore('activeDisplayBrightness', $this->BrightnessToRaw((int) $Value), $Ident, (int) $Value);
                break;
            case 'BrightnessStandby':
                $this->WriteAndStore('standbyDisplayBrightness', $this->BrightnessToRaw((int) $Value), $Ident, (int) $Value);
                break;
            case 'SensorMode':
                $this->WriteAndStore('sensorMode', (int) $Value, $Ident, (int) $Value);
                break;
            case 'RegulationMode':
                $this->WriteAndStore('regulationMode', (bool) $Value, $Ident, (int) $Value);
                break;
            case 'Hysteresis':
                $v = round(min(3.0, max(0.3, (float) $Value)), 1);
                $this->WriteAndStore('temperatureControlHysteresis', $v, $Ident, $v);
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    // =====================================================================
    // Status verarbeiten
    // =====================================================================

    private function ProcessStatus(array $d): void
    {
        $p = (isset($d['parameters']) && is_array($d['parameters'])) ? $d['parameters'] : [];
        $this->WriteAttributeString('ParamKeys', json_encode(array_keys($p)));

        // WiFi7 meldet ein "model"-Feld, WiFi6 nicht.
        $isWifi7 = !empty($d['model']);
        $this->WriteAttributeBoolean('IsWifi7', $isWifi7);

        $sensorMode = $p['sensorMode'] ?? null;

        // Betriebsmodus & Sollwerte
        if (isset($p['operatingMode'])) {
            $this->SetValue('OperatingMode', (int) $p['operatingMode']);
        }
        foreach (['heatingSetpoint' => 'HeatingSetpoint', 'coolingSetpoint' => 'CoolingSetpoint', 'ecoSetpoint' => 'EcoSetpoint'] as $key => $ident) {
            if (isset($p[$key])) {
                $this->SetValue($ident, (float) $p[$key]);
            }
        }
        $this->UpdateActiveSetpoint();

        // Temperaturen
        $internal = $d['internalTemperature'] ?? null;
        $floor = $this->ValidNtc($d['floorTemperature'] ?? null);
        $external = $this->ValidNtc($d['externalTemperature'] ?? null);

        $current = $internal;
        if ($sensorMode === 0) {
            $current = $floor;
        } elseif ($sensorMode === 3 || $sensorMode === 4) {
            $current = $external;
        }
        if ($current !== null) {
            $this->SetValue('Temperature', (float) $current);
        }
        if ($floor !== null && $this->ReadPropertyBoolean('ShowFloor')) {
            $this->SetValue('FloorTemperature', $floor);
        }
        if ($external !== null && $this->ReadPropertyBoolean('ShowExternal')) {
            $this->SetValue('ExternalTemperature', $external);
        }

        // Zustand
        $state = strtolower((string) ($d['state'] ?? ''));
        $stateMap = ['idle' => 0, 'heating' => 1, 'cooling' => 2, 'open' => 3, 'close' => 4, 'closed' => 4];
        if (isset($stateMap[$state])) {
            $this->SetValue('State', $stateMap[$state]);
        }

        // Leistung / Energie
        if (isset($d['currentPower'])) {
            $this->SetValue('Power', (int) $d['currentPower']);
        }
        if (isset($d['totalConsumption'])) {
            $this->SetValue('Energy', (float) $d['totalConsumption']);
        }

        // Fenster-offen-Erkennung (im Status verschachtelt unter parameters.OWD)
        $owd = (isset($p['OWD']) && is_array($p['OWD'])) ? $p['OWD'] : [];
        if (isset($owd['activeNow'])) {
            $this->SetValue('OpenWindowActive', (bool) $owd['activeNow']);
        }
        if ($this->ReadPropertyBoolean('ShowAdvanced')) {
            $owdEnabled = $owd['openWindowDetection'] ?? ($p['openWindowDetection'] ?? null);
            if ($owdEnabled !== null) {
                $this->SetValue('OpenWindowDetection', (bool) $owdEnabled);
            }
            if (isset($p['disableButtons'])) {
                $this->SetValue('ChildLock', (bool) $p['disableButtons']);
            }
            $display = $p['temperatureDisplay'] ?? ($p['displayInformation'] ?? null);
            if ($display !== null) {
                $this->SetValue('DisplayTemperature', (bool) $display);
            }
            if (isset($p['activeDisplayBrightness'])) {
                $this->SetValue('BrightnessActive', $this->BrightnessFromRaw((int) $p['activeDisplayBrightness']));
            }
            if (isset($p['standbyDisplayBrightness'])) {
                $this->SetValue('BrightnessStandby', $this->BrightnessFromRaw((int) $p['standbyDisplayBrightness']));
            }
            if ($sensorMode !== null) {
                $this->SetValue('SensorMode', (int) $sensorMode);
            }
            if (isset($p['regulationMode'])) {
                $this->SetValue('RegulationMode', (int) (bool) $p['regulationMode']);
            }
            if (isset($p['temperatureControlHysteresis'])) {
                $this->SetValue('Hysteresis', (float) $p['temperatureControlHysteresis']);
            }
        }

        // WLAN & Firmware (WiFi7 liefert das RSSI auf oberster Ebene, die Spezifikation unter "network")
        $rssi = $d['wifiSignalStrength'] ?? ($d['network']['wifiSignalStrength'] ?? null);
        if ($rssi !== null) {
            $this->SetValue('WifiSignal', (int) trim(str_ireplace('dBm', '', (string) $rssi)));
        }
        if (isset($d['firmware'])) {
            $this->SetValue('Firmware', (string) $d['firmware']);
        }

        // Relais (WiFi7, Fühlermodus "Relais")
        if ($sensorMode === self::SENSORMODE_RELAY || in_array($state, ['open', 'close', 'closed'], true) || isset($p['onOff'])) {
            $this->RegisterVariableBoolean('Relay', 'Relais', '~Switch', 45);
            $this->EnableAction('Relay');
            if (in_array($state, ['open', 'close', 'closed'], true)) {
                $this->SetValue('Relay', $state !== 'open');
            } elseif (isset($p['onOff'])) {
                $this->SetValue('Relay', (bool) $p['onOff']);
            }
        }
    }

    private function ValidNtc($value): ?float
    {
        if ($value === null || (float) $value === self::NTC_FAULT) {
            return null;
        }
        return (float) $value;
    }

    private function UpdateActiveSetpoint(): void
    {
        switch ($this->GetValue('OperatingMode')) {
            case 2:
                $v = $this->GetValue('CoolingSetpoint');
                break;
            case 3:
                $v = $this->GetValue('EcoSetpoint');
                break;
            default:
                $v = $this->GetValue('HeatingSetpoint');
                break;
        }
        $this->SetValue('Setpoint', $v);
    }

    // =====================================================================
    // Schreiben
    // =====================================================================

    private function WriteSetpoint(string $parameter, string $ident, float $temperature): bool
    {
        // Gerät arbeitet in 0,5-°C-Schritten zwischen 5 und 40 °C.
        $t = round(min(40.0, max(5.0, $temperature)) * 2) / 2;
        if (!$this->WriteParameter($parameter, $t)) {
            return false;
        }
        $this->SetValue($ident, $t);
        $this->UpdateActiveSetpoint();
        return true;
    }

    private function WriteAndStore(string $parameter, $payload, string $ident, $value): bool
    {
        if (!$this->WriteParameter($parameter, $payload)) {
            return false;
        }
        $this->SetValue($ident, $value);
        return true;
    }

    private function WriteParameter(string $parameter, $value): bool
    {
        $r = $this->Request('POST', '/api/parameters', [$parameter => $value]);
        if ($r !== null && strtolower((string) ($r['status'] ?? '')) === 'success') {
            $this->SendDebug('Write', $parameter . ' = ' . json_encode($value), 0);
            return true;
        }
        $this->LogMessage(sprintf('Setzen von %s = %s fehlgeschlagen: %s', $parameter, json_encode($value), json_encode($r)), KL_ERROR);
        return false;
    }

    /** Parameter-Name so wählen, wie ihn das Gerät tatsächlich meldet (WiFi6 vs. WiFi7). */
    private function ParamName(string $primary, string $alt): string
    {
        $keys = json_decode($this->ReadAttributeString('ParamKeys'), true) ?: [];
        if (!in_array($primary, $keys, true) && in_array($alt, $keys, true)) {
            return $alt;
        }
        return $primary;
    }

    // Helligkeit: WiFi6 = 1..10 (x10 %), WiFi7 = 0..100 in 10er-Schritten. Symcon zeigt immer Prozent.
    private function BrightnessFromRaw(int $raw): int
    {
        return $this->ReadAttributeBoolean('IsWifi7') ? $raw : $raw * 10;
    }

    private function BrightnessToRaw(int $percent): int
    {
        $percent = min(100, max(0, $percent));
        if ($this->ReadAttributeBoolean('IsWifi7')) {
            return (int) (round($percent / 10) * 10);
        }
        return (int) max(1, round($percent / 10));
    }

    // =====================================================================
    // HTTP
    // =====================================================================

    /**
     * @return array|null Dekodierte JSON-Antwort oder null bei Fehler.
     */
    private function Request(string $method, string $path, ?array $body = null): ?array
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return null;
        }
        $host = preg_replace('#^https?://#i', '', rtrim($host, '/'));
        $url = sprintf('http://%s:%d%s', $host, $this->ReadPropertyInteger('Port'), $path);
        $timeout = max(2, $this->ReadPropertyInteger('Timeout'));
        $attempts = ($method === 'GET') ? 2 : 3;

        // Das Gerät ist klein – Anfragen seriell abarbeiten.
        $lock = 'HEATITWIFI_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 15000)) {
            $this->SendDebug('Request', 'Semaphore-Timeout', 0);
            return null;
        }

        try {
            for ($i = 1; $i <= $attempts; $i++) {
                $ch = curl_init($url);
                $opts = [
                    CURLOPT_CUSTOMREQUEST  => $method,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_TIMEOUT        => ($method === 'GET') ? $timeout : max($timeout, 10),
                    CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
                ];
                if ($body !== null) {
                    // 19.0 muss als "19.0" gesendet werden (WiFi7 prüft Typen strikt).
                    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_PRESERVE_ZERO_FRACTION);
                }
                curl_setopt_array($ch, $opts);

                $response = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                $this->SendDebug($method . ' ' . $path, sprintf('[%d] %s %s', $code, $body !== null ? json_encode($body) : '', (string) $response), 0);

                if ($response !== false && $code > 0 && $code < 500) {
                    $decoded = json_decode(trim((string) $response), true);
                    if (is_array($decoded)) {
                        if ($code >= 400) {
                            $this->LogMessage(sprintf('%s %s -> HTTP %d: %s', $method, $path, $code, $response), KL_WARNING);
                        }
                        return $decoded;
                    }
                    if ($code < 400) {
                        // Leerer Body bei Erfolg
                        return ['status' => 'Success'];
                    }
                    $this->LogMessage(sprintf('%s %s -> HTTP %d: %s', $method, $path, $code, $response), KL_WARNING);
                    return null;
                }

                $this->SendDebug('Request', sprintf('Versuch %d/%d fehlgeschlagen: %s (HTTP %d)', $i, $attempts, $err, $code), 0);
                if ($i < $attempts) {
                    IPS_Sleep(1000 * $i);
                }
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }

        $this->LogMessage(sprintf('%s %s nicht erreichbar', $method, $url), KL_WARNING);
        return null;
    }

    // =====================================================================
    // Profile
    // =====================================================================

    private function CreateProfiles(): void
    {
        $this->Profile('HEATITWIFI.Setpoint', VARIABLETYPE_FLOAT, ' °C', 5, 40, 0.5, 1, 'Temperature');
        $this->Profile('HEATITWIFI.Hysteresis', VARIABLETYPE_FLOAT, ' °C', 0.3, 3.0, 0.1, 1, 'Temperature');
        $this->Profile('HEATITWIFI.Power', VARIABLETYPE_INTEGER, ' W', 0, 0, 0, 0, 'Electricity');
        $this->Profile('HEATITWIFI.Energy', VARIABLETYPE_FLOAT, ' kWh', 0, 0, 0, 2, 'Electricity');
        $this->Profile('HEATITWIFI.Rssi', VARIABLETYPE_INTEGER, ' dBm', 0, 0, 0, 0, 'Intensity');
        $this->Profile('HEATITWIFI.Brightness', VARIABLETYPE_INTEGER, ' %', 0, 100, 10, 0, 'Intensity');

        $this->Profile('HEATITWIFI.Mode', VARIABLETYPE_INTEGER, '', 0, 3, 0, 0, 'Temperature', [
            [0, 'Aus', 'Power', -1],
            [1, 'Heizen', 'Flame', 0xFF8000],
            [2, 'Kühlen', 'Snowflake', 0x0080FF],
            [3, 'Eco', 'Leaf', 0x00C000],
        ]);
        $this->Profile('HEATITWIFI.State', VARIABLETYPE_INTEGER, '', 0, 4, 0, 0, 'Information', [
            [0, 'Leerlauf', '', -1],
            [1, 'Heizt', 'Flame', 0xFF8000],
            [2, 'Kühlt', 'Snowflake', 0x0080FF],
            [3, 'Relais offen', '', -1],
            [4, 'Relais geschlossen', '', 0x00C000],
        ]);
        $this->Profile('HEATITWIFI.SensorMode', VARIABLETYPE_INTEGER, '', 0, 7, 0, 0, 'Gear', [
            [0, 'Bodenfühler (F)', '', -1],
            [1, 'Interner Fühler (A)', '', -1],
            [2, 'Intern + Bodenbegrenzung (AF)', '', -1],
            [3, 'Externer Fühler (A2)', '', -1],
            [4, 'Extern + Bodenbegrenzung (A2F)', '', -1],
            [5, 'Leistungsregler (PWER)', '', -1],
            [7, 'Relais (nur WiFi7)', '', -1],
        ]);
        $this->Profile('HEATITWIFI.Regulation', VARIABLETYPE_INTEGER, '', 0, 1, 0, 0, 'Gear', [
            [0, 'Hysterese', '', -1],
            [1, 'PWM', '', -1],
        ]);
    }

    private function Profile(string $name, int $type, string $suffix, float $min, float $max, float $step, int $digits, string $icon, array $associations = []): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }
        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, '', $suffix);
        if ($type !== VARIABLETYPE_STRING) {
            IPS_SetVariableProfileValues($name, $min, $max, $step);
        }
        if ($type === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, $digits);
        }
        foreach ($associations as $a) {
            IPS_SetVariableProfileAssociation($name, $a[0], $a[1], $a[2], $a[3]);
        }
    }
}
