<?php

declare(strict_types=1);

require_once __DIR__ . '/EaseeDashboard.php';

/**
 * Easee Wallbox
 *
 * Liest den Zustand einer Easee-Wallbox über die Easee Cloud
 * (Observations-API) aus und erlaubt Laden starten/pausieren sowie
 * das dauerhafte Verriegeln des Kabels.
 */
class EaseeWallbox extends IPSModule
{
    use EaseeDashboard;

    private const API_HOST = 'https://api.easee.com';

    // Observation-IDs der Easee-API
    private const OBS_CABLE_PERMANENT = 30;
    private const OBS_CURRENT_L1 = 73;
    private const OBS_CURRENT_L2 = 74;
    private const OBS_CURRENT_L3 = 75;
    private const OBS_FIRMWARE = 80;
    private const OBS_REASON = 96;
    private const OBS_SMART_CHARGING = 102;
    private const OBS_CABLE_LOCKED = 103;
    private const OBS_OP_MODE = 109;
    private const OBS_OUTPUT_PHASE = 110;
    private const OBS_OUTPUT_CURRENT = 114;
    private const OBS_ERROR_CODE = 119;
    private const OBS_TOTAL_POWER = 120;
    private const OBS_SESSION_ENERGY = 121;
    private const OBS_LIFETIME_ENERGY = 124;
    private const OBS_WIFI_RSSI = 132;
    private const OBS_CLOUD = 250;

    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const HISTORY_MAX = 30;

    // Anzahl Abrufe ohne Strom, bevor ein Ladevorgang als beendet gilt
    // (schützt vor kurzen Aussetzern, z. B. Balancing der Fahrzeugbatterie)
    private const STOP_CONFIRM_CYCLES = 2;

    // =================================================================
    // Symcon-Lebenszyklus
    // =================================================================

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('ChargerID', '');
        $this->RegisterPropertyInteger('UpdateInterval', 5);
        $this->RegisterPropertyInteger('MaxPowerKW', 11);
        $this->RegisterPropertyInteger('NotifyInstance', 0);
        $this->RegisterPropertyBoolean('NotifyStart', true);
        $this->RegisterPropertyBoolean('NotifyEnd', true);
        $this->RegisterPropertyBoolean('NotifyError', true);
        $this->RegisterPropertyBoolean('Dashboard', true);
        $this->RegisterPropertyBoolean('LogPower', true);

        // Interne Daten - nicht mehr als versteckte Variablen, sondern
        // als Attribute direkt an der Instanz
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('CredentialHash', '');
        $this->RegisterAttributeString('ActiveChargerID', '');
        $this->RegisterAttributeString('ChargerName', '');
        $this->RegisterAttributeInteger('SessionStart', 0);
        $this->RegisterAttributeInteger('StopPending', 0);
        $this->RegisterAttributeString('History', '[]');

