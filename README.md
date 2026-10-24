# Symcon-Modul: Heatit WiFi6 / WiFi7 Thermostat

Liest und steuert ein Heatit WiFi6- oder WiFi7-Thermostat über dessen **lokale HTTP-API** (kein Cloud-Konto, keine Authentifizierung).

## Installation

1. Dieses Repository in der Symcon-Verwaltungskonsole unter *Kern Instanzen → Module Control* per URL hinzufügen
   (oder den Ordner `SymconHeatIt` nach `<Symcon>/modules/` kopieren bzw. verlinken) und das Modul-Control neu laden.
2. Instanz **HeatIt WiFi Thermostat** anlegen.
3. IP-Adresse des Thermostats eintragen (am besten per DHCP-Reservierung fest vergeben).

## Variablen

| Variable | Beschreibung | Schreibbar |
|---|---|---|
| Betriebsmodus | Aus / Heizen / Kühlen / Eco | ja |
| Solltemperatur (aktiver Modus) | Sollwert des gerade aktiven Modus | ja |
| Solltemperatur Heizen / Kühlen / Eco | einzelne Sollwerte (5–40 °C, 0,5-Schritte) | ja |
| Ist-Temperatur | passend zum Fühlermodus (intern / Boden / extern) | – |
| Status | Leerlauf / Heizt / Kühlt (Relais offen/geschlossen) | – |
| Leistung, Energieverbrauch | W / kWh | – |
| Fenster-offen erkannt | | – |
| WLAN-Signal, Firmware | | – |
| Relais | wird automatisch angelegt, wenn WiFi7 im Relais-Modus läuft | ja |
| *optional:* Boden-/Externe Temperatur | in der Instanz aktivierbar | – |
| *optional (Erweitert):* Kindersicherung, Fenster-offen-Erkennung, Display, Helligkeit, Fühlermodus, Regelart, Hysterese | | ja |

## Funktionen (PHP)

```php
HEATITWIFI_Update($id);
HEATITWIFI_SetOperatingMode($id, 1);          // 0 Aus, 1 Heizen, 2 Kühlen, 3 Eco
HEATITWIFI_SetTargetTemperature($id, 21.5);   // aktiver Modus
HEATITWIFI_SetHeatingSetpoint($id, 21.5);
HEATITWIFI_SetCoolingSetpoint($id, 24.0);
HEATITWIFI_SetEcoSetpoint($id, 18.0);
HEATITWIFI_SetRelay($id, true);               // nur WiFi7 im Relais-Modus
HEATITWIFI_SetParameter($id, 'internalCalibration', '-1.5');   // beliebiger API-Parameter, Wert als JSON
HEATITWIFI_ResetEnergy($id);
echo HEATITWIFI_GetStatusJSON($id);           // Roh-Status zur Diagnose
```

## Hinweise

* API-Grundlage: Heatit-OpenAPI (WiFi6, v7.0) sowie die beobachteten Abweichungen der WiFi7-Firmware 0.1.13
  (u. a. `model`-Feld, Helligkeit 0–100, `displayInformation`, strikte Typen, Relais-Modus).
* Das Gerät meldet nach einem Schreibzugriff kurzzeitig den alten Wert. Das Modul übernimmt deshalb den geschriebenen Wert sofort
  und lässt den nächsten Abfragezyklus bestätigen.
* Fühler mit exakt 100,0 °C gelten als nicht angeschlossen und werden ignoriert.
