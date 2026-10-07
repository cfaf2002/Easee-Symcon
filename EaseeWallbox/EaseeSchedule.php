<?php

/**
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

/**
 * Zeitsteuerung:
 *  - "Zeitfenster": Laden nur zwischen Beginn und Ende (auch über Mitternacht)
 *  - "Fertig bis":  eine Ziel-Energie bis zu einer Uhrzeit laden - so spät
 *                   wie möglich starten, mit Sicherheitspuffer
 *
 * Wird "Laden" manuell geschaltet, übersteuert das die Zeitsteuerung, bis
 * das Fahrzeug abgesteckt wird.
 */
trait EaseeSchedule
{
    private function RegisterScheduleAttributes(): void
    {
        $this->RegisterAttributeInteger('ManualOverride', 0);
        $this->RegisterAttributeInteger('ScheduleWanted', -1);
        $this->RegisterAttributeInteger('ScheduleRetryAt', 0);
        $this->RegisterAttributeInteger('Deadline', 0);
        $this->RegisterAttributeFloat('DeadlineBaseEnergy', 0);
    }

    private function CreateScheduleVariables(): void
    {
        $keep = $this->ReadPropertyBoolean('EnableSchedule');

        $time = self::PDateTime(0, 1);
        $this->MaintainVariable('ScheduleMode', 'Zeitsteuerung', VARIABLETYPE_INTEGER, self::PEnum([
            [0, 'Aus', 'power-off', -1],
            [1, 'Zeitfenster', 'clock', 0x3498DB],
            [2, 'Fertig bis', 'flag-checkered', 0x2ECC71]
        ], 'clock', true), 60, $keep);
        $this->MaintainVariable('ScheduleStart', 'Zeitfenster Beginn', VARIABLETYPE_INTEGER, $time, 61, $keep);
        $this->MaintainVariable('ScheduleEnd', 'Zeitfenster Ende', VARIABLETYPE_INTEGER, $time, 62, $keep);
        $this->MaintainVariable('ReadyBy', 'Fertig bis', VARIABLETYPE_INTEGER, $time, 63, $keep);
        $this->MaintainVariable('TargetEnergy', 'Ziel-Energie', VARIABLETYPE_FLOAT, self::PSlider(1, 100, 1, ' kWh', 0, 'battery-bolt'), 64, $keep);
        $this->MaintainVariable('TargetSoc', 'Ziel-Akkustand', VARIABLETYPE_INTEGER, self::PSlider(10, 100, 5, ' %', 0, 'battery-full'), 65,
            $keep && $this->ReadPropertyBoolean('EnableSoc') && !$this->IsGuest());
        $this->MaintainVariable('ScheduleInfo', 'Zeitsteuerung Info', VARIABLETYPE_STRING, self::PValue('', 0, 'circle-info'), 66, $keep);

        if (!$keep) {
            return;
        }

        foreach (['ScheduleMode', 'ScheduleStart', 'ScheduleEnd', 'ReadyBy', 'TargetEnergy'] as $ident) {
            $this->EnableAction($ident);
        }
        if (@$this->GetIDForIdent('TargetSoc') !== false) {
            $this->EnableAction('TargetSoc');
            if ((int) $this->GetValue('TargetSoc') === 0) {
                $this->SetValue('TargetSoc', 100);
            }
        }

        // Sinnvolle Startwerte beim ersten Anlegen
        $now = time();
        if ((int) $this->GetValue('ScheduleStart') === 0) {
            $this->SetValue('ScheduleStart', self::TodayAt(22 * 60, $now));
        }
        if ((int) $this->GetValue('ScheduleEnd') === 0) {
            $this->SetValue('ScheduleEnd', self::TodayAt(6 * 60, $now));
        }
        if ((int) $this->GetValue('ReadyBy') === 0) {
            $this->SetValue('ReadyBy', self::TodayAt(7 * 60, $now));
        }
        if ((float) $this->GetValue('TargetEnergy') <= 0) {
            $this->SetValue('TargetEnergy', 20.0);
        }
    }

    private function ScheduleEnabled(): bool
    {
        return $this->ReadPropertyBoolean('EnableSchedule') && @$this->GetIDForIdent('ScheduleMode') !== false;
    }

    private function UpdateScheduleTimer(): void
    {
        $on = $this->ReadPropertyBoolean('Active') && $this->ScheduleEnabled() && (int) $this->GetValue('ScheduleMode') !== 0;
        $this->SetTimerInterval('ScheduleTimer', $on ? 60 * 1000 : 0);
    }