        $this->RegisterTimer('UpdateTimer', 0, 'EASEE_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('QuickRefresh', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'QuickRefresh\', true);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $this->CreateProfiles();
        $this->CreateVariables();

        // Zugangsdaten geändert -> alte Tokens verwerfen
        $hash = md5($this->ReadPropertyString('Username') . '|' . $this->ReadPropertyString('Password'));
        if ($hash !== $this->ReadAttributeString('CredentialHash')) {
            $this->WriteAttributeString('CredentialHash', $hash);
            $this->ClearTokens();
            $this->WriteAttributeString('ActiveChargerID', '');
        }

        // Fest eingetragene Charger-ID übernehmen
        $wanted = trim($this->ReadPropertyString('ChargerID'));
        if ($wanted !== '' && $wanted !== $this->ReadAttributeString('ActiveChargerID')) {
            $this->WriteAttributeString('ActiveChargerID', $wanted);
            $this->WriteAttributeString('ChargerName', '');
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        if ($this->ReadPropertyBoolean('LogPower')) {
            $this->EnableArchiveLogging('Power');
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(104);
            return;
        }

        if (trim($this->ReadPropertyString('Username')) === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(201);
            return;
        }

        $this->SetTimerInterval('UpdateTimer', max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000);
        $this->SetStatus(102);

        // Ersten Abruf kurz nach dem Übernehmen starten (nicht blockierend)
        $this->ScheduleQuickRefresh(3);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ChargingActive':
                $Value ? $this->StartCharging() : $this->StopCharging();
                break;

            case 'CableLockPermanent':
                $this->SetCableLockPermanent((bool) $Value);
                break;

            case 'EnergyPrice':
                $this->SetEnergyPrice((float) $Value);
                break;

            case 'QuickRefresh':
                $this->SetTimerInterval('QuickRefresh', 0);
                $this->Update();
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    // =================================================================
    // Öffentliche Funktionen (EASEE_...)
    // =================================================================

    /** Zustand der Wallbox abrufen und alle Variablen aktualisieren. */
    public function Update(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }

        $ok = true;

        try {
            $observations = $this->FetchObservations();
            $this->ProcessObservations($observations);
            $this->ReportSuccess();
        } catch (Exception $e) {
            $this->ReportError($e);
            $ok = false;
        }

        $this->RefreshDashboard();

        return $ok;
    }

    /** Laden fortsetzen/starten (resume_charging). */
    public function StartCharging(): bool
    {
        return $this->RunCommand('resume_charging', null, function () {
            $this->WriteAttributeInteger('StopPending', 0);
        });
    }

    /** Laden pausieren (pause_charging). */
    public function StopCharging(): bool
    {
        return $this->RunCommand('pause_charging', null, function () {
            // Nächster Abruf ohne Strom beendet die Session sofort
            if ($this->GetValue('ChargingActive')) {
                $this->WriteAttributeInteger('StopPending', self::STOP_CONFIRM_CYCLES - 1);
            }
        });
    }

    /** Kabel dauerhaft verriegeln (true) oder freigeben (false). */
    public function SetCableLockPermanent(bool $State): bool
    {
        return $this->RunCommand('lock_state', ['state' => $State], function () use ($State) {
            $this->SetValue('CableLockPermanent', $State);
        });
    }

    /** Strompreis in €/kWh setzen, Kosten werden sofort neu berechnet. */
    public function SetEnergyPrice(float $Price): void
    {
        $Price = max(0.0, $Price);

        $this->SetValue('EnergyPrice', $Price);
        $this->SetValue('SessionCost', round($this->GetValue('SessionEnergy') * $Price, 2));
        $this->SetValue('LifetimeCost', round($this->GetValue('LifetimeEnergy') * $Price, 2));

        $this->RefreshDashboard();
    }

    /** Listet alle Charger des Kontos auf (auch als Verbindungstest). */
    public function ListChargers(): string
    {
        try {
            $chargers = $this->Api('GET', '/api/chargers');
        } catch (Exception $e) {
            $this->ReportError($e);
            return 'Fehler: ' . $e->getMessage();
        }

        if (!is_array($chargers) || count($chargers) === 0) {
            return 'Anmeldung erfolgreich, aber kein Charger im Konto gefunden.';
        }

        $lines = ['Anmeldung erfolgreich. Gefundene Charger:', ''];
        foreach ($chargers as $c) {
            $lines[] = ($c['id'] ?? '?') . '  –  ' . ($c['name'] ?? 'ohne Namen');
        }

        return implode("\n", $lines);
    }

    /** Liefert die Ladehistorie als JSON (neueste zuletzt). */
    public function GetHistory(): string
    {
        return $this->ReadAttributeString('History');
    }

    /** Ladehistorie löschen. */
    public function ResetHistory(): void
    {
        $this->WriteAttributeString('History', '[]');
        $this->RefreshDashboard();
    }

    /**
     * Ladehistorie aus den alten Skripten übernehmen, z. B.:
     * EASEE_ImportHistory(12345, GetValueString(<ID der alten Variable "Ladehistorie (JSON)">));
     */
    public function ImportHistory(string $Json): int
    {
        $import = json_decode($Json, true);
        if (!is_array($import)) {
            throw new Exception('Ungültiges JSON.');
        }

        $history = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $history = array_merge($history, $import);

        usort($history, fn ($a, $b) => ($a['end'] ?? 0) <=> ($b['end'] ?? 0));
        $history = array_slice($history, -self::HISTORY_MAX);

        $this->WriteAttributeString('History', json_encode($history));
        $this->RefreshDashboard();

        return count($history);
    }

    // =================================================================
    // Auswertung
    // =================================================================

    private function ProcessObservations(array $v): void
    {
        $opMode = (int) ($v[self::OBS_OP_MODE] ?? 0);
        $current = (float) ($v[self::OBS_OUTPUT_CURRENT] ?? 0);
        $outputPhase = (int) ($v[self::OBS_OUTPUT_PHASE] ?? 0);
        $power = (float) ($v[self::OBS_TOTAL_POWER] ?? 0);
        $sessionEnergy = (float) ($v[self::OBS_SESSION_ENERGY] ?? 0);
        $lifetimeEnergy = (float) ($v[self::OBS_LIFETIME_ENERGY] ?? 0);
        $errorCode = (int) ($v[self::OBS_ERROR_CODE] ?? 0);
        $price = (float) $this->GetValue('EnergyPrice');

        $sessionCost = round($sessionEnergy * $price, 2);
        $vehicleConnected = in_array($opMode, [2, 3, 4, 6, 7], true);

        // --- Ladevorgang erkennen (mit Schutzzeit gegen Aussetzer) ---
        $wasCharging = (bool) $this->GetValue('ChargingActive');
        $isCharging = $wasCharging;
        $chargingNow = $opMode === 3 && $current > 0;
        $pending = $this->ReadAttributeInteger('StopPending');

        if ($chargingNow) {
            $pending = 0;
            if (!$wasCharging) {
                $isCharging = true;
                $this->WriteAttributeInteger('SessionStart', time());
                if ($this->ReadPropertyBoolean('NotifyStart')) {
                    $this->Notify('⚡ Easee Wallbox', 'Ladevorgang gestartet.');
                }
            }
        } elseif ($wasCharging) {
            $pending++;
            // Fahrzeug abgesteckt -> sofort beenden, sonst Schutzzeit abwarten
            if (!$vehicleConnected || $pending >= self::STOP_CONFIRM_CYCLES) {
                $isCharging = false;
                $pending = 0;
                $this->FinishSession($sessionEnergy, $sessionCost);
            }
        }

        $this->WriteAttributeInteger('StopPending', $pending);

        // --- Fehler neu aufgetreten? ---
        $wasError = (int) $this->GetValue('ErrorCode') !== 0;
        if ($errorCode !== 0 && !$wasError && $this->ReadPropertyBoolean('NotifyError')) {
            $this->Notify('⚠️ Easee Wallbox', self::ErrorText($errorCode));
        }

        // --- Variablen schreiben ---
        $this->SetValue('Status', $opMode);
        $this->SetValue('ChargingActive', $isCharging);
        $this->SetValue('Power', round($power, 2));
        $this->SetValue('Current', round($current, 1));
        $this->SetValue('PhaseCount', self::PhaseCount($outputPhase));
        $this->SetValue('CurrentL1', round((float) ($v[self::OBS_CURRENT_L1] ?? 0), 1));
        $this->SetValue('CurrentL2', round((float) ($v[self::OBS_CURRENT_L2] ?? 0), 1));
        $this->SetValue('CurrentL3', round((float) ($v[self::OBS_CURRENT_L3] ?? 0), 1));
        $this->SetValue('SessionEnergy', round($sessionEnergy, 2));
        $this->SetValue('SessionCost', $sessionCost);
        $this->SetValue('LifetimeEnergy', round($lifetimeEnergy, 2));
        $this->SetValue('LifetimeCost', round($lifetimeEnergy * $price, 2));
        $this->SetValue('VehicleConnected', $vehicleConnected);
        $this->SetValue('CableLocked', self::ToBool($v[self::OBS_CABLE_LOCKED] ?? false));
        $this->SetValue('CableLockPermanent', self::ToBool($v[self::OBS_CABLE_PERMANENT] ?? false));
        $this->SetValue('SmartCharging', self::ToBool($v[self::OBS_SMART_CHARGING] ?? false));
        $this->SetValue('Online', self::ToBool($v[self::OBS_CLOUD] ?? false));
        $this->SetValue('WiFiRSSI', (int) ($v[self::OBS_WIFI_RSSI] ?? 0));
        $this->SetValue('Firmware', (string) ($v[self::OBS_FIRMWARE] ?? ''));
        $this->SetValue('Reason', self::ReasonText($v[self::OBS_REASON] ?? null));
        $this->SetValue('ErrorCode', $errorCode);
        $this->SetValue('ErrorText', self::ErrorText($errorCode));
        $this->SetValue('LastUpdate', time());
    }

    private function FinishSession(float $energy, float $cost): void
    {
        $start = $this->ReadAttributeInteger('SessionStart');
        $end = time();

        if ($start > 0) {
            $history = json_decode($this->ReadAttributeString('History'), true) ?: [];
            $history[] = [
                'start'    => $start,
                'end'      => $end,
                'duration' => max(0, $end - $start),
                'energy'   => round($energy, 2),
                'cost'     => round($cost, 2)
            ];
            $history = array_slice($history, -self::HISTORY_MAX);
            $this->WriteAttributeString('History', json_encode($history));
        }

        $this->WriteAttributeInteger('SessionStart', 0);

        if ($this->ReadPropertyBoolean('NotifyEnd')) {
            $this->Notify(
                '✅ Easee Wallbox',
                sprintf(
                    'Ladevorgang beendet: %s kWh, %s €',
                    number_format($energy, 2, ',', '.'),
                    number_format($cost, 2, ',', '.')
                )
            );
        }
    }

    private function RunCommand(string $command, ?array $body, callable $onSuccess): bool
    {
        try {
            $chargerId = $this->GetChargerId();
            $this->Api('POST', '/api/chargers/' . rawurlencode($chargerId) . '/commands/' . $command, $body);
            $onSuccess();
            $this->ReportSuccess();
            $this->SendDebug('Befehl', $command . ' gesendet', 0);
        } catch (Exception $e) {
            $this->ReportError($e);
            echo 'Fehler: ' . $e->getMessage();
            return false;
        }

        // Wallbox braucht einen Moment - Zustand in 15 s neu holen
        $this->ScheduleQuickRefresh(15);

        return true;
    }

    private function ReportSuccess(): void
    {
        $this->SetValue('ApiOk', true);
        $this->SetValue('LastError', '');
        if ($this->GetStatus() !== 102 && $this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(102);
        }
    }

    private function ReportError(Exception $e): void
    {
        $message = $e->getMessage();
        $previous = (string) $this->GetValue('LastError');

        $this->SetValue('ApiOk', false);
        $this->SetValue('LastError', $message);
        $this->SetStatus($e instanceof EaseeAuthException ? 202 : 203);
        $this->LogMessage($message, KL_ERROR);

        // Nur bei neuer Fehlermeldung benachrichtigen, nicht bei jedem Abruf
        if ($message !== $previous && $this->ReadPropertyBoolean('NotifyError')) {
            $this->Notify('⚠️ Easee API', $message);
        }
    }

    private function ScheduleQuickRefresh(int $seconds): void
    {
        $this->SetTimerInterval('QuickRefresh', $seconds * 1000);
    }

    private function RefreshDashboard(): void
    {
        if ($this->ReadPropertyBoolean('Dashboard') && @$this->GetIDForIdent('Dashboard') !== false) {
            $this->SetValue('Dashboard', $this->BuildDashboard());
        }
    }

    // =================================================================
    // Easee API
    // =================================================================

    private function FetchObservations(): array
    {
        $chargerId = $this->GetChargerId();

        $ids = implode(',', [
            self::OBS_CABLE_PERMANENT, self::OBS_CURRENT_L1, self::OBS_CURRENT_L2, self::OBS_CURRENT_L3,
            self::OBS_FIRMWARE, self::OBS_REASON, self::OBS_SMART_CHARGING, self::OBS_CABLE_LOCKED,
            self::OBS_OP_MODE, self::OBS_OUTPUT_PHASE, self::OBS_OUTPUT_CURRENT, self::OBS_ERROR_CODE,
            self::OBS_TOTAL_POWER, self::OBS_SESSION_ENERGY, self::OBS_LIFETIME_ENERGY,
            self::OBS_WIFI_RSSI, self::OBS_CLOUD
        ]);

        $data = $this->Api('GET', '/state/' . rawurlencode($chargerId) . '/observations?ids=' . $ids);

        if (!is_array($data) || !isset($data['observations']) || !is_array($data['observations'])) {
            throw new Exception('Unerwartete Antwort der Observations-API.');
        }

        $values = [];
        foreach ($data['observations'] as $obs) {
            if (isset($obs['id'])) {
                $values[(int) $obs['id']] = $obs['value'] ?? null;
            }
        }

        return $values;
    }

    private function GetChargerId(): string
    {
        $id = $this->ReadAttributeString('ActiveChargerID');
        if ($id !== '') {
            return $id;
        }

        $chargers = $this->Api('GET', '/api/chargers');
        if (!is_array($chargers) || !isset($chargers[0]['id'])) {
            throw new Exception('Kein Charger im Easee-Konto gefunden.');
        }

        $this->WriteAttributeString('ActiveChargerID', (string) $chargers[0]['id']);
        $this->WriteAttributeString('ChargerName', (string) ($chargers[0]['name'] ?? ''));

        return (string) $chargers[0]['id'];
    }

    /**
     * Zentraler API-Aufruf: kümmert sich um Token, 401 (neu anmelden)
     * und 429 (kurz warten) - einmalige Wiederholung.
     */
    private function Api(string $method, string $path, ?array $body = null)
    {
        $token = $this->GetAccessToken();
        [$code, $raw] = $this->HttpRequest($method, self::API_HOST . $path, $body, $token);

        if ($code === 401) {
            $this->SendDebug('API', '401 - melde neu an', 0);
            $this->ClearTokens();
            $token = $this->GetAccessToken();
            [$code, $raw] = $this->HttpRequest($method, self::API_HOST . $path, $body, $token);
        } elseif ($code === 429) {
            $this->SendDebug('API', '429 - warte 5 s', 0);
            sleep(5);
            [$code, $raw] = $this->HttpRequest($method, self::API_HOST . $path, $body, $token);
        }

        if ($code === 401) {
            throw new EaseeAuthException('Easee lehnt die Anmeldung ab (HTTP 401).');
        }
        if ($code === 429) {
            throw new Exception('Easee API-Limit erreicht (HTTP 429). Updateintervall erhöhen.');
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception('Easee API Fehler HTTP ' . $code . ': ' . mb_substr($raw, 0, 200));
        }

        if (trim($raw) === '') {
            return null;
        }

        $json = json_decode($raw, true);
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Ungültige JSON-Antwort: ' . json_last_error_msg());
        }

        return $json;
    }

