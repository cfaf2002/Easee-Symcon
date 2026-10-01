<?php

declare(strict_types=1);

require_once __DIR__ . '/EaseeDashboard.php';
require_once __DIR__ . '/EaseeSchedule.php';
require_once __DIR__ . '/EaseeTile.php';

/**
 * Easee Wallbox
 *
 * Liest den Zustand einer Easee-Wallbox über die Easee Cloud
 * (Observations-API) aus und steuert sie: Laden starten/pausieren,
 * Ladestrom begrenzen, Kabel dauerhaft verriegeln, Zeitsteuerung.
 *
 * Autor: Armin Frohwerk
 */
class EaseeWallbox extends IPSModule
{
    use EaseeDashboard;
    use EaseeSchedule;
    use EaseeTile;

    private const API_HOST = 'https://api.easee.com';

    // Observation-IDs der Easee-API
    private const OBS_CABLE_PERMANENT = 30;
    private const OBS_MAX_CURRENT = 47;
    private const OBS_DYNAMIC_CURRENT = 48;
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
    private const STATS_MONTHS = 36;

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
        $this->RegisterPropertyBoolean('LogEnergy', true);
        $this->RegisterPropertyFloat('EnergyPrice', 0.30);
        $this->RegisterPropertyString('TileBackground', '');
        $this->RegisterPropertyInteger('TileDim', 55);
        $this->RegisterPropertyInteger('TileBlur', 0);
        $this->RegisterPropertyString('CarImage', '');
        $this->RegisterPropertyBoolean('CarMirror', false);
        $this->RegisterPropertyInteger('CarPortX', 20);
        $this->RegisterPropertyInteger('CarPortY', 45);
        $this->RegisterPropertyBoolean('EnableSoc', false);
        $this->RegisterPropertyBoolean('GuestAutoReset', true);
        $this->RegisterPropertyInteger('SocVariable', 0);
        $this->RegisterPropertyFloat('BatteryCapacity', 15.0);
        $this->RegisterPropertyBoolean('EnableSchedule', false);
        $this->RegisterPropertyInteger('ScheduleBuffer', 30);

        // Interne Daten
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('CredentialHash', '');
        $this->RegisterAttributeString('ActiveChargerID', '');
        $this->RegisterAttributeString('ChargerName', '');
        $this->RegisterAttributeInteger('SessionStart', 0);
        $this->RegisterAttributeFloat('SessionStartEnergy', 0);
        $this->RegisterAttributeInteger('PlugConnected', 0);
        $this->RegisterAttributeFloat('PlugLifetime', 0);
        $this->RegisterAttributeFloat('PlugIntegral', 0);
        $this->RegisterAttributeInteger('LastPoll', 0);
        $this->RegisterAttributeInteger('StopPending', 0);
        $this->RegisterAttributeString('History', '[]');
        $this->RegisterAttributeFloat('LastLifetime', 0);
        $this->RegisterAttributeString('Stats', '{}');
        $this->RegisterAttributeString('DayKey', '');
        $this->RegisterAttributeInteger('SocWatched', 0);
        $this->RegisterAttributeInteger('PriceMigrated', 0);
        $this->RegisterAttributeInteger('LastPhases', 3);
        $this->RegisterScheduleAttributes();

        $this->RegisterTimer('UpdateTimer', 0, 'EASEE_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('QuickRefresh', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'QuickRefresh\', true);');
        $this->RegisterTimer('ScheduleTimer', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'ScheduleTick\', true);');

        // Eigene Kachel in der Kachel-Visualisierung (HTML-SDK)
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $this->CreateProfiles();
        $this->CreateVariables();
        $this->CreateScheduleVariables();
        $this->SetupSoc();

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

        // Einmalig: bisher über die Variable gepflegten Strompreis ins Formular übernehmen
        if ($this->ReadAttributeInteger('PriceMigrated') === 0) {
            $this->WriteAttributeInteger('PriceMigrated', 1);
            $old = (float) $this->GetValue('EnergyPrice');
            if ($old > 0 && abs($old - $this->ReadPropertyFloat('EnergyPrice')) > 0.00001) {
                IPS_SetProperty($this->InstanceID, 'EnergyPrice', $old);
                IPS_ApplyChanges($this->InstanceID);
                return;
            }
        }
        $this->ApplyEnergyPrice($this->ReadPropertyFloat('EnergyPrice'));

