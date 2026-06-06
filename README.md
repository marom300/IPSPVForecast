# PV-Ertragsprognose (forecast.solar) für IP-Symcon

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-%E2%89%A5%207.0-blue.svg)](https://www.symcon.de)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C.svg?logo=paypal)](https://paypal.me/marom300)

> *Deutsche Beschreibung unten · English description further down.*

Ein Community-Modul für **IP-Symcon ≥ 7.0**, das über die kostenlose [forecast.solar](https://forecast.solar)-API eine PV-Ertragsprognose für eine oder mehrere Dachflächen abruft, in IPS-Variablen schreibt und für WebFront/IPSView visualisiert.

![Module screenshot placeholder](PVForecastSolar/imgs/screenshot.png)

> ☕ **Gefällt dir das Modul?** Wenn es dir Strom (oder Nerven) spart, freue ich mich über eine kleine Spende: **[paypal.me/marom300](https://paypal.me/marom300)** — danke! 🙏

---

## 🇩🇪 Deutsche Anleitung

### Funktionen

- Abruf von [forecast.solar](https://doc.forecast.solar/api) für beliebig viele Dachflächen
- Pro Fläche ein HTTP-Request, anschließend automatische Summenbildung
- Geloggte Variablen für **Prognose heute / morgen / übermorgen**, erwartete Leistung jetzt, Resttagesertrag, Status, letzter Abruf
- Optional **Variablen je Fläche**
- Optionale **Selbstkalibrierung**: Korrekturfaktor aus realem Ist-Ertrag vs. Prognose der letzten N Tage (begrenzt auf 0,5 – 1,5)
- **HTML-Visualisierung** (`~HTMLBox`) – dunkles Theme, responsiv, IPSView- und WebFront-tauglich
- Vollständige Auswertung des **Rate-Limits** der Public-API (≈ 12 Anfragen/Stunde/IP)
- Robuste Fehlerbehandlung: bei API-/Netzwerkfehlern bleiben die zuletzt gültigen Werte erhalten
- Komplett konfigurierbar – **keine fest verdrahteten Anlagendaten**

### Installation

1. In der IPS-Konsole → **Modul-Store** → **Module verwalten** → **Hinzufügen** → Repository-URL eintragen:
   ```
   https://github.com/your-user/IPS-PVForecastSolar
   ```
2. Modul „PVForecastSolar" installieren.
3. Neue Instanz vom Typ **PV-Ertragsprognose (forecast.solar)** anlegen.
4. Standort und Dachflächen konfigurieren (siehe unten).
5. Button **Verbindung testen** drücken – bei OK ist alles bereit.
6. Modul aktualisiert automatisch im konfigurierten Intervall (Standard 60 Min). Manuell über **Jetzt aktualisieren**.

### Konfiguration

#### Standort
- **Breitengrad / Längengrad** – z. B. 48.4330 / 16.4830 (Hautzendorf, AT). forecast.solar leitet die Zeitzone aus den Koordinaten ab.

#### Dachflächen
Liste mit beliebig vielen Einträgen, je Zeile:

| Spalte | Bedeutung |
|---|---|
| **Bezeichnung** | freier Name, z. B. „Satteldach Süd" |
| **kWp** | installierte Peak-Leistung dieser Fläche, Dezimalpunkt |
| **Neigung (°)** | 0 = waagrecht, 90 = senkrecht |
| **Azimut (°)** | **0 = Süd, -90 = Ost, +90 = West, ±180 = Nord** |
| **Dämpfung morgens / abends** | optional 0…1 (für Horizontverschattung) |
| **Aktiv** | nur aktive Flächen werden abgerufen |

#### Abruf
- **Update-Intervall** – Standard 60 Min. Achtung: Public-Tier max. ~12 Anfragen/Stunde/IP. Bei 3 Flächen und 60 min Intervall ergibt das 3 Anfragen/Stunde → OK.
- **API-Schlüssel** – optional, für Personal/Plus-Tier (höheres Limit, feinere Auflösung).
- **Variablen je Fläche anlegen** – wenn aktiv, werden zusätzlich `Heute`/`Morgen`-Variablen pro Fläche erzeugt.

#### Selbstkalibrierung (optional)
- **Aktivieren** + **Variable mit realem Tagesertrag (kWh)** auswählen (z. B. die Ist-Energievariable Ihres Wechselrichters).
- **Zeitfenster (Tage)** – Default 10. Modul berechnet `Σ Ist / Σ Prognose` der letzten abgeschlossenen Tage, plausibilisiert auf 0,5 – 1,5 und wendet den Faktor auf zukünftige Prognosen an.

### Beispielkonfiguration (Mehrflächen-Anlage)

| Bezeichnung | kWp | Neigung | Azimut | Aktiv |
|---|---|---|---|---|
| Satteldach Süd | 11.205 | 22 | 0 | ✓ |
| Flachdach Ost | 3.735 | 12 | -90 | ✓ |

> Hinweis: „Satteldach Süd" fasst hier zwei Strings (14 + 13 Module à 415 W) zu einer Fläche zusammen, da beide identische Neigung/Ausrichtung haben (27 × 415 W = 11.205 kWp).
> „Flachdach Ost" = String 3 (9 × 415 W = 3.735 kWp).
> Gesamt 36 Module / 14,94 kWp.
>
> Standort-Beispiel: Hautzendorf (AT) ≈ 48.433 N / 16.483 O.

### Rate-Limit beachten

Public-Tier: **~12 Anfragen/Stunde/IP**. Die Faustregel:

```
Anzahl aktiver Flächen × (60 / Update-Intervall in min)  ≤  12
```

- 1 Fläche, 5 min  → 12 calls/h → grenzwertig
- 3 Flächen, 60 min → 3 calls/h → entspannt
- 4 Flächen, 30 min → 8 calls/h → OK

Das Formular zeigt eine Warnung, wenn die Konfiguration das Limit sprengt. Das Modul wertet die `message.ratelimit`-Antwort aus und überspringt Abrufe bei `remaining = 0`.

### Erzeugte Variablen

| Ident | Typ | Beschreibung |
|---|---|---|
| `Today` | Float (kWh, geloggt) | Prognose heute (Summe aller Flächen) |
| `Tomorrow` | Float (kWh, geloggt) | Prognose morgen |
| `DayAfter` | Float (kWh, geloggt) | Prognose übermorgen (falls API liefert) |
| `PowerNow` | Float (W) | Erwartete Leistung jetzt |
| `RemainingToday` | Float (kWh) | Resttagesertrag bis Sonnenuntergang |
| `LastUpdate` | String | Zeitstempel des letzten Abrufs |
| `Status` | Integer | 0 OK, 1 Fehler, 2 Rate-Limit |
| `Visualization` | String (~HTMLBox) | HTML-Visualisierung für WebFront/IPSView |
| `Correction` | Float | Aktiver Korrekturfaktor (wenn Selbstkalibrierung an) |
| `Roof{n}Today/Tomorrow` | Float (kWh) | optional, je Fläche |

### Stolpersteine

- Nord-Flächen oder steile Winkel liefern sehr kleine / 0-Werte → das ist korrekt, kein Fehler.
- `watt_hours_day` enthält je nach Tier nur heute + morgen → übermorgen ist defensiv 0, wenn nicht vorhanden.
- forecast.solar liefert **Wh**; das Modul rechnet automatisch in kWh.

---

## 🇬🇧 English

### Features

- Fetches PV yield forecasts from [forecast.solar](https://doc.forecast.solar/api) for any number of roof surfaces
- One HTTP request per surface, summed automatically
- Logged variables for **today / tomorrow / day-after**, expected power now, remaining today, status, last update
- Optional **per-roof variables**
- Optional **self-calibration**: correction factor from actual vs. forecast over the last N days (clamped to 0.5 – 1.5)
- **HTML visualization** (`~HTMLBox`) – dark theme, responsive, IPSView- and WebFront-ready
- Full handling of the public-tier **rate limit** (~12 req/h/IP)
- Robust error handling: last valid values are kept on API failure
- Fully configurable – **no hard-coded plant data**

### Installation

1. In the IPS console → **Module Store** → **Manage Modules** → **Add** the repo URL.
2. Install **PVForecastSolar**.
3. Create a new instance of type **PV forecast (forecast.solar)**.
4. Configure location and roof surfaces.
5. Click **Test connection** – if OK you are done.
6. The module updates automatically (default 60 min) and can be triggered manually with **Update now**.

### Configuration

- **Location**: latitude & longitude (decimal degrees).
- **Roof surfaces**: list — name, kWp, tilt (0 = flat, 90 = vertical), azimuth (**0 = south, -90 = east, +90 = west, ±180 = north**), optional damping, active flag.
- **Fetch**: interval, optional API key, per-roof variables toggle.
- **Self-calibration**: enable, select the variable holding the actual daily yield (kWh), choose the window in days.

### Example configuration

| Name | kWp | Tilt | Azimuth | Active |
|---|---|---|---|---|
| Gabled roof south | 11.205 | 22° | 0° | ✓ |
| Flat roof east | 3.735 | 12° | -90° | ✓ |

Example location: Hautzendorf (AT) ≈ 48.433 N / 16.483 E.

### Rate limit

Public tier ≈ **12 requests per hour per IP**. Rule of thumb:

```
active roofs × (60 / interval_minutes)  ≤  12
```

The configuration form warns if the planned load exceeds the limit. The module also reads the API rate-limit response and skips when exhausted.

### Produced variables

| Ident | Type | Description |
|---|---|---|
| `Today` | float (kWh, logged) | forecast today (sum) |
| `Tomorrow` | float (kWh, logged) | forecast tomorrow |
| `DayAfter` | float (kWh, logged) | forecast day after (if returned) |
| `PowerNow` | float (W) | expected power now |
| `RemainingToday` | float (kWh) | remaining today |
| `LastUpdate` | string | last update timestamp |
| `Status` | int | 0 OK, 1 error, 2 rate-limit |
| `Visualization` | string (~HTMLBox) | HTML for WebFront/IPSView |
| `Correction` | float | active correction factor (if calibration is on) |
| `Roof{n}Today/Tomorrow` | float (kWh) | optional per-roof variables |

### Support

If this module saves you some electricity (or nerves), a small donation is much appreciated: **[paypal.me/marom300](https://paypal.me/marom300)** — thank you! 🙏

### License

MIT – see [LICENSE](LICENSE).