    private function GetAccessToken(): string
    {
        $lock = 'EASEE_Token_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 15000)) {
            throw new Exception('Token-Sperre konnte nicht belegt werden.');
        }

        try {
            $token = $this->ReadAttributeString('AccessToken');
            if ($token !== '' && $this->ReadAttributeInteger('TokenExpires') > time()) {
                return $token;
            }

            if ($this->ReadAttributeString('RefreshToken') !== '') {
                try {
                    return $this->RequestToken('/api/accounts/refresh_token', [
                        'accessToken'  => $token,
                        'refreshToken' => $this->ReadAttributeString('RefreshToken')
                    ]);
                } catch (Exception $e) {
                    $this->SendDebug('Token', 'Refresh fehlgeschlagen: ' . $e->getMessage(), 0);
                }
            }

            return $this->RequestToken('/api/accounts/login', [
                'userName' => $this->ReadPropertyString('Username'),
                'password' => $this->ReadPropertyString('Password')
            ]);
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    private function RequestToken(string $path, array $body): string
    {
        [$code, $raw] = $this->HttpRequest('POST', self::API_HOST . $path, $body, null);

        if ($code === 400 || $code === 401 || $code === 403) {
            throw new EaseeAuthException('Anmeldung bei Easee fehlgeschlagen (HTTP ' . $code . '). Benutzername/Passwort prüfen.');
        }
        if ($code !== 200) {
            throw new Exception('Token-Abruf fehlgeschlagen (HTTP ' . $code . ').');
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['accessToken'])) {
            throw new Exception('Easee hat kein Token geliefert.');
        }

        $this->WriteAttributeString('AccessToken', (string) $json['accessToken']);
        $this->WriteAttributeString('RefreshToken', (string) ($json['refreshToken'] ?? ''));
        $this->WriteAttributeInteger('TokenExpires', time() + (int) ($json['expiresIn'] ?? 3600) - 60);
        $this->SendDebug('Token', 'Neues Token erhalten', 0);

        return (string) $json['accessToken'];
    }

