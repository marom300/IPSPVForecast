# PVForecastSolar

IP-Symcon instance module wrapping the [forecast.solar](https://forecast.solar) public API.

See the [repository README](../README.md) for installation, full configuration and a multi-roof example.

## Quick start

1. Add an instance of **PV-Ertragsprognose (forecast.solar)** in IP-Symcon.
2. Enter latitude / longitude.
3. Add at least one roof (name, kWp, tilt, azimuth, active = on).
4. Click **Test connection**, then **Update now**.

## Variables produced

| Ident | Description |
|---|---|
| `Today`, `Tomorrow`, `DayAfter` | daily forecasts (kWh, logged) |
| `PowerNow` | expected power (W) |
| `RemainingToday` | remaining today (kWh) |
| `Visualization` | HTML box (dark theme, responsive) |
| `Status` | 0 OK / 1 Error / 2 Rate-limit |
| `Correction` | active correction factor (calibration) |

## Azimuth convention

`0 = South, -90 = East, +90 = West, ±180 = North` (the forecast.solar convention).

## Rate limit

Public tier ≈ 12 requests/hour/IP. Active roofs × (60/interval_min) must stay ≤ 12.
The form warns if the configuration would exceed it, the module also reads the API rate-limit response and skips when exhausted.
