<?php

declare(strict_types=1);

/**
 * PV-Ertragsprognose (forecast.solar)
 *
 * IP-Symcon module that fetches PV yield forecasts from forecast.solar
 * for one or more roof surfaces, writes them into IPS variables and
 * renders an HTML visualization.
 *
 * Free Public-Tier: max ~12 requests per hour per IP. The module
 * evaluates the API rate-limit headers and skips updates when exhausted.
 *
 * @see https://doc.forecast.solar/api
 */
class PVForecastSolar extends IPSModuleStrict
{
    private const STATUS_OK = 0;
    private const STATUS_ERROR = 1;
    private const STATUS_RATELIMIT = 2;

    private const API_BASE = 'https://api.forecast.solar';

    /**
     * Wrapper um Translate(), der bei kaputter locale.json (Translate -> false)
     * defensiv den englischen Ausgangstext zurückgibt. Schützt sprintf()-Aufrufe.
     */
    private function T(string $s): string
    {
        $t = $this->Translate($s);
        return is_string($t) ? $t : $s;
    }

    public function Create(): void
    {
        parent::Create();

        // Standort
        $this->RegisterPropertyFloat('Latitude', 48.0);
        $this->RegisterPropertyFloat('Longitude', 16.0);

        // Dachflächen-Liste (JSON-Array von Objekten)
        $this->RegisterPropertyString('Roofs', '[]');

        // Abruf
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyString('ApiKey', '');
        $this->RegisterPropertyBoolean('PerRoofVariables', false);

        // Selbstkalibrierung
        $this->RegisterPropertyBoolean('CalibrationActive', false);
        $this->RegisterPropertyInteger('ActualYieldVariableID', 0);
        $this->RegisterPropertyInteger('CalibrationWindowDays', 10);

        // Reale PV-Leistung (W) für das Overlay-Diagramm (Prognose vs. Ist)
        $this->RegisterPropertyInteger('ActualPowerVariableID', 0);

        // Buffer NICHT in Create() leeren! Create() läuft bei jedem Modul-Reload
        // (z. B. nach Update aus dem Store), das würde den Cache jedes Mal wegwerfen.
        // GetBuffer liefert für nie gesetzte Buffer ohnehin '' zurück – wir behandeln
        // das in getCachedResult() / updateCalibration() / GetConfigurationForm() defensiv.

        // Timer (Callback: public method UpdateForecast)
        $this->RegisterTimer('UpdateTimer', 0, 'PVF_UpdateForecast($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->registerProfiles();
        $this->registerSummaryVariables();
        $this->registerPerRoofVariables();

        // Korrekturfaktor-Variable nur wenn Kalibrierung aktiv
        if ($this->ReadPropertyBoolean('CalibrationActive')) {
            $this->RegisterVariableFloat('Correction', $this->Translate('Correction factor'), 'PVF.Factor', 90);
        } elseif (@$this->GetIDForIdent('Correction')) {
            $this->UnregisterVariable('Correction');
        }

        // Hinweis: Eine Prognose-Kurve im NATIVEN IPS-Archiv ist nicht möglich –
        // AC_AddLoggedValues akzeptiert keine Zukunfts-Zeitstempel. Stattdessen
        // wird die Prognose-vs-Ist-Kurve direkt in der HTML-Box gezeichnet
        // (siehe ActualPowerVariableID / renderOverlayChart). Altvariable aufräumen:
        if (@$this->GetIDForIdent('ForecastEnergy')) {
            $this->UnregisterVariable('ForecastEnergy');
        }

        // Timer-Intervall
        $intervalMin = max(1, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('UpdateTimer', $intervalMin * 60 * 1000);

        // Initialer Status
        $this->SetStatus(102); // 102 = aktiv
    }

    public function GetConfigurationForm(): string
    {
        $formPath = __DIR__ . '/form.json';
        $form = json_decode(file_get_contents($formPath), true);

        // Korrekturfaktor anzeigen (read-only Label)
        $correction = (float) $this->GetBuffer('Correction');
        if ($correction <= 0) {
            $correction = 1.0;
        }
        $form['elements'][] = [
            'type'    => 'Label',
            'caption' => sprintf($this->T('Current correction factor: %.3f'), $correction),
        ];

        // Rate-Limit-Warnung: aktive Flächen × Aufrufe pro Stunde
        $roofs = $this->getActiveRoofs();
        $intervalMin = max(1, $this->ReadPropertyInteger('UpdateInterval'));
        $callsPerHour = (60.0 / $intervalMin) * max(1, count($roofs));
        if ($this->ReadPropertyString('ApiKey') === '' && $callsPerHour > 12) {
            $form['elements'][] = [
                'type'    => 'Label',
                'caption' => sprintf(
                    $this->T('WARNING: %.1f requests/hour exceed the public-tier limit of 12. Increase interval or use an API key.'),
                    $callsPerHour
                ),
            ];
        }

        return json_encode($form);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'UpdateNow':
                $this->UpdateForecast();
                // Feedback ans Formular geben, sonst weiß der Nutzer nicht, was passiert ist
                $status = (int) @GetValue($this->GetIDForIdent('Status'));
                $cachedAvailable = $this->getCachedResult() !== null;
                switch ($status) {
                    case self::STATUS_OK:
                        echo $this->T('Update OK – variables refreshed.');
                        break;
                    case self::STATUS_RATELIMIT:
                        $rl = json_decode($this->GetBuffer('LastRatelimit'), true) ?: [];
                        $age = time() - (int) ($rl['ts'] ?? time());
                        $reset = max(0, (int) ($rl['period'] ?? 3600) - $age);
                        echo sprintf(
                            $this->T('Rate-limit exhausted. Resets in %d s. Cache available: %s.'),
                            $reset, $cachedAvailable ? $this->T('yes') : $this->T('no')
                        );
                        break;
                    case self::STATUS_ERROR:
                    default:
                        echo sprintf(
                            $this->T("Update failed – see IPS messages log for details. Cache available: %s."),
                            $cachedAvailable ? $this->T('yes') : $this->T('no')
                        );
                        break;
                }
                break;
            case 'TestConnection':
                $this->testConnection();
                break;
            case 'RefreshVisualization':
                $this->refreshVisualizationFromCache();
                break;
            default:
                throw new Exception("Invalid Ident: $Ident");
        }
    }

    /**
     * Rendert das HTML neu (ohne API-Call).
     * Primär aus dem Buffer-Cache (enthält auch das Stundenprofil);
     * fällt der Buffer weg (z. B. direkt nach Modul-Update), wird aus den
     * aktuellen Variablenwerten rekonstruiert – dann fehlt nur das
     * Stundenprofil, die Tagesbalken sind aber da.
     */
    private function refreshVisualizationFromCache(): void
    {
        $cached = $this->getCachedResult();
        if ($cached === null) {
            $cached = $this->buildTotalsFromVariables();
        }
        if ($cached === null) {
            echo $this->T('No data available yet. Run "Update now" once first.');
            return;
        }
        $html = $this->renderHTML($cached, true);
        $this->SetValue('Visualization', $html);
        echo $this->T('Visualization re-rendered.');
    }

    /**
     * Rekonstruiert ein $totals-Array aus den aktuell gespeicherten
     * Variablenwerten (Fallback, wenn der Buffer-Cache leer ist).
     * Liefert null, wenn die Variablen noch nicht existieren.
     */
    private function buildTotalsFromVariables(): ?array
    {
        $idToday = @$this->GetIDForIdent('Today');
        if (!$idToday) {
            return null;
        }
        return [
            'Today'          => (float) GetValue($idToday),
            'Tomorrow'       => (float) @GetValue($this->GetIDForIdent('Tomorrow')),
            'DayAfter'       => (float) @GetValue($this->GetIDForIdent('DayAfter')),
            'PowerNow'       => (float) @GetValue($this->GetIDForIdent('PowerNow')),
            'RemainingToday' => (float) @GetValue($this->GetIDForIdent('RemainingToday')),
            'HourlySum'      => [], // im Variablen-Fallback nicht verfügbar
        ];
    }

    /**
     * Timer/Button-Callback. Holt für jede aktive Fläche die Prognose,
     * summiert, schreibt Variablen und rendert HTML.
     */
    public function UpdateForecast(): void
    {
        $roofs = $this->getActiveRoofs();
        if (count($roofs) === 0) {
            $this->setStatusVar(self::STATUS_ERROR);
            $this->LogMessage($this->Translate('No active roofs configured.'), KL_WARNING);
            return;
        }

        // Rate-Limit-Check (Buffer aus letztem Lauf)
        $ratelimit = json_decode($this->GetBuffer('LastRatelimit'), true);
        if (is_array($ratelimit) && isset($ratelimit['remaining'], $ratelimit['ts'])) {
            $age = time() - (int) $ratelimit['ts'];
            $period = (int) ($ratelimit['period'] ?? 3600);
            if ($ratelimit['remaining'] <= 0 && $age < $period) {
                $this->setStatusVar(self::STATUS_RATELIMIT);
                $this->LogMessage(sprintf(
                    $this->T('Rate-limit exhausted (%d remaining), skipping update. Resets in %ds.'),
                    (int) $ratelimit['remaining'], $period - $age
                ), KL_WARNING);
                return;
            }
        }

        $lat = $this->ReadPropertyFloat('Latitude');
        $lon = $this->ReadPropertyFloat('Longitude');
        $apiKey = trim($this->ReadPropertyString('ApiKey'));

        $perRoofResults = [];
        foreach ($roofs as $idx => $roof) {
            try {
                $json = $this->fetchRoof(
                    $lat, $lon,
                    (float) $roof['Declination'],
                    (float) $roof['Azimuth'],
                    (float) $roof['kWp'],
                    (float) ($roof['DampMorning'] ?? 0),
                    (float) ($roof['DampEvening'] ?? 0),
                    $apiKey
                );
                $perRoofResults[$idx] = $this->parseResult($json);
                $perRoofResults[$idx]['Name'] = $roof['Name'] ?? ('Roof ' . ($idx + 1));
            } catch (Throwable $e) {
                // Eine 429-Antwort (oder remaining<=0 im Buffer) ist KEIN echter
                // Fehler, sondern Rate-Limit – das sauseinanderhalten.
                $rl = json_decode($this->GetBuffer('LastRatelimit'), true);
                $isRateLimit = (strpos($e->getMessage(), '429') !== false)
                    || (is_array($rl) && (int) ($rl['remaining'] ?? 1) <= 0);

                $this->setStatusVar($isRateLimit ? self::STATUS_RATELIMIT : self::STATUS_ERROR);
                $this->LogMessage(sprintf(
                    $this->T('Fetch failed for roof "%s": %s'),
                    (string) ($roof['Name'] ?? '?'), $e->getMessage()
                ), $isRateLimit ? KL_WARNING : KL_ERROR);
                // Letzten Cache behalten
                $cached = $this->getCachedResult();
                if ($cached !== null) {
                    $this->renderAndWrite($cached, true);
                }
                return;
            }
        }

        $totals = $this->sumRoofs($perRoofResults);
        $totals['PerRoof'] = $perRoofResults;
        $totals['Latitude'] = $lat;
        $totals['Longitude'] = $lon;

        // Korrekturfaktor
        if ($this->ReadPropertyBoolean('CalibrationActive')) {
            $this->updateCalibration($totals['Today']);
            $factor = (float) $this->GetBuffer('Correction');
            if ($factor > 0) {
                $totals['Today'] *= $factor;
                $totals['Tomorrow'] *= $factor;
                $totals['DayAfter'] *= $factor;
                $totals['RemainingToday'] *= $factor;
                $totals['Correction'] = $factor;
                // Faktor auch auf Profile/Kurve anwenden, damit alles konsistent ist
                foreach ($totals['HourlySum'] as $ts => $wh) {
                    $totals['HourlySum'][$ts] = $wh * $factor;
                }
                foreach ($totals['PeriodsSum'] as $ts => $wh) {
                    $totals['PeriodsSum'][$ts] = $wh * $factor;
                }
            }
        }

        $this->setCachedResult($totals);
        $this->renderAndWrite($totals, false);
        $this->setStatusVar(self::STATUS_OK);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function getActiveRoofs(): array
    {
        $list = json_decode($this->ReadPropertyString('Roofs'), true);
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_filter($list, fn($r) => !empty($r['Active'])));
    }

    /**
     * Führt einen GET gegen forecast.solar aus und liefert das dekodierte JSON.
     *
     * @throws Exception bei HTTP-/JSON-Fehlern
     */
    private function fetchRoof(
        float $lat, float $lon, float $dec, float $az, float $kwp,
        float $dampMorning, float $dampEvening, string $apiKey
    ): array {
        $path = sprintf(
            '/estimate/%s/%s/%s/%s/%s',
            rawurlencode($this->fmt($lat)),
            rawurlencode($this->fmt($lon)),
            rawurlencode($this->fmt($dec)),
            rawurlencode($this->fmt($az)),
            rawurlencode($this->fmt($kwp))
        );
        $url = self::API_BASE . ($apiKey !== '' ? '/' . rawurlencode($apiKey) : '') . $path;

        $query = [];
        if ($dampMorning > 0) {
            $query['damping_morning'] = $this->fmt($dampMorning);
        }
        if ($dampEvening > 0) {
            $query['damping_evening'] = $this->fmt($dampEvening);
        }
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'IPS-PVForecastSolar/1.0',
        ]);

        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('cURL error: ' . $err);
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new Exception('Invalid JSON response (HTTP ' . $http . ')');
        }

        // Rate-Limit aus message.ratelimit speichern (für alle Flächen identisch)
        if (isset($json['message']['ratelimit']) && is_array($json['message']['ratelimit'])) {
            $rl = $json['message']['ratelimit'];
            $this->SetBuffer('LastRatelimit', json_encode([
                'limit'     => (int) ($rl['limit'] ?? 0),
                'remaining' => (int) ($rl['remaining'] ?? 0),
                'period'    => (int) ($rl['period'] ?? 3600),
                'ts'        => time(),
            ]));
        }

        if ($http >= 400) {
            $code = $json['message']['code'] ?? $http;
            $text = $json['message']['text'] ?? ('HTTP ' . $http);
            throw new Exception(sprintf('API error %s: %s', (string) $code, (string) $text));
        }

        if (!isset($json['result']) || !is_array($json['result'])) {
            throw new Exception('Response misses result field');
        }

        return $json;
    }

    /**
     * Extrahiert Tagessummen und Profile aus der forecast.solar-Antwort.
     */
    private function parseResult(array $json): array
    {
        $result = $json['result'];
        $wattHoursDay = $result['watt_hours_day'] ?? [];

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $dayAfter = date('Y-m-d', strtotime('+2 days'));

        $todayWh = (float) ($wattHoursDay[$today] ?? 0);
        $tomorrowWh = (float) ($wattHoursDay[$tomorrow] ?? 0);
        $dayAfterWh = (float) ($wattHoursDay[$dayAfter] ?? 0);

        // Erwartete Leistung jetzt: nächste Timestamp aus `watts`
        $powerNow = 0.0;
        $remainingTodayWh = 0.0;
        if (isset($result['watts']) && is_array($result['watts'])) {
            $now = time();
            foreach ($result['watts'] as $ts => $w) {
                $t = strtotime((string) $ts);
                if ($t !== false && abs($t - $now) <= 1800) {
                    $powerNow = (float) $w;
                    break;
                }
            }
        }
        if (isset($result['watt_hours_period']) && is_array($result['watt_hours_period'])) {
            $now = time();
            foreach ($result['watt_hours_period'] as $ts => $wh) {
                $t = strtotime((string) $ts);
                if ($t !== false && $t >= $now && date('Y-m-d', $t) === $today) {
                    $remainingTodayWh += (float) $wh;
                }
            }
        }

        // Stündliches Profil heute (für die HTML-Visualisierung)
        // + volle Periodenreihe (heute+morgen+übermorgen, für die Archiv-Kurve)
        $hourly = [];
        $periods = [];
        if (isset($result['watt_hours_period']) && is_array($result['watt_hours_period'])) {
            foreach ($result['watt_hours_period'] as $ts => $wh) {
                $periods[(string) $ts] = (float) $wh;
                if (str_starts_with((string) $ts, $today)) {
                    $hourly[(string) $ts] = (float) $wh;
                }
            }
        }

        return [
            'Today'          => $todayWh / 1000.0,
            'Tomorrow'       => $tomorrowWh / 1000.0,
            'DayAfter'       => $dayAfterWh / 1000.0,
            'PowerNow'       => $powerNow,
            'RemainingToday' => $remainingTodayWh / 1000.0,
            'Hourly'         => $hourly,
            'Periods'        => $periods,
        ];
    }

    /**
     * Summiert beliebig viele Flächenergebnisse zu einem Gesamtergebnis.
     */
    private function sumRoofs(array $perRoofResults): array
    {
        $sum = [
            'Today'          => 0.0,
            'Tomorrow'       => 0.0,
            'DayAfter'       => 0.0,
            'PowerNow'       => 0.0,
            'RemainingToday' => 0.0,
            'HourlySum'      => [],
            'PeriodsSum'     => [],
        ];
        foreach ($perRoofResults as $r) {
            $sum['Today']          += (float) $r['Today'];
            $sum['Tomorrow']       += (float) $r['Tomorrow'];
            $sum['DayAfter']       += (float) $r['DayAfter'];
            $sum['PowerNow']       += (float) $r['PowerNow'];
            $sum['RemainingToday'] += (float) $r['RemainingToday'];
            foreach (($r['Hourly'] ?? []) as $ts => $wh) {
                $sum['HourlySum'][$ts] = ($sum['HourlySum'][$ts] ?? 0.0) + (float) $wh;
            }
            foreach (($r['Periods'] ?? []) as $ts => $wh) {
                $sum['PeriodsSum'][$ts] = ($sum['PeriodsSum'][$ts] ?? 0.0) + (float) $wh;
            }
        }
        ksort($sum['HourlySum']);
        ksort($sum['PeriodsSum']);
        return $sum;
    }

    /**
     * Pflegt einen Tages-Ringpuffer (Prognose vs. Ist) und berechnet einen
     * Korrekturfaktor Σ Ist / Σ Prognose der letzten abgeschlossenen Tage.
     * Plausibilisiert auf 0,5 – 1,5.
     *
     * Strategie:
     *   - heutiger Forecast wird bei jedem Aufruf überschrieben
     *   - heutiger Actual wird als Maximum mitgeführt (defensiv gegen
     *     Mitternachts-Reset der Ist-Variable des Wechselrichters)
     *   - Faktor wird nur aus *abgeschlossenen* Vortagen gerechnet
     */
    private function updateCalibration(float $forecastTodayKWh): void
    {
        $varId = $this->ReadPropertyInteger('ActualYieldVariableID');
        if ($varId <= 0 || !IPS_VariableExists($varId)) {
            return;
        }

        $actualNow = (float) GetValue($varId);
        $today = date('Y-m-d');

        $history = json_decode($this->GetBuffer('History'), true);
        if (!is_array($history)) {
            $history = [];
        }

        if (!isset($history[$today])) {
            $history[$today] = ['Forecast' => $forecastTodayKWh, 'Actual' => $actualNow];
        } else {
            $history[$today]['Forecast'] = $forecastTodayKWh;
            // max() schützt gegen Reset der Ist-Variable nach Mitternacht
            $history[$today]['Actual'] = max(
                (float) ($history[$today]['Actual'] ?? 0),
                $actualNow
            );
        }

        $window = max(2, $this->ReadPropertyInteger('CalibrationWindowDays'));
        ksort($history);
        while (count($history) > $window + 1) {
            array_shift($history);
        }
        $this->SetBuffer('History', json_encode($history));

        $sumA = 0.0;
        $sumF = 0.0;
        foreach ($history as $date => $row) {
            if ($date === $today) {
                continue;
            }
            $f = (float) ($row['Forecast'] ?? 0);
            $a = (float) ($row['Actual'] ?? 0);
            if ($f > 0.1) {
                $sumA += $a;
                $sumF += $f;
            }
        }
        if ($sumF > 0) {
            $factor = max(0.5, min(1.5, $sumA / $sumF));
            $this->SetBuffer('Correction', (string) $factor);
            if (@$this->GetIDForIdent('Correction')) {
                $this->SetValue('Correction', $factor);
            }
        }
    }

    /**
     * Testet eine einzelne Fläche und zeigt das Ergebnis als Popup.
     */
    private function testConnection(): void
    {
        $roofs = $this->getActiveRoofs();
        if (empty($roofs)) {
            echo $this->Translate('No active roofs configured.');
            return;
        }
        $r = $roofs[0];
        try {
            $json = $this->fetchRoof(
                $this->ReadPropertyFloat('Latitude'),
                $this->ReadPropertyFloat('Longitude'),
                (float) $r['Declination'],
                (float) $r['Azimuth'],
                (float) $r['kWp'],
                (float) ($r['DampMorning'] ?? 0),
                (float) ($r['DampEvening'] ?? 0),
                trim($this->ReadPropertyString('ApiKey'))
            );
            $parsed = $this->parseResult($json);
            $rl = json_decode($this->GetBuffer('LastRatelimit'), true) ?: [];
            echo sprintf(
                $this->T("Connection OK.\nRoof: %s\nToday: %.2f kWh\nTomorrow: %.2f kWh\nRate-limit: %d/%d (period %ds)"),
                (string) ($r['Name'] ?? '?'),
                $parsed['Today'], $parsed['Tomorrow'],
                (int) ($rl['remaining'] ?? 0), (int) ($rl['limit'] ?? 0), (int) ($rl['period'] ?? 0)
            );
        } catch (Throwable $e) {
            echo $this->Translate('Connection failed: ') . $e->getMessage();
        }
    }

    private function renderAndWrite(array $totals, bool $stale): void
    {
        $this->writeVariables($totals);
        $html = $this->renderHTML($totals, $stale);
        $this->SetValue('Visualization', $html);
    }

    private function writeVariables(array $totals): void
    {
        $this->SetValue('Today',          (float) $totals['Today']);
        $this->SetValue('Tomorrow',       (float) $totals['Tomorrow']);
        $this->SetValue('DayAfter',       (float) $totals['DayAfter']);
        $this->SetValue('PowerNow',       (float) $totals['PowerNow']);
        $this->SetValue('RemainingToday', (float) $totals['RemainingToday']);
        $this->SetValue('LastUpdate',     date('Y-m-d H:i:s'));

        // Per-Roof
        if ($this->ReadPropertyBoolean('PerRoofVariables') && isset($totals['PerRoof'])) {
            foreach ($totals['PerRoof'] as $idx => $r) {
                $identToday = 'Roof' . $idx . 'Today';
                $identTomorrow = 'Roof' . $idx . 'Tomorrow';
                if (@$this->GetIDForIdent($identToday)) {
                    $this->SetValue($identToday, (float) $r['Today']);
                }
                if (@$this->GetIDForIdent($identTomorrow)) {
                    $this->SetValue($identTomorrow, (float) $r['Tomorrow']);
                }
            }
        }
    }

    private function setStatusVar(int $state): void
    {
        $this->SetValue('Status', $state);
    }

    private function getCachedResult(): ?array
    {
        $raw = $this->GetBuffer('LastResult');
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function setCachedResult(array $data): void
    {
        $this->SetBuffer('LastResult', json_encode($data));
    }

    private function fmt(float $v): string
    {
        // forecast.solar verlangt Dezimalpunkt
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

    // ---------------------------------------------------------------
    // Variable / Profile registration
    // ---------------------------------------------------------------

    private function registerProfiles(): void
    {
        if (!IPS_VariableProfileExists('PVF.kWh')) {
            IPS_CreateVariableProfile('PVF.kWh', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('PVF.kWh', '', ' kWh');
            IPS_SetVariableProfileDigits('PVF.kWh', 2);
            IPS_SetVariableProfileIcon('PVF.kWh', 'Sun');
        }
        if (!IPS_VariableProfileExists('PVF.W')) {
            IPS_CreateVariableProfile('PVF.W', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('PVF.W', '', ' W');
            IPS_SetVariableProfileDigits('PVF.W', 0);
            IPS_SetVariableProfileIcon('PVF.W', 'Electricity');
        }
        if (!IPS_VariableProfileExists('PVF.Factor')) {
            IPS_CreateVariableProfile('PVF.Factor', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('PVF.Factor', '', '');
            IPS_SetVariableProfileDigits('PVF.Factor', 3);
        }
        if (!IPS_VariableProfileExists('PVF.Status')) {
            IPS_CreateVariableProfile('PVF.Status', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('PVF.Status', self::STATUS_OK,        $this->Translate('OK'),        '', 0x00C800);
            IPS_SetVariableProfileAssociation('PVF.Status', self::STATUS_ERROR,     $this->Translate('Error'),     '', 0xC80000);
            IPS_SetVariableProfileAssociation('PVF.Status', self::STATUS_RATELIMIT, $this->Translate('Rate-limit'), '', 0xFFA500);
        }
    }

    private function registerSummaryVariables(): void
    {
        $this->RegisterVariableFloat('Today',          $this->Translate('Forecast today'),         'PVF.kWh', 10);
        $this->RegisterVariableFloat('Tomorrow',       $this->Translate('Forecast tomorrow'),      'PVF.kWh', 20);
        $this->RegisterVariableFloat('DayAfter',       $this->Translate('Forecast day after'),     'PVF.kWh', 30);
        $this->RegisterVariableFloat('PowerNow',       $this->Translate('Expected power now'),     'PVF.W',   40);
        $this->RegisterVariableFloat('RemainingToday', $this->Translate('Remaining today'),        'PVF.kWh', 50);
        $this->RegisterVariableString('LastUpdate',    $this->Translate('Last update'),            '',        60);
        $this->RegisterVariableInteger('Status',       $this->Translate('Status'),                 'PVF.Status', 70);
        $this->RegisterVariableString('Visualization', $this->Translate('Visualization'),          '~HTMLBox', 80);

        // Logging aktivieren für die wichtigen Tageswerte
        foreach (['Today', 'Tomorrow', 'DayAfter'] as $ident) {
            $this->enableLogging($ident, 0);
        }
    }

    /**
     * Liefert die InstanceID des (ersten) Archive-Control-Moduls oder 0.
     */
    private function getArchiveID(): int
    {
        $ids = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return (is_array($ids) && count($ids) > 0) ? (int) $ids[0] : 0;
    }

    /**
     * Aktiviert das Logging einer Variable im Archiv.
     * $aggregation: 0 = Standard (Mittelwert), 1 = Zähler etc. (siehe IPS-Doku)
     */
    private function enableLogging(string $ident, int $aggregation = 0): void
    {
        $aid = $this->getArchiveID();
        $vid = @$this->GetIDForIdent($ident);
        if ($aid && $vid && function_exists('AC_SetLoggingStatus')) {
            @AC_SetLoggingStatus($aid, $vid, true);
            @AC_SetAggregationType($aid, $vid, $aggregation);
            @AC_ReAggregateVariable($aid, $vid);
        }
    }

    /**
     * Liest die reale Erzeugung von heute aus dem Archiv (stündlich aggregiert)
     * und liefert kWh je Stunde des Tages [0..23].
     *
     * Erwartet eine Variable mit MOMENTANER PV-Leistung in W (Standard-Logging).
     * Energie/Stunde = Mittelwert(W) × Dauer(h) / 1000.
     */
    private function getActualHourlyKWh(): array
    {
        $vid = $this->ReadPropertyInteger('ActualPowerVariableID');
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return [];
        }
        $aid = $this->getArchiveID();
        if (!$aid || !function_exists('AC_GetAggregatedValues')) {
            return [];
        }

        $startOfDay = (int) strtotime('today 00:00:00');
        $now = time();
        // Aggregationsstufe 0 = stündlich
        $rows = @AC_GetAggregatedValues($aid, $vid, 0, $startOfDay, $now, 0);
        if (!is_array($rows)) {
            return [];
        }

        $byHour = [];
        foreach ($rows as $r) {
            $ts = (int) ($r['TimeStamp'] ?? 0);
            if ($ts < $startOfDay) {
                continue;
            }
            $h = (int) date('G', $ts);
            $avgW = (float) ($r['Avg'] ?? 0);
            $durH = ((float) ($r['Duration'] ?? 3600)) / 3600.0;
            $byHour[$h] = ($byHour[$h] ?? 0.0) + ($avgW * $durH / 1000.0);
        }
        return $byHour;
    }

    private function registerPerRoofVariables(): void
    {
        $roofs = json_decode($this->ReadPropertyString('Roofs'), true);
        if (!is_array($roofs)) {
            $roofs = [];
        }

        if (!$this->ReadPropertyBoolean('PerRoofVariables')) {
            // Vorhandene aufräumen (max. 64 Indizes – mehr als ausreichend)
            for ($i = 0; $i < 64; $i++) {
                foreach (['Today', 'Tomorrow'] as $kind) {
                    $ident = 'Roof' . $i . $kind;
                    if (@$this->GetIDForIdent($ident)) {
                        $this->UnregisterVariable($ident);
                    }
                }
            }
            return;
        }

        foreach ($roofs as $idx => $roof) {
            if (empty($roof['Active'])) {
                continue;
            }
            $name = (string) ($roof['Name'] ?? ('Roof ' . ($idx + 1)));
            $this->RegisterVariableFloat('Roof' . $idx . 'Today',
                sprintf($this->T('%s – today'), $name),
                'PVF.kWh', 100 + $idx * 2);
            $this->RegisterVariableFloat('Roof' . $idx . 'Tomorrow',
                sprintf($this->T('%s – tomorrow'), $name),
                'PVF.kWh', 101 + $idx * 2);
        }
    }

    // ---------------------------------------------------------------
    // HTML rendering
    // ---------------------------------------------------------------

    /**
     * Zeichnet ein selbst gerendertes SVG-Diagramm „Prognose vs. Ist" für heute:
     * Balken = reale Erzeugung (kWh/h), gestrichelte Linie = Prognose (kWh/h).
     * SVG skaliert über viewBox automatisch auf jede Kachelbreite.
     *
     * @param array $forecastByHour [hour 0..23 => kWh]
     * @param array $actualByHour   [hour 0..23 => kWh]
     */
    private function renderOverlayChart(array $forecastByHour, array $actualByHour): string
    {
        $hours = array_unique(array_merge(array_keys($forecastByHour), array_keys($actualByHour)));
        if (empty($hours)) {
            return '';
        }
        sort($hours);
        $minH = (int) min($hours);
        $maxH = (int) max($hours);
        if ($maxH - $minH < 4) {
            $maxH = min(23, $minH + 4);
        }
        $n = $maxH - $minH + 1;

        $allVals = array_merge([0.1], array_values($forecastByHour), array_values($actualByHour));
        $maxVal = ceil(max($allVals) * 10) / 10;
        if ($maxVal <= 0) {
            $maxVal = 0.1;
        }

        // viewBox-Koordinaten (skalieren später auf 100% Breite).
        // Flaches Seitenverhältnis (1000x230) -> niedrige gerenderte Höhe,
        // damit die Box ohne Scrollbalken in eine Kachel passt.
        $W = 1000; $H = 230;
        $L = 46; $R = 992; $T = 12; $B = 196;
        $plotH = $B - $T;
        $slotW = ($R - $L) / $n;

        $xCenter = fn($h) => $L + $slotW * (($h - $minH) + 0.5);
        $yVal    = fn($v) => $B - ($v / $maxVal) * $plotH;
        $r1 = fn($x) => round($x, 1);

        $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="xMidYMid meet" '
             . 'style="width:100%;height:auto;display:block;font-family:\'Segoe UI\',Tahoma,sans-serif;">';

        // Gridlines + Y-Beschriftung (0 / halb / max)
        foreach ([0.0, $maxVal / 2, $maxVal] as $gv) {
            $y = $yVal($gv);
            $svg .= '<line x1="' . $L . '" y1="' . $r1($y) . '" x2="' . $R . '" y2="' . $r1($y)
                  . '" stroke="rgba(255,255,255,0.10)" stroke-width="1"/>';
            $svg .= '<text x="' . ($L - 6) . '" y="' . $r1($y + 4) . '" text-anchor="end" '
                  . 'font-size="16" fill="#9aa6b3">' . number_format($gv, 1) . '</text>';
        }

        // Ist-Balken
        $barW = $slotW * 0.62;
        foreach ($actualByHour as $h => $v) {
            if ($h < $minH || $h > $maxH || $v <= 0) {
                continue;
            }
            $x = $xCenter($h) - $barW / 2;
            $y = $yVal($v);
            $svg .= '<rect x="' . $r1($x) . '" y="' . $r1($y) . '" width="' . $r1($barW)
                  . '" height="' . $r1($B - $y) . '" rx="3" fill="#f7a000" opacity="0.85">'
                  . '<title>' . $h . ':00 ' . htmlspecialchars($this->T('Actual'), ENT_QUOTES)
                  . ': ' . number_format($v, 2) . ' kWh</title></rect>';
        }

        // „Jetzt"-Markierung
        $nowH = (float) date('G') + (float) date('i') / 60.0;
        if ($nowH >= $minH && $nowH <= $maxH + 1) {
            $xn = $L + $slotW * ($nowH - $minH);
            $svg .= '<line x1="' . $r1($xn) . '" y1="' . $T . '" x2="' . $r1($xn) . '" y2="' . $B
                  . '" stroke="rgba(255,255,255,0.28)" stroke-width="1" stroke-dasharray="2 3"/>';
        }

        // Prognose-Linie (gestrichelt) + Punkte
        $pts = [];
        for ($h = $minH; $h <= $maxH; $h++) {
            if (!isset($forecastByHour[$h])) {
                continue;
            }
            $pts[] = $r1($xCenter($h)) . ',' . $r1($yVal($forecastByHour[$h]));
        }
        if (count($pts) >= 2) {
            $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#ffd27a" '
                  . 'stroke-width="3" stroke-dasharray="7 5" stroke-linejoin="round" stroke-linecap="round"/>';
        }
        for ($h = $minH; $h <= $maxH; $h++) {
            if (!isset($forecastByHour[$h])) {
                continue;
            }
            $svg .= '<circle cx="' . $r1($xCenter($h)) . '" cy="' . $r1($yVal($forecastByHour[$h]))
                  . '" r="3" fill="#ffd27a"><title>' . $h . ':00 '
                  . htmlspecialchars($this->T('Forecast'), ENT_QUOTES)
                  . ': ' . number_format($forecastByHour[$h], 2) . ' kWh</title></circle>';
        }

        // X-Beschriftung (alle 2 Stunden)
        for ($h = $minH; $h <= $maxH; $h++) {
            if ($h % 2 !== 0) {
                continue;
            }
            $svg .= '<text x="' . $r1($xCenter($h)) . '" y="' . ($B + 20) . '" text-anchor="middle" '
                  . 'font-size="15" fill="#9aa6b3">' . $h . '</text>';
        }

        // Legende
        $lx = $L + 6; $ly = $T + 14;
        $svg .= '<rect x="' . $lx . '" y="' . ($ly - 10) . '" width="14" height="10" rx="2" fill="#f7a000" opacity="0.85"/>';
        $svg .= '<text x="' . ($lx + 20) . '" y="' . ($ly - 1) . '" font-size="15" fill="#cfd6e0">'
              . htmlspecialchars($this->T('Actual'), ENT_QUOTES) . '</text>';
        $svg .= '<line x1="' . ($lx + 76) . '" y1="' . ($ly - 5) . '" x2="' . ($lx + 106) . '" y2="' . ($ly - 5)
              . '" stroke="#ffd27a" stroke-width="3" stroke-dasharray="7 5"/>';
        $svg .= '<text x="' . ($lx + 112) . '" y="' . ($ly - 1) . '" font-size="15" fill="#cfd6e0">'
              . htmlspecialchars($this->T('Forecast'), ENT_QUOTES) . '</text>';

        $svg .= '</svg>';

        $title = htmlspecialchars($this->T('Today: forecast vs. actual'), ENT_QUOTES);
        return '<div class="htitle">' . $title . ' <span style="color:#9aa6b3;">(kWh)</span></div>'
             . '<div style="background:rgba(255,255,255,0.04);padding:0.5em;border-radius:0.6em;">' . $svg . '</div>';
    }

    private function renderHTML(array $totals, bool $stale): string
    {
        $today    = (float) ($totals['Today'] ?? 0);
        $tomorrow = (float) ($totals['Tomorrow'] ?? 0);
        $dayAfter = (float) ($totals['DayAfter'] ?? 0);
        $power    = (float) ($totals['PowerNow'] ?? 0);
        $remain   = (float) ($totals['RemainingToday'] ?? 0);
        $corr     = isset($totals['Correction']) ? (float) $totals['Correction'] : null;

        $max = max($today, $tomorrow, $dayAfter, 0.01);

        // Eindeutiger Scope pro Instanz -> mehrere HTMLBoxen auf einer Seite
        // kollidieren nicht und das <style> bleibt lokal.
        $scope = 'pvf-' . $this->InstanceID;

        // --- Tagesbalken (HTML, gestylt über .row im <style>-Block) ---
        $rowHtml = function (string $label, float $kwh, float $max): string {
            $pct = max(2, (int) round(($kwh / $max) * 100));
            $lbl = htmlspecialchars($label, ENT_QUOTES);
            $val = number_format($kwh, 2, '.', '');
            return '<div class="row"><div class="cap"><span>' . $lbl . '</span>'
                 . '<span>' . $val . ' kWh</span></div>'
                 . '<div class="track"><div class="fill" style="width:' . $pct . '%;"></div></div></div>';
        };

        // --- Stundenanzeige ---
        // Wenn eine Ist-Leistungs-Variable konfiguriert ist: Overlay-Diagramm
        // (Balken = Ist, Linie = Prognose). Sonst: einfaches Forecast-Stundenprofil.
        $hourlyHtml = '';
        $hasActualVar = $this->ReadPropertyInteger('ActualPowerVariableID') > 0;
        if ($hasActualVar) {
            $forecastByHour = [];
            foreach (($totals['HourlySum'] ?? []) as $ts => $wh) {
                $t = strtotime((string) $ts);
                if ($t === false) {
                    continue;
                }
                $h = (int) date('G', $t);
                $forecastByHour[$h] = ($forecastByHour[$h] ?? 0.0) + (float) $wh / 1000.0;
            }
            $actualByHour = $this->getActualHourlyKWh();
            $hourlyHtml = $this->renderOverlayChart($forecastByHour, $actualByHour);
        }
        if ($hourlyHtml === '' && !empty($totals['HourlySum']) && is_array($totals['HourlySum'])) {
            $vals = array_values($totals['HourlySum']);
            $hmax = max($vals) ?: 1.0;
            $bars = '';
            foreach ($totals['HourlySum'] as $ts => $wh) {
                $pct = max(2, (int) round(($wh / $hmax) * 100));
                $hm  = date('H:i', (int) strtotime((string) $ts));
                $tip = htmlspecialchars(sprintf('%s · %.2f kWh', $hm, ((float) $wh) / 1000.0), ENT_QUOTES);
                $bars .= '<div class="hbar" title="' . $tip . '" style="height:' . $pct . '%;">'
                       . '<span class="tip">' . $tip . '</span></div>';
            }
            $hourlyHtml = '<div class="htitle">' . htmlspecialchars($this->T('Hourly profile today'), ENT_QUOTES)
                . '</div><div class="hourly">' . $bars . '</div>';
        }

        $lat = (float) $this->ReadPropertyFloat('Latitude');
        $lon = (float) $this->ReadPropertyFloat('Longitude');
        $loc = sprintf('%.3f / %.3f', $lat, $lon);
        $last = date('Y-m-d H:i');
        $staleNote = $stale
            ? ' <span class="stale">(' . htmlspecialchars($this->T('cached'), ENT_QUOTES) . ')</span>'
            : '';
        $corrLine = ($corr !== null)
            ? ' · ' . htmlspecialchars($this->T('Correction'), ENT_QUOTES) . ': ' . number_format($corr, 3)
            : '';

        $tTitle  = htmlspecialchars($this->T('PV forecast'), ENT_QUOTES);
        $tPower  = htmlspecialchars($this->T('Power now'), ENT_QUOTES);
        $tRemain = htmlspecialchars($this->T('Remaining today'), ENT_QUOTES);
        $powerVal  = number_format($power, 0, '.', '');
        $remainVal = number_format($remain, 2, '.', '');

        $rowsHtml = $rowHtml($this->T('Today'),     round($today, 2),    $max)
                  . $rowHtml($this->T('Tomorrow'),  round($tomorrow, 2), $max)
                  . $rowHtml($this->T('Day after'), round($dayAfter, 2), $max);

        // Hinweis zur Responsivität:
        //  - container-type:inline-size auf dem Wrapper -> cqi-Einheiten messen
        //    die KACHEL-Breite (nicht die Viewport-Breite wie vw). Gleiche Kachel =
        //    gleiche Darstellung auf jedem Client.
        //  - font-size doppelt deklariert: erst fixer px-Wert (Fallback für alte
        //    IPSView-Webviews ohne cqi), dann clamp() mit cqi für moderne Clients.
        //  - alle Kindgrößen in em -> skalieren mit der einen Basis-Schriftgröße.
        return <<<HTML
<div id="{$scope}" style="container-type:inline-size;width:100%;">
<style>
#{$scope} .card{font-family:'Segoe UI',Tahoma,sans-serif;color:#e9edf3;box-sizing:border-box;
  font-size:13px;font-size:clamp(10px,3.6cqi,16px);
  padding:0.6em 0.9em;border-radius:0.8em;
  background:linear-gradient(135deg,rgba(20,28,40,0.9),rgba(10,14,22,0.9));}
#{$scope} .card *{box-sizing:border-box;}
#{$scope} .head{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;
  gap:0.2em 1em;margin-bottom:0.45em;}
#{$scope} .title{font-size:1.2em;font-weight:600;}
#{$scope} .sub{font-size:0.78em;color:#9aa6b3;}
#{$scope} .stale{color:#ffb86b;}
#{$scope} .metrics{display:flex;flex-wrap:wrap;gap:0.4em 1.3em;margin-bottom:0.4em;}
#{$scope} .metric{flex:1 1 8em;text-align:center;}
#{$scope} .metric .lbl{font-size:0.76em;color:#9aa6b3;}
#{$scope} .metric .val{font-size:1.45em;font-weight:600;line-height:1.1;}
#{$scope} .row{margin:0.28em 0;}
#{$scope} .row .cap{display:flex;justify-content:space-between;font-size:0.82em;color:#cfd6e0;margin-bottom:0.15em;}
#{$scope} .track{background:rgba(255,255,255,0.08);height:0.6em;border-radius:999px;overflow:hidden;}
#{$scope} .fill{height:100%;background:linear-gradient(90deg,#f7b500,#ff7a00);}
#{$scope} .htitle{font-size:0.82em;color:#cfd6e0;margin:0.5em 0 0.25em;}
#{$scope} .hourly{background:rgba(255,255,255,0.04);padding:0.5em;border-radius:0.6em;
  display:flex;align-items:flex-end;gap:0.12em;height:6em;}
#{$scope} .hbar{position:relative;flex:1 1 0;min-width:2px;align-self:flex-end;
  background:linear-gradient(180deg,#f7b500,#ff7a00);border-radius:3px 3px 0 0;transition:filter .12s;}
#{$scope} .hbar:hover{filter:brightness(1.3);}
#{$scope} .tip{position:absolute;bottom:108%;left:50%;transform:translateX(-50%);white-space:nowrap;
  background:rgba(8,12,20,0.97);color:#e9edf3;font-size:0.72em;padding:0.25em 0.5em;
  border-radius:0.35em;border:1px solid rgba(255,255,255,0.14);
  opacity:0;pointer-events:none;transition:opacity .12s;z-index:9;}
#{$scope} .hbar:hover .tip{opacity:1;}
</style>
<div class="card">
  <div class="head">
    <div class="title">☀ {$tTitle}</div>
    <div class="sub">{$loc} · {$last}{$staleNote}{$corrLine}</div>
  </div>
  <div class="metrics">
    <div class="metric"><div class="lbl">{$tPower}</div><div class="val">{$powerVal} W</div></div>
    <div class="metric"><div class="lbl">{$tRemain}</div><div class="val">{$remainVal} kWh</div></div>
  </div>
  {$rowsHtml}
  {$hourlyHtml}
</div>
</div>
HTML;
    }
}