    private function ClearTokens(): void
    {
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('TokenExpires', 0);
    }

    /** @return array{0:int,1:string} HTTP-Code und Rohantwort */
    protected function HttpRequest(string $method, string $url, ?array $body, ?string $token): array
    {
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Passwort/Token nicht im Debug anzeigen
        $this->SendDebug($method, preg_replace('#\?.*$#', '', $url) . ' -> HTTP ' . $code, 0);

        if ($raw === false) {
            throw new Exception('Verbindungsfehler: ' . $error);
        }

        if (strpos($url, '/accounts/') === false) {
            $this->SendDebug('Antwort', mb_substr((string) $raw, 0, 1000), 0);
        }

        return [$code, (string) $raw];
    }

    // =================================================================
    // Benachrichtigung, Archiv, Profile, Variablen
    // =================================================================

    private function Notify(string $title, string $text): void
    {
        $id = $this->ReadPropertyInteger('NotifyInstance');
        if ($id <= 0 || !@IPS_InstanceExists($id)) {
            return;
        }

        $prefix = IPS_GetModule(IPS_GetInstance($id)['ModuleInfo']['ModuleID'])['Prefix'] ?? '';

        try {
            if ($prefix === 'VISU' && function_exists('VISU_PostNotification')) {
                VISU_PostNotification($id, $title, $text, 'Electricity', 0);
            } elseif ($prefix === 'WFC' && function_exists('WFC_PushNotification')) {
                WFC_PushNotification($id, $title, $text, '', 0);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Push', 'Fehlgeschlagen: ' . $e->getMessage(), 0);
        }
    }

    private function EnableArchiveLogging(string $ident): void
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        $varId = @$this->GetIDForIdent($ident);
        if (count($archives) === 0 || $varId === false) {
            return;
        }

        if (!AC_GetLoggingStatus($archives[0], $varId)) {
            AC_SetLoggingStatus($archives[0], $varId, true);
            IPS_ApplyChanges($archives[0]);
        }
    }