    private function HandleScheduleAction(string $ident, $value): bool
    {
        if (!in_array($ident, ['ScheduleMode', 'ScheduleStart', 'ScheduleEnd', 'ReadyBy', 'TargetEnergy', 'TargetSoc'], true)) {
            return false;
        }

        if (@$this->GetIDForIdent($ident) === false) {
            throw new InvalidArgumentException('Zeitsteuerung ist nicht aktiviert');
        }
        $this->SetValue($ident, self::ValidScheduleValue($ident, $value));

        // Neue Vorgaben -> Plan neu berechnen
        $this->WriteAttributeInteger('ScheduleWanted', -1);
        $this->WriteAttributeInteger('ScheduleRetryAt', 0);

        if ($ident === 'ScheduleMode') {
            $this->WriteAttributeInteger('ManualOverride', 0);
            $this->UpdateScheduleTimer();
        }
        if (in_array($ident, ['ScheduleMode', 'ReadyBy', 'TargetEnergy', 'TargetSoc'], true)) {
            $this->WriteAttributeInteger('Deadline', 0);
        }

        $this->EvaluateSchedule();

        return true;
    }

    /** Werte aus Visualisierung/Skript prüfen und in den gültigen Bereich bringen. */
    private static function ValidScheduleValue(string $ident, mixed $value): int|float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException('Ungültiger Wert für ' . $ident);
        }
        switch ($ident) {
            case 'ScheduleMode':
                $mode = (int) $value;
                if ($mode < 0 || $mode > 2) {
                    throw new InvalidArgumentException('Ungültiger Modus: ' . $mode);
                }
                return $mode;
            case 'TargetEnergy':
                return max(1.0, min(200.0, round((float) $value, 1)));
            case 'TargetSoc':
                return max(10, min(100, (int) $value));
            default:            // Uhrzeiten (Zeitstempel)
                return max(0, (int) $value);
        }
    }

    /** Manuelles Schalten merken - Zeitsteuerung pausiert bis zum Abstecken. */
    private function MarkManualOverride(): void
    {
        if (!$this->ScheduleEnabled() || (int) $this->GetValue('ScheduleMode') === 0) {
            return;
        }
        if (!$this->GetValue('VehicleConnected')) {
            return;
        }

        $this->WriteAttributeInteger('ManualOverride', 1);
        $this->SetScheduleInfo('Manuell übersteuert (bis zum Abstecken)');
    }

    /** Wird jede Minute sowie nach jedem Abruf aufgerufen. */
    private function EvaluateSchedule(bool $refreshViews = true): void
    {
        if (!$this->ScheduleEnabled()) {
            return;
        }

        $mode = (int) $this->GetValue('ScheduleMode');
        $now = time();

        if ($mode === 0) {
            $this->SetScheduleInfo('Aus', $refreshViews);
            return;
        }

        if (!$this->GetValue('VehicleConnected')) {
            // Neues Einstecken = neuer Plan
            $this->WriteAttributeInteger('ManualOverride', 0);
            $this->WriteAttributeInteger('Deadline', 0);
            $this->WriteAttributeInteger('ScheduleWanted', -1);
            $this->WriteAttributeInteger('ScheduleRetryAt', 0);
            $this->SetScheduleInfo('Wartet auf Fahrzeug', $refreshViews);
            return;
        }

        if ($this->ReadAttributeInteger('ManualOverride') === 1) {
            $this->SetScheduleInfo('Manuell übersteuert (bis zum Abstecken)', $refreshViews);
            return;
        }

        if ($mode === 1) {
            [$want, $info] = $this->PlanWindow($now);
        } else {
            [$want, $info] = $this->PlanReadyBy($now);
        }

        $this->SetScheduleInfo($info, $refreshViews);

        $wanted = $want ? 1 : 0;
        if ($wanted === $this->ReadAttributeInteger('ScheduleWanted') || $now < $this->ReadAttributeInteger('ScheduleRetryAt')) {
            return;
        }

        $this->SendDebug('Zeitsteuerung', ($want ? 'Laden freigeben' : 'Laden pausieren') . ' - ' . $info, 0);

        if ($this->SendChargingCommand($want)) {
            $this->WriteAttributeInteger('ScheduleWanted', $wanted);
            $this->WriteAttributeInteger('ScheduleRetryAt', 0);
        } else {
            // Fehlgeschlagen: in 5 Minuten erneut versuchen
            $this->WriteAttributeInteger('ScheduleRetryAt', $now + 300);
        }
    }

    private function PlanWindow(int $now): array
    {
        $start = self::MinuteOfDay((int) $this->GetValue('ScheduleStart'));
        $end = self::MinuteOfDay((int) $this->GetValue('ScheduleEnd'));
        $current = self::MinuteOfDay($now);

        if ($start === $end) {
            return [true, 'Zeitfenster ganztägig'];
        }

        $inside = $start < $end
            ? ($current >= $start && $current < $end)
            : ($current >= $start || $current < $end);

        return $inside
            ? [true, 'Laden erlaubt bis ' . self::ClockText($end)]
            : [false, 'Pause bis ' . self::ClockText($start)];
    }

    private function PlanReadyBy(int $now): array
    {
        $session = (float) $this->GetValue('SessionEnergy');
        $deadline = $this->ReadAttributeInteger('Deadline');

        // Ziel-Zeitpunkt beim Einstecken festlegen (bleibt auch nach Ablauf
        // bestehen, damit ein Rückstand noch aufgeholt wird)
        if ($deadline === 0 || $now > $deadline + 12 * 3600) {
            $deadline = self::NextOccurrence(self::MinuteOfDay((int) $this->GetValue('ReadyBy')), $now);
            $this->WriteAttributeInteger('Deadline', $deadline);
            $this->WriteAttributeFloat('DeadlineBaseEnergy', $session);
        }

        $base = $this->ReadAttributeFloat('DeadlineBaseEnergy');
        if ($session < $base) {
            // Easee hat den Session-Zähler zurückgesetzt
            $base = 0;
            $this->WriteAttributeFloat('DeadlineBaseEnergy', 0);
        }

        $deadlineText = date('H:i', $deadline);
        $soc = $this->CurrentSoc();

        if ($soc !== null && @$this->GetIDForIdent('TargetSoc') !== false) {
            // Ziel in Prozent: benötigte Energie aus Akkugröße, ca. 10 % Ladeverluste
            $targetSoc = (int) $this->GetValue('TargetSoc');
            if ($soc >= $targetSoc) {
                return [false, sprintf('Ziel erreicht (%d %%)', $soc)];
            }
            $need = ($targetSoc - $soc) / 100 * $this->ReadPropertyFloat('BatteryCapacity') / 0.9;
            $goal = sprintf('%d %% → %d %%', $soc, $targetSoc);
        } else {
            $target = (float) $this->GetValue('TargetEnergy');
            $charged = $session - $base;
            $need = $target - $charged;
            if ($need <= 0.05) {
                return [false, sprintf('Ziel erreicht (%s kWh)', self::Num($charged, 1))];
            }
            $goal = self::Num($need, 1) . ' kWh';
        }

        // Ladeleistung schätzen: Stromgrenze x 230 V x Phasen
        $ampere = (int) $this->GetValue('ChargeLimit');
        if ($ampere <= 0) {
            $ampere = $this->MaxAmpere();
        }
        $powerKW = $ampere * 230 * $this->ReadAttributeInteger('LastPhases') / 1000;
        $powerKW = max(1.0, min($powerKW, (float) $this->ReadPropertyInteger('MaxPowerKW')));

        $seconds = (int) ($need / $powerKW * 3600) + $this->ReadPropertyInteger('ScheduleBuffer') * 60;
        $startAt = $deadline - $seconds;

        if ($now >= $startAt) {
            return [true, sprintf('Lädt: %s bis %s', $goal, $deadlineText)];
        }

        return [false, sprintf('Start um %s (%s bis %s)', date('H:i', $startAt), $goal, $deadlineText)];
    }

    private function SetScheduleInfo(string $info, bool $refreshViews = true): void
    {
        if (@$this->GetIDForIdent('ScheduleInfo') === false || $this->GetValue('ScheduleInfo') === $info) {
            return;
        }

        $this->SetValue('ScheduleInfo', $info);
        if ($refreshViews) {
            $this->RefreshViews();
        }
    }

    private static function MinuteOfDay(int $timestamp): int
    {
        return (int) date('G', $timestamp) * 60 + (int) date('i', $timestamp);
    }

    private static function TodayAt(int $minuteOfDay, int $now): int
    {
        return mktime(intdiv($minuteOfDay, 60), $minuteOfDay % 60, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
    }

    private static function NextOccurrence(int $minuteOfDay, int $now): int
    {
        $t = self::TodayAt($minuteOfDay, $now);
        return $t > $now ? $t : strtotime('+1 day', $t);
    }

    private static function ClockText(int $minuteOfDay): string
    {
        return sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }

    private static function Num(float $value, int $digits = 2): string
    {
        return number_format($value, $digits, ',', '.');
    }
}
