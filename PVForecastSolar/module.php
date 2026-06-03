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

        // Buffer
        $this->SetBuffer('LastResult', '');
        $this->SetBuffer('LastRatelimit', '');
        $this->SetBuffer('History', '[]');
        $this->SetBuffer('Correction', '1.0');

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
     * Rendert das HTML aus dem Cache neu (ohne API-Call).
     * Nützlich nach Template-Änderungen oder wenn das Rate-Limit
     * gerade erschöpft ist und du trotzdem die letzte Visualisierung
     * mit dem neuen Layout sehen willst.
     */
    private function refreshVisualizationFromCache(): void
    {
        $cached = $this->getCachedResult();
        if ($cached === null) {
            echo $this->Translate('No cached forecast available. Run "Update now" once first.');
            return;
        }
        $html = $this->renderHTML($cached, true);
        $this->SetValue('Visualization', $html);
        echo $this->Translate('Visualization re-rendered from cache.');
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
                $this->setStatusVar(self::STATUS_ERROR);
                $this->LogMessage(sprintf(
                    $this->T('Fetch failed for roof "%s": %s'),
                    (string) ($roof['Name'] ?? '?'), $e->getMessage()
                ), KL_ERROR);
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

        // Stündliches Profil heute
        $hourly = [];
        if (isset($result['watt_hours_period']) && is_array($result['watt_hours_period'])) {
            foreach ($result['watt_hours_period'] as $ts => $wh) {
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
        }
        ksort($sum['HourlySum']);
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
            $vid = @$this->GetIDForIdent($ident);
            if ($vid && function_exists('AC_SetLoggingStatus')) {
                $aid = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}'); // Archive Control
                if (is_array($aid) && count($aid) > 0) {
                    @AC_SetLoggingStatus($aid[0], $vid, true);
                    @AC_SetAggregationType($aid[0], $vid, 0); // Counter
                }
            }
        }
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

    private function renderHTML(array $totals, bool $stale): string
    {
        $today    = (float) ($totals['Today'] ?? 0);
        $tomorrow = (float) ($totals['Tomorrow'] ?? 0);
        $dayAfter = (float) ($totals['DayAfter'] ?? 0);
        $power    = (float) ($totals['PowerNow'] ?? 0);
        $remain   = (float) ($totals['RemainingToday'] ?? 0);
        $corr     = isset($totals['Correction']) ? (float) $totals['Correction'] : null;

        $max = max($today, $tomorrow, $dayAfter, 0.01);

        // Fluid Font-Größen: clamp(min, ideal, max) – ideal skaliert mit Viewport-Breite
        // Tagesbalken
        $bar = function (string $label, float $kwh, float $max): string {
            $pct = max(2, (int) round(($kwh / $max) * 100));
            $lbl = htmlspecialchars($label, ENT_QUOTES);
            return <<<HTML
<div style="margin:0.45em 0;">
  <div style="display:flex;justify-content:space-between;font-size:clamp(11px,1.4vw,13px);color:#cfd6e0;margin-bottom:3px;">
    <span>{$lbl}</span><span>{$kwh} kWh</span>
  </div>
  <div style="background:rgba(255,255,255,0.08);height:clamp(8px,1.2vw,14px);border-radius:999px;overflow:hidden;">
    <div style="width:{$pct}%;height:100%;background:linear-gradient(90deg,#f7b500,#ff7a00);"></div>
  </div>
</div>
HTML;
        };

        // Stündliches Profil – Bars als flex:1, füllen die volle verfügbare Breite
        $hourlyHtml = '';
        if (!empty($totals['HourlySum']) && is_array($totals['HourlySum'])) {
            $vals = array_values($totals['HourlySum']);
            $hmax = max($vals) ?: 1.0;
            $bars = '';
            foreach ($totals['HourlySum'] as $ts => $wh) {
                $pct = (int) round(($wh / $hmax) * 100);
                $title = sprintf('%s: %.0f Wh', (string) $ts, (float) $wh);
                // flex:1 → Bars verteilen sich auf die volle Breite.
                // height via aspect-irrelevant: nutzen feste Container-Höhe + prozentuale Bar-Höhe.
                $bars .= '<div title="' . htmlspecialchars($title, ENT_QUOTES) . '" '
                       . 'style="flex:1 1 0;min-width:3px;max-width:22px;margin:0 1px;'
                       . 'align-self:flex-end;height:' . max(2, $pct) . '%;'
                       . 'background:linear-gradient(180deg,#f7b500,#ff7a00);border-radius:2px 2px 0 0;"></div>';
            }
            $hourlyHtml = '<div style="margin-top:0.9em;">'
                . '<div style="font-size:clamp(11px,1.4vw,13px);color:#cfd6e0;margin-bottom:4px;">'
                . $this->Translate('Hourly profile today') . '</div>'
                . '<div style="background:rgba(255,255,255,0.04);padding:6px;border-radius:8px;'
                . 'display:flex;align-items:flex-end;height:clamp(50px,9vw,110px);">'
                . $bars . '</div></div>';
        }

        $lat = (float) $this->ReadPropertyFloat('Latitude');
        $lon = (float) $this->ReadPropertyFloat('Longitude');
        $loc = sprintf('%.3f / %.3f', $lat, $lon);
        $last = date('Y-m-d H:i');
        $staleNote = $stale ? '<span style="color:#ffb86b;">&nbsp;(' . $this->Translate('cached') . ')</span>' : '';
        $corrLine = ($corr !== null)
            ? '<span style="margin-left:10px;white-space:nowrap;">' . $this->Translate('Correction') . ': ' . number_format($corr, 3) . '</span>'
            : '';

        return <<<HTML
<div style="font-family:'Segoe UI',Tahoma,sans-serif;color:#e9edf3;
            padding:clamp(10px,1.2vw,16px) clamp(12px,1.4vw,20px);
            border-radius:12px;
            background:linear-gradient(135deg,rgba(20,28,40,0.85),rgba(10,14,22,0.85));
            width:100%;box-sizing:border-box;">
  <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;
              gap:6px 14px;margin-bottom:10px;">
    <div style="font-size:clamp(15px,1.8vw,20px);font-weight:600;">☀ {$this->Translate('PV forecast')}</div>
    <div style="font-size:clamp(10px,1.1vw,13px);color:#9aa6b3;">
      {$loc} · {$last}{$staleNote}{$corrLine}
    </div>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:10px 18px;margin-bottom:8px;">
    <div style="flex:1 1 130px;text-align:center;">
      <div style="font-size:clamp(10px,1.1vw,13px);color:#9aa6b3;">{$this->Translate('Power now')}</div>
      <div style="font-size:clamp(17px,2.3vw,26px);font-weight:600;">{$power} W</div>
    </div>
    <div style="flex:1 1 130px;text-align:center;">
      <div style="font-size:clamp(10px,1.1vw,13px);color:#9aa6b3;">{$this->Translate('Remaining today')}</div>
      <div style="font-size:clamp(17px,2.3vw,26px);font-weight:600;">{$remain} kWh</div>
    </div>
  </div>
  {$bar($this->Translate('Today'),         round($today, 2), $max)}
  {$bar($this->Translate('Tomorrow'),      round($tomorrow, 2), $max)}
  {$bar($this->Translate('Day after'),     round($dayAfter, 2), $max)}
  {$hourlyHtml}
</div>
HTML;
    }
}