    private function CreateProfiles(): void
    {
        $this->RegisterProfile('EaseeWB.kW', VARIABLETYPE_FLOAT, ' kW', 2, 'Electricity');
        $this->RegisterProfile('EaseeWB.kWh', VARIABLETYPE_FLOAT, ' kWh', 2, 'Electricity');
        $this->RegisterProfile('EaseeWB.EUR', VARIABLETYPE_FLOAT, ' €', 2, 'Euro');
        $this->RegisterProfile('EaseeWB.Price', VARIABLETYPE_FLOAT, ' €/kWh', 4, 'Euro', 0, 2, 0.01);
        $this->RegisterProfile('EaseeWB.dBm', VARIABLETYPE_INTEGER, ' dBm', 0, 'Intensity');

        $this->RegisterProfile('EaseeWB.OpMode', VARIABLETYPE_INTEGER, '', 0, 'Car', 0, 0, 0, [
            [0, 'Offline', '', 0x555555],
            [1, 'Kein Fahrzeug verbunden', '', 0x95A5A6],
            [2, 'Wartet auf Start', '', 0xF1C40F],
            [3, 'Lädt', '', 0x2ECC71],
            [4, 'Ladung beendet', '', 0x3498DB],
            [5, 'Fehler', '', 0xE74C3C],
            [6, 'Bereit zum Laden', '', 0x3498DB],
            [7, 'Wartet auf Freigabe', '', 0xE67E22],
            [8, 'Abmeldung läuft', '', 0x7F8C8D]
        ]);
    }

