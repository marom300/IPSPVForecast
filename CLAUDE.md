# PV-Ertragsprognose (forecast.solar) – IP-Symcon Modul

## Projektüberblick

Veröffentlichungsreifes Community-Modul für IP-Symcon (≥ 7.0, PHP 8.x) das via [forecast.solar](https://forecast.solar) eine PV-Ertragsprognose abruft, in IPS-Variablen schreibt und für WebFront/IPSView visualisiert. Alles über die Modul-Konfiguration einstellbar – keine fest verdrahteten Anlagendaten.

## Architektur

```
/                                Repository-Root
  library.json                   Library-Definition (eindeutige GUID)
  README.md                      Installations- & Konfigurationsanleitung (DE/EN)
  LICENSE                        MIT
  CLAUDE.md                      dieses Dokument
  /PVForecastSolar               Modul-Ordner
    module.json                  Modul-Definition (eindeutige GUID, Type 3 = Gerät)
    module.php                   Hauptklasse PVForecastSolar : IPSModuleStrict
    form.json                    Konfigurationsformular
    locale.json                  Übersetzungen DE/EN
    README.md                    Modul-spezifisches README
    /imgs                        Icon/Screenshots (optional)
```

### GUIDs

- Library: `{5A4F8E7D-3C2B-4A19-8F6E-1D2C3B4A5968}`
- Modul:   `{7B6C5D4E-3F2A-4B19-9E8D-7C6B5A4F3E2D}`

### Klassenstruktur `PVForecastSolar`

- `Create()` – Properties, Buffer, Profile, Timer registrieren
- `Destroy()` – nichts spezifisches nötig
- `ApplyChanges()` – Variablen anlegen, Timer-Intervall setzen, Sub-Variablen je Fläche pflegen
- `GetConfigurationForm()` – form.json dynamisch (Korrekturfaktor-Anzeige, Rate-Limit-Warnung)
- `RequestAction($Ident, $Value)` – Buttons („Jetzt aktualisieren", „Verbindung testen")
- `UpdateForecast()` – Timer-Callback (öffentlich), Hauptabruf
- Hilfsmethoden (private/protected):
  - `fetchRoof($lat, $lon, $dec, $az, $kwp, $dampMorning, $dampEvening, $apiKey)` – HTTP-Request
  - `parseResult($json)` – Tagessummen + Profile extrahieren
  - `sumRoofs($results)` – Summenbildung
  - `calcCorrection()` – Korrekturfaktor aus Ist/Prognose
  - `renderHTML($data)` – HTML-Visualisierung
  - `writeVariables($data)` – Variablen schreiben
  - `setStatusVar($state)` – Statusvariable + Log
  - `getCachedResult()` / `setCachedResult($data)` – Buffer

## forecast.solar – API-Eckdaten

- Public-Tier (ohne Key): `GET https://api.forecast.solar/estimate/{lat}/{lon}/{dec}/{az}/{kwp}`
- Personal-Tier (Key): `GET https://api.forecast.solar/{apikey}/estimate/{lat}/{lon}/{dec}/{az}/{kwp}`
- Azimut: `0 = Süd, -90 = Ost, +90 = West, ±180 = Nord` (URL-kodieren!)
- Rate-Limit Public: ~12 Anfragen/Stunde/IP → JSON-Feld `message.ratelimit { limit, remaining, period }` auswerten
- Antwort `result`:
  - `watts` – Leistung W je Timestamp
  - `watt_hours_period` – Energie Wh je Periode
  - `watt_hours` – kumuliert über Tag (Wh)
  - `watt_hours_day` – Tagessumme je Datum (Wh) – Wh → kWh / 1000
- `damping` Parameter: optional Morgen/Abend-Dämpfung gegen Verschattung

## Properties (Modul-Konfiguration)

| Name | Typ | Default | Bedeutung |
|---|---|---|---|
| `Latitude` | float | 48.0 | Breitengrad |
| `Longitude` | float | 16.0 | Längengrad |
| `Roofs` | list (json) | [] | Liste der Dachflächen |
| `UpdateInterval` | int | 60 | Minuten |
| `ApiKey` | string | "" | optional |
| `PerRoofVariables` | bool | false | Variablen je Fläche anlegen |
| `CalibrationActive` | bool | false | Selbstkalibrierung an |
| `ActualYieldVariableID` | int | 0 | Variable mit realem Tagesertrag (kWh) |
| `CalibrationWindowDays` | int | 10 | Tage für Korrekturfaktor-Berechnung |
| `WriteForecastCurve` | bool | false | Prognose-Kurve (kWh/h) ins Archiv schreiben (für Charts) |

Buffer (`SetBuffer`):
- `LastResult` – letztes vollständiges Ergebnis (JSON)
- `LastRatelimit` – `{limit, remaining, period, ts}`
- `History` – Ringpuffer der letzten Tagessummen (für Kalibrierung)

## Erzeugte IPS-Variablen (Idents)

Summen (immer):
- `Today` (kWh, geloggt)
- `Tomorrow` (kWh, geloggt)
- `DayAfter` (kWh, geloggt)
- `PowerNow` (W)
- `RemainingToday` (kWh)
- `LastUpdate` (String/Unix)
- `Status` (Int + Profil OK/Error/Ratelimit)
- `Visualization` (~HTMLBox)
- `Correction` (Float, optional)
- `ForecastEnergy` (kWh/h, geloggt, optional) – Prognose-Kurve mit Zukunfts-Zeitstempeln via `AC_AddLoggedValues` für Diagramme

Je Fläche (wenn aktiv): `Roof{Index}Today`, `Roof{Index}Tomorrow`

## Profile

- `PV.kWh` – Float, Suffix " kWh", 2 Nachkommastellen
- `PV.W` – Float, Suffix " W", 0 Nachkommastellen
- `PV.Status` – Integer, Assoziationen: 0 OK, 1 Fehler, 2 Rate-Limit

## Offene Punkte / TODO

- [x] Repo-Scaffold (Phase 1)
- [x] API + Summenvariablen (Phase 2)
- [x] Konfigurationsformular + Robustheit (Phase 3)
- [x] Per-Fläche-Variablen + Selbstkalibrierung (Phase 4)
- [x] HTML-Visualisierung + finale Doku (Phase 5)
- [ ] Screenshots ergänzen (User-Aufgabe nach erster Installation)

## Stolpersteine

- forecast.solar liefert Wh – im Modul nach kWh umrechnen
- Nord-Flächen / steile Winkel → kleine Werte sind OK, kein Fehler
- `watt_hours_day` enthält je nach Tier nur heute+morgen → übermorgen defensiv
- Negative Azimute URL-kodieren (`urlencode`)
- Bei `remaining <= 0` Abruf überspringen + Log-Warnung
- Anzahl Flächen × (60 / Intervall) ≤ 12 → sonst Warnung im Formular