        // Hintergrundbild/Einstellungen sofort an offene Kacheln schicken
        $this->PushTile(true);

        if ($this->ReadPropertyBoolean('LogPower')) {
            $this->EnableArchiveLogging('Power', 0);
        }
        if ($this->ReadPropertyBoolean('LogEnergy')) {
            // Zähler-Aggregation: Symcon bildet daraus Werte pro Tag/Woche/Monat/Jahr
            $this->EnableArchiveLogging('LifetimeEnergy', 1);
            $this->EnableArchiveLogging('CostCounter', 1);
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetTimerInterval('ScheduleTimer', 0);
            $this->SetStatus(104);
            return;
        }

        if (trim($this->ReadPropertyString('Username')) === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetTimerInterval('ScheduleTimer', 0);
            $this->SetStatus(201);
            return;
        }

        $this->SetTimerInterval('UpdateTimer', max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000);
        $this->UpdateScheduleTimer();
        $this->SetStatus(102);

        // Ersten Abruf kurz nach dem Übernehmen starten (nicht blockierend)
        $this->ScheduleQuickRefresh(3);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }

        // Akkustand des Fahrzeugs hat sich geändert
        if ($Message === VM_UPDATE && $SenderID === $this->ReadAttributeInteger('SocWatched')) {
            $this->UpdateSoc();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'ChargingActive':
                $Value ? $this->StartCharging() : $this->StopCharging();
                break;

            case 'ChargeLimit':
                $this->SetChargeLimit((int) $Value);
                break;

            case 'GuestCharging':
                $this->SetValue('GuestCharging', (bool) $Value);
                $this->UpdateSoc(false);
                $this->EvaluateSchedule(false);
                $this->RefreshViews();
                break;

            case 'CableLockPermanent':
                $this->SetCableLockPermanent((bool) $Value);
                break;

            case 'QuickRefresh':
                $this->SetTimerInterval('QuickRefresh', 0);
                $this->Update();
                break;

            case 'ScheduleTick':
                $this->EvaluateSchedule();
                break;

            default:
                if (!$this->HandleScheduleAction($Ident, $Value)) {
                    throw new Exception('Unbekannte Aktion: ' . $Ident);
                }
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

        if ($ok) {
            $this->EvaluateSchedule(false);
        }

        $this->RefreshViews();

        return $ok;
    }

    /** Laden fortsetzen/starten (manuell - übersteuert die Zeitsteuerung bis zum Abstecken). */
    public function StartCharging(): bool
    {
        $this->MarkManualOverride();
        return $this->SendChargingCommand(true);
    }

    /** Laden pausieren (manuell - übersteuert die Zeitsteuerung bis zum Abstecken). */
    public function StopCharging(): bool
    {
        $this->MarkManualOverride();
        return $this->SendChargingCommand(false);
    }

    /**
     * Ladestrom begrenzen (Ampere je Phase). Wird als "dynamischer"
     * Wert gesetzt: schont den Speicher der Wallbox, gilt aber nur bis
     * zu deren nächstem Neustart.
     */
    public function SetChargeLimit(int $Ampere): bool
    {
        $max = $this->MaxAmpere();
        $Ampere = max(6, min($max, $Ampere));

        return $this->RunApi(
            'settings',
            ['dynamicChargerCurrent' => $Ampere],
            function () use ($Ampere) {
                $this->SetValue('ChargeLimit', $Ampere);
            }
        );
    }

    /** Kabel dauerhaft verriegeln (true) oder freigeben (false). */
    public function SetCableLockPermanent(bool $State): bool
    {
        return $this->RunApi('commands/lock_state', ['state' => $State], function () use ($State) {
            $this->SetValue('CableLockPermanent', $State);
        });
    }

    /**
     * Strompreis in €/kWh setzen (entspricht dem Feld im Instanz-Formular).
     * Gilt ab sofort; bereits erfasste Tages-/Monatskosten bleiben unverändert.
     */
    public function SetEnergyPrice(float $Price): void
    {
        IPS_SetProperty($this->InstanceID, 'EnergyPrice', max(0.0, $Price));
        IPS_ApplyChanges($this->InstanceID);
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
        $this->RefreshViews();
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
        $this->RefreshViews();

        return count($history);
    }

    /** Monatsstatistik als JSON: {"2026-09": {"energy": kWh, "cost": €}, ...} */
    public function GetStatistics(): string
    {
        $out = [];
        foreach ($this->ReadStats() as $month => $s) {
            $out[$month] = ['energy' => round($s['e'], 2), 'cost' => round($s['c'], 2)];
        }
        return json_encode($out);
    }

    /** Monats-/Jahresstatistik löschen. */
    public function ResetStatistics(): void
    {
        $this->WriteAttributeString('Stats', '{}');
        $this->UpdateStatVariables();
        $this->RefreshViews();
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
        $phaseCount = self::PhaseCount($outputPhase);

        $vehicleConnected = in_array($opMode, [2, 3, 4, 6, 7], true);

        // Easee aktualisiert den Session-Zähler in der Cloud nur verzögert -
        // deshalb zusätzlich selbst mitrechnen und den größten Wert nehmen
        $sessionEnergy = $this->EstimateSessionEnergy($sessionEnergy, $lifetimeEnergy, $power, $vehicleConnected);

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
                // Easee zählt die Session-Energie ab dem Einstecken; nach einer
                // Pause wird nur der neue Anteil dieser Ladung gewertet
                $this->WriteAttributeFloat('SessionStartEnergy', $sessionEnergy);
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
                $this->FinishSession($sessionEnergy, $price);
            }
        }

        $this->WriteAttributeInteger('StopPending', $pending);

        // --- Monats-/Jahresstatistik über den Zählerstand ---
        $this->AccumulateStats($lifetimeEnergy, $price);

        // --- Fehler neu aufgetreten? ---
        $wasError = (int) $this->GetValue('ErrorCode') !== 0;
        if ($errorCode !== 0 && !$wasError && $this->ReadPropertyBoolean('NotifyError')) {
            $this->Notify('⚠️ Easee Wallbox', self::ErrorText($errorCode));
        }

        if ($phaseCount > 0) {
            $this->WriteAttributeInteger('LastPhases', $phaseCount);
        }

        // Ladestrom-Grenze: dynamischer Wert, sonst der feste Maximalwert
        $limit = (float) ($v[self::OBS_DYNAMIC_CURRENT] ?? 0);
        if ($limit <= 0) {
            $limit = (float) ($v[self::OBS_MAX_CURRENT] ?? 0);
        }

        // --- Variablen schreiben ---
        $this->SetValue('Status', $opMode);
        $this->SetValue('ChargingActive', $isCharging);
        $this->SetValue('Power', round($power, 2));
        $this->SetValue('Current', round($current, 1));
        if ($limit > 0) {
            $this->SetValue('ChargeLimit', (int) round($limit));
        }
        $this->SetValue('PhaseCount', $phaseCount);
        $this->SetValue('CurrentL1', round((float) ($v[self::OBS_CURRENT_L1] ?? 0), 1));
        $this->SetValue('CurrentL2', round((float) ($v[self::OBS_CURRENT_L2] ?? 0), 1));
        $this->SetValue('CurrentL3', round((float) ($v[self::OBS_CURRENT_L3] ?? 0), 1));
        $this->SetValue('SessionEnergy', round($sessionEnergy, 2));
        $this->SetValue('LifetimeEnergy', round($lifetimeEnergy, 2));
        $this->SetValue('SessionCost', round($sessionEnergy * $price, 2));
        $this->SetValue('LifetimeCost', round($lifetimeEnergy * $price, 2));
        // Gastladung endet mit dem Abstecken -> wieder eigenes Auto
        if ($this->ReadPropertyBoolean('GuestAutoReset') && !$vehicleConnected
            && (bool) $this->GetValue('VehicleConnected') && (bool) $this->GetValue('GuestCharging')) {
            $this->SetValue('GuestCharging', false);
            $this->SendDebug('Gastladung', 'Fahrzeug abgesteckt -> eigenes Auto', 0);
        }
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

    /**
     * Geladene Energie seit dem Einstecken: Maximum aus
     *  - Session-Zähler der Easee-Cloud (verzögert),
     *  - Differenz des Gesamtzählers seit dem Einstecken,
     *  - aufsummierter Ladeleistung zwischen den Abrufen.
     */
    private function EstimateSessionEnergy(float $cloud, float $lifetime, float $powerKW, bool $connected): float
    {
        $now = time();
        $last = $this->ReadAttributeInteger('LastPoll');
        $this->WriteAttributeInteger('LastPoll', $now);

        if (!$connected) {
            $this->WriteAttributeInteger('PlugConnected', 0);
            $this->WriteAttributeFloat('PlugIntegral', 0);
            return $cloud;
        }

        if ($this->ReadAttributeInteger('PlugConnected') === 0) {
            // Gerade eingesteckt (oder Modul neu gestartet)
            $this->WriteAttributeInteger('PlugConnected', 1);
            $this->WriteAttributeFloat('PlugLifetime', $lifetime);
            $this->WriteAttributeFloat('PlugIntegral', 0);
        } elseif ($last > 0 && $powerKW > 0) {
            $seconds = min(900, max(0, $now - $last));
            $this->WriteAttributeFloat('PlugIntegral', $this->ReadAttributeFloat('PlugIntegral') + $powerKW * $seconds / 3600);
        }

        $plugLifetime = $this->ReadAttributeFloat('PlugLifetime');
        $fromCounter = ($lifetime > 0 && $plugLifetime > 0) ? max(0.0, $lifetime - $plugLifetime) : 0.0;
        if ($fromCounter > 150 || $lifetime < $plugLifetime) {
            // Unplausibler Sprung (Zähler getauscht o. Ä.) -> neu aufsetzen
            $this->WriteAttributeFloat('PlugLifetime', $lifetime);
            $fromCounter = 0.0;
        }

        return max($cloud, $fromCounter, $this->ReadAttributeFloat('PlugIntegral'));
    }

    private function FinishSession(float $sessionEnergy, float $price): void
    {
        $start = $this->ReadAttributeInteger('SessionStart');
        $base = $this->ReadAttributeFloat('SessionStartEnergy');
        $energy = ($base > 0 && $sessionEnergy >= $base) ? $sessionEnergy - $base : $sessionEnergy;
        $cost = round($energy * $price, 2);
        $end = time();

        if ($start > 0) {
            $history = json_decode($this->ReadAttributeString('History'), true) ?: [];
            $history[] = [
                'start'    => $start,
                'end'      => $end,
                'duration' => max(0, $end - $start),
                'energy'   => round($energy, 2),
                'cost'     => $cost
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

    // =================================================================
    // Statistik
    // =================================================================

    /** Preis aus dem Formular in die Anzeige-Variable übernehmen und Kosten neu berechnen. */
    private function ApplyEnergyPrice(float $price): void
    {
        $price = max(0.0, $price);
        if (abs((float) $this->GetValue('EnergyPrice') - $price) < 0.00001) {
            return;
        }

        $this->SetValue('EnergyPrice', $price);
        $this->SetValue('SessionCost', round($this->GetValue('SessionEnergy') * $price, 2));
        $this->SetValue('LifetimeCost', round($this->GetValue('LifetimeEnergy') * $price, 2));
        $this->RefreshViews();
    }

    // =================================================================
    // Akkustand des Fahrzeugs (optional, aus einer beliebigen Variable)
    // =================================================================

    private function SocEnabled(): bool
    {
        $id = $this->ReadPropertyInteger('SocVariable');
        return $this->ReadPropertyBoolean('EnableSoc') && $id > 0 && @IPS_VariableExists($id);
    }

    /** Variable anlegen/entfernen und Änderungen der Quellvariable abonnieren. */
    private function SetupSoc(): void
    {
        $enabled = $this->ReadPropertyBoolean('EnableSoc');
        $this->MaintainVariable('SoC', 'Akkustand Fahrzeug', VARIABLETYPE_INTEGER, 'EaseeWB.Percent', 5, $enabled);

        $old = $this->ReadAttributeInteger('SocWatched');
        $new = $this->SocEnabled() ? $this->ReadPropertyInteger('SocVariable') : 0;

        if ($old > 0 && $old !== $new) {
            $this->UnregisterMessage($old, VM_UPDATE);
        }
        if ($new > 0 && $old !== $new) {
            $this->RegisterMessage($new, VM_UPDATE);
        }
        $this->WriteAttributeInteger('SocWatched', $new);

        if ($new > 0) {
            $this->UpdateSoc(false);
        }
    }

    /** Aktuellen Akkustand in Prozent (null = nicht verfügbar). */
    private function CurrentSoc(): ?int
    {
        // Bei einer Gastladung gehört der Akkustand nicht zum angesteckten Auto
        if (!$this->SocEnabled() || $this->IsGuest()) {
            return null;
        }

        $value = GetValue($this->ReadPropertyInteger('SocVariable'));
        if (!is_numeric($value)) {
            return null;
        }

        return (int) round(max(0, min(100, (float) $value)));
    }

    private function IsGuest(): bool
    {
        return @$this->GetIDForIdent('GuestCharging') !== false && (bool) $this->GetValue('GuestCharging');
    }

    /** Gastladung ein-/ausschalten (true = fremdes Auto an der Wallbox). */
    public function SetGuestCharging(bool $Guest): void
    {
        $this->RequestAction('GuestCharging', $Guest);
    }

    private function UpdateSoc(bool $refresh = true): void
    {
        $soc = $this->CurrentSoc();
        if ($soc === null || @$this->GetIDForIdent('SoC') === false) {
            return;
        }

        $this->SetValue('SoC', $soc);

        if ($refresh) {
            $this->EvaluateSchedule(false);
            $this->RefreshViews();
        }
    }

    /** Um Mitternacht "heute" auf 0 setzen. */
    private function RollDay(): void
    {
        $today = date('Y-m-d');
        if ($this->ReadAttributeString('DayKey') === $today) {
            return;
        }

        $this->WriteAttributeString('DayKey', $today);
        $this->SetValue('EnergyToday', 0.0);
        $this->SetValue('CostToday', 0.0);
    }

    private function AccumulateStats(float $lifetimeEnergy, float $price): void
    {
        $this->RollDay();

        $last = $this->ReadAttributeFloat('LastLifetime');
        $this->WriteAttributeFloat('LastLifetime', $lifetimeEnergy);

        $delta = $lifetimeEnergy - $last;

        // Erster Abruf oder unplausibler Sprung (Zähler getauscht) -> nicht werten
        if ($last <= 0 || $delta <= 0 || $delta > 200) {
            $this->UpdateStatVariables();
            return;
        }

        // Tageswerte und laufender Kostenzähler (fürs Archiv)
        $this->SetValue('EnergyToday', round((float) $this->GetValue('EnergyToday') + $delta, 3));
        $this->SetValue('CostToday', round((float) $this->GetValue('CostToday') + $delta * $price, 4));
        $this->SetValue('CostCounter', round((float) $this->GetValue('CostCounter') + $delta * $price, 4));

        $stats = $this->ReadStats();
        $month = date('Y-m');
        $stats[$month] = [
            'e' => ($stats[$month]['e'] ?? 0) + $delta,
            'c' => ($stats[$month]['c'] ?? 0) + $delta * $price
        ];

        ksort($stats);
        $stats = array_slice($stats, -self::STATS_MONTHS, null, true);
        $this->WriteAttributeString('Stats', json_encode($stats));

        $this->UpdateStatVariables();
    }

    private function ReadStats(): array
    {
        $stats = json_decode($this->ReadAttributeString('Stats'), true);
        return is_array($stats) ? $stats : [];
    }

    private function UpdateStatVariables(): void
    {
        $stats = $this->ReadStats();
        $month = $stats[date('Y-m')] ?? ['e' => 0, 'c' => 0];

        $yearE = 0.0;
        $yearC = 0.0;
        foreach ($stats as $key => $s) {
            if (strpos($key, date('Y') . '-') === 0) {
                $yearE += $s['e'];
                $yearC += $s['c'];
            }
        }

        $this->SetValue('EnergyMonth', round($month['e'], 2));
        $this->SetValue('CostMonth', round($month['c'], 2));
        $this->SetValue('EnergyYear', round($yearE, 2));
        $this->SetValue('CostYear', round($yearC, 2));
    }

    // =================================================================
    // Befehle
    // =================================================================

    /** Laden fortsetzen (true) oder pausieren (false) - ohne Übersteuerungs-Logik. */
    private function SendChargingCommand(bool $start): bool
    {
        if ($start) {
            return $this->RunApi('commands/resume_charging', null, function () {
                $this->WriteAttributeInteger('StopPending', 0);
            });
        }

        return $this->RunApi('commands/pause_charging', null, function () {
            // Nächster Abruf ohne Strom beendet die Session sofort
            if ($this->GetValue('ChargingActive')) {
                $this->WriteAttributeInteger('StopPending', self::STOP_CONFIRM_CYCLES - 1);
            }
        });
    }

    /** POST an /api/chargers/{id}/{path} mit anschließender Aktualisierung. */
    private function RunApi(string $path, ?array $body, callable $onSuccess): bool
    {
        try {
            $chargerId = $this->GetChargerId();
            $this->Api('POST', '/api/chargers/' . rawurlencode($chargerId) . '/' . $path, $body);
            $onSuccess();
            $this->ReportSuccess();
            $this->SendDebug('Befehl', $path . ' ' . json_encode($body), 0);
        } catch (Exception $e) {
            $this->ReportError($e);
            echo 'Fehler: ' . $e->getMessage();
            return false;
        }

        // Wallbox braucht einen Moment - Zustand in 15 s neu holen
        $this->ScheduleQuickRefresh(15);
        $this->RefreshViews();

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

    /** Dashboard-Variable und Kachel neu aufbauen. */
    private function RefreshViews(): void
    {
        if ($this->ReadPropertyBoolean('Dashboard') && @$this->GetIDForIdent('Dashboard') !== false) {
            $this->SetValue('Dashboard', $this->BuildDashboard());
        }
        $this->PushTile();
    }

    private function MaxAmpere(): int
    {
        return $this->ReadPropertyInteger('MaxPowerKW') >= 22 ? 32 : 16;
    }

    // =================================================================
    // Easee API
    // =================================================================

    private function FetchObservations(): array
    {
        $chargerId = $this->GetChargerId();

        $ids = implode(',', [
            self::OBS_CABLE_PERMANENT, self::OBS_MAX_CURRENT, self::OBS_DYNAMIC_CURRENT,
            self::OBS_CURRENT_L1, self::OBS_CURRENT_L2, self::OBS_CURRENT_L3,
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

    /** @param int $aggregation 0 = Standard, 1 = Zähler */
    private function EnableArchiveLogging(string $ident, int $aggregation): void
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        $varId = @$this->GetIDForIdent($ident);
        if (count($archives) === 0 || $varId === false) {
            return;
        }

        $archive = $archives[0];
        $changed = false;

        if (!AC_GetLoggingStatus($archive, $varId)) {
            AC_SetLoggingStatus($archive, $varId, true);
            $changed = true;
        }
        if (AC_GetAggregationType($archive, $varId) !== $aggregation) {
            AC_SetAggregationType($archive, $varId, $aggregation);
            $changed = true;
        }
        if ($changed) {
            IPS_ApplyChanges($archive);
        }
    }

    private function CreateProfiles(): void
    {
        $this->RegisterProfile('EaseeWB.kW', VARIABLETYPE_FLOAT, ' kW', 2, 'Electricity');
        $this->RegisterProfile('EaseeWB.kWh', VARIABLETYPE_FLOAT, ' kWh', 2, 'Electricity');
        $this->RegisterProfile('EaseeWB.EUR', VARIABLETYPE_FLOAT, ' €', 2, 'Euro');
        $this->RegisterProfile('EaseeWB.Price', VARIABLETYPE_FLOAT, ' €/kWh', 4, 'Euro', 0, 2, 0.01);
        $this->RegisterProfile('EaseeWB.dBm', VARIABLETYPE_INTEGER, ' dBm', 0, 'Intensity');
        $this->RegisterProfile('EaseeWB.Percent', VARIABLETYPE_INTEGER, ' %', 0, 'Battery', 0, 100, 1);
        $this->RegisterProfile('EaseeWB.Ampere', VARIABLETYPE_INTEGER, ' A', 0, 'Electricity', 6, $this->MaxAmpere(), 1);
        $this->RegisterProfile('EaseeWB.Target', VARIABLETYPE_FLOAT, ' kWh', 0, 'Battery', 1, 100, 1);

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

        $this->RegisterProfile('EaseeWB.Guest', VARIABLETYPE_BOOLEAN, '', 0, 'Car', 0, 0, 0, [
            [false, 'Eigenes Auto', '', 0x2ECC71],
            [true, 'Gastladung', '', 0xF39C12]
        ]);

        $this->RegisterProfile('EaseeWB.Schedule', VARIABLETYPE_INTEGER, '', 0, 'Clock', 0, 2, 0, [
            [0, 'Aus', '', -1],
            [1, 'Zeitfenster', '', 0x3498DB],
            [2, 'Fertig bis', '', 0x2ECC71]
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
        $this->RegisterVariableInteger('ChargeLimit', 'Ladestrom-Grenze', 'EaseeWB.Ampere', 5);
        $this->RegisterVariableInteger('PhaseCount', 'Phasen', '', 6);
        $this->RegisterVariableFloat('CurrentL1', 'Strom L1', '~Ampere', 7);
        $this->RegisterVariableFloat('CurrentL2', 'Strom L2', '~Ampere', 8);
        $this->RegisterVariableFloat('CurrentL3', 'Strom L3', '~Ampere', 9);

        $this->RegisterVariableFloat('SessionEnergy', 'Session Energie', 'EaseeWB.kWh', 10);
        $this->RegisterVariableFloat('SessionCost', 'Session Kosten', 'EaseeWB.EUR', 11);
        $this->RegisterVariableFloat('LifetimeEnergy', 'Gesamtenergie', 'EaseeWB.kWh', 12);
        $this->RegisterVariableFloat('LifetimeCost', 'Gesamtkosten (geschätzt)', 'EaseeWB.EUR', 13);
        $this->RegisterVariableFloat('EnergyPrice', 'Strompreis', 'EaseeWB.Price', 14);
        $this->RegisterVariableFloat('EnergyToday', 'Energie heute', 'EaseeWB.kWh', 15);
        $this->RegisterVariableFloat('CostToday', 'Kosten heute', 'EaseeWB.EUR', 16);
        $this->RegisterVariableFloat('EnergyMonth', 'Energie dieser Monat', 'EaseeWB.kWh', 17);
        $this->RegisterVariableFloat('CostMonth', 'Kosten dieser Monat', 'EaseeWB.EUR', 18);
        $this->RegisterVariableFloat('EnergyYear', 'Energie dieses Jahr', 'EaseeWB.kWh', 19);
        $this->RegisterVariableFloat('CostYear', 'Kosten dieses Jahr', 'EaseeWB.EUR', 20);
        $this->RegisterVariableFloat('CostCounter', 'Kosten gesamt (seit Installation)', 'EaseeWB.EUR', 21);

        $this->RegisterVariableBoolean('VehicleConnected', 'Fahrzeug verbunden', '~Switch', 20);
        $this->RegisterVariableBoolean('GuestCharging', 'Angestecktes Fahrzeug', 'EaseeWB.Guest', 24);
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
        $this->EnableAction('ChargeLimit');
        $this->EnableAction('CableLockPermanent');
        $this->EnableAction('GuestCharging');
        // Strompreis wird im Instanz-Formular gepflegt
        $this->DisableAction('EnergyPrice');
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