    private function RegisterProfile(
        string $name,
        int $type,
        string $suffix,
        int $digits,
        string $icon,
        float $min = 0,
        float $max = 0,
        float $step = 0,
        array $associations = []
    ): void {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileIcon($name, $icon);
        if ($type === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, $digits);
        }
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        foreach ($associations as [$value, $text, $aIcon, $color]) {
            IPS_SetVariableProfileAssociation($name, $value, $text, $aIcon, $color);
        }
    }

    private function CreateVariables(): void
    {
        $this->RegisterVariableInteger('Status', 'Status', 'EaseeWB.OpMode', 1);
        $this->RegisterVariableBoolean('ChargingActive', 'Laden', '~Switch', 2);
        $this->RegisterVariableFloat('Power', 'Ladeleistung', 'EaseeWB.kW', 3);
        $this->RegisterVariableFloat('Current', 'Ladestrom', '~Ampere', 4);
        $this->RegisterVariableInteger('PhaseCount', 'Phasen', '', 5);
        $this->RegisterVariableFloat('CurrentL1', 'Strom L1', '~Ampere', 6);
        $this->RegisterVariableFloat('CurrentL2', 'Strom L2', '~Ampere', 7);
        $this->RegisterVariableFloat('CurrentL3', 'Strom L3', '~Ampere', 8);

        $this->RegisterVariableFloat('SessionEnergy', 'Session Energie', 'EaseeWB.kWh', 10);
        $this->RegisterVariableFloat('SessionCost', 'Session Kosten', 'EaseeWB.EUR', 11);
        $this->RegisterVariableFloat('LifetimeEnergy', 'Gesamtenergie', 'EaseeWB.kWh', 12);
        $this->RegisterVariableFloat('LifetimeCost', 'Gesamtkosten (geschätzt)', 'EaseeWB.EUR', 13);
        $this->RegisterVariableFloat('EnergyPrice', 'Strompreis', 'EaseeWB.Price', 14);

        $this->RegisterVariableBoolean('VehicleConnected', 'Fahrzeug verbunden', '~Switch', 20);
        $this->RegisterVariableBoolean('CableLocked', 'Kabel verriegelt', '~Switch', 21);
        $this->RegisterVariableBoolean('CableLockPermanent', 'Kabel dauerhaft verriegelt', '~Switch', 22);
        $this->RegisterVariableBoolean('SmartCharging', 'Smart Charging', '~Switch', 23);

        $this->RegisterVariableBoolean('Online', 'Online', '~Switch', 30);
        $this->RegisterVariableInteger('WiFiRSSI', 'WLAN Signal', 'EaseeWB.dBm', 31);
        $this->RegisterVariableString('Firmware', 'Firmware', '', 32);
        $this->RegisterVariableString('Reason', 'Grund für keinen Strom', '', 33);
        $this->RegisterVariableInteger('ErrorCode', 'Fehlercode', '', 34);
        $this->RegisterVariableString('ErrorText', 'Fehlertext', '', 35);

        $this->RegisterVariableBoolean('ApiOk', 'API OK', '~Switch', 40);
        $this->RegisterVariableInteger('LastUpdate', 'Letztes Update', '~UnixTimestamp', 41);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 42);

        $this->MaintainVariable('Dashboard', 'Dashboard', VARIABLETYPE_STRING, '~HTMLBox', 50, $this->ReadPropertyBoolean('Dashboard'));

        $this->EnableAction('ChargingActive');
        $this->EnableAction('CableLockPermanent');
        $this->EnableAction('EnergyPrice');

        if ((float) $this->GetValue('EnergyPrice') <= 0) {
            $this->SetValue('EnergyPrice', 0.30);
        }
    }

    // =================================================================
    // Texte
    // =================================================================

    private static function ToBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value != 0;
        }
        return strtolower((string) $value) === 'true';
    }

    private static function PhaseCount(int $outputPhase): int
    {
        if ($outputPhase >= 10 && $outputPhase <= 15) {
            return 1;
        }
        if ($outputPhase >= 20 && $outputPhase <= 22) {
            return 2;
        }
        return $outputPhase === 30 ? 3 : 0;
    }

    private static function ReasonText($reason): string
    {
        if ($reason === null) {
            return '-';
        }

        $texts = [
            0  => 'Kein Problem',
            1  => 'Lastmanagement begrenzt Strom',
            5  => 'Ladewarteschlange aktiv',
            50 => 'Kein Fahrzeug angeschlossen',
            51 => 'Minimalstrom nicht erreicht',
            55 => 'Laden durch Zeitplan gesperrt',
            56 => 'Laden pausiert',
            57 => 'Cloud-Steuerung begrenzt Laden',
            75 => 'RFID-Freigabe erforderlich'
        ];

        return $texts[(int) $reason] ?? ('Code ' . (int) $reason);
    }

    private static function ErrorText(int $code): string
    {
        $texts = [
            0 => 'Kein Fehler',
            1 => 'Erdungsfehler',
            2 => 'Übertemperatur',
            3 => 'Relaisfehler',
            4 => 'Kommunikationsfehler',
            5 => 'Stromfehler',
            6 => 'Spannungsfehler',
            7 => 'Verriegelungsfehler'
        ];

        return $texts[$code] ?? ('Fehlercode ' . $code);
    }
}

class EaseeAuthException extends Exception
{
}
