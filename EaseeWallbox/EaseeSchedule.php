<?php

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

        $this->MaintainVariable('ScheduleMode', 'Zeitsteuerung', VARIABLETYPE_INTEGER, 'EaseeWB.Schedule', 60, $keep);
        $this->MaintainVariable('ScheduleStart', 'Zeitfenster Beginn', VARIABLETYPE_INTEGER, '~UnixTimestampTime', 61, $keep);
        $this->MaintainVariable('ScheduleEnd', 'Zeitfenster Ende', VARIABLETYPE_INTEGER, '~UnixTimestampTime', 62, $keep);
        $this->MaintainVariable('ReadyBy', 'Fertig bis', VARIABLETYPE_INTEGER, '~UnixTimestampTime', 63, $keep);
        $this->MaintainVariable('TargetEnergy', 'Ziel-Energie', VARIABLETYPE_FLOAT, 'EaseeWB.Target', 64, $keep);
        $this->MaintainVariable('ScheduleInfo', 'Zeitsteuerung Info', VARIABLETYPE_STRING, '', 65, $keep);

        if (!$keep) {
            return;
        }

        foreach (['ScheduleMode', 'ScheduleStart', 'ScheduleEnd', 'ReadyBy', 'TargetEnergy'] as $ident) {
            $this->EnableAction($ident);
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
        if (!in_array($ident, ['ScheduleMode', 'ScheduleStart', 'ScheduleEnd', 'ReadyBy', 'TargetEnergy'], true)) {
            return false;
        }

        $this->SetValue($ident, $value);

        // Neue Vorgaben -> Plan neu berechnen
        $this->WriteAttributeInteger('ScheduleWanted', -1);
        $this->WriteAttributeInteger('ScheduleRetryAt', 0);

        if ($ident === 'ScheduleMode') {
            $this->WriteAttributeInteger('ManualOverride', 0);
            $this->UpdateScheduleTimer();
        }
        if (in_array($ident, ['ScheduleMode', 'ReadyBy', 'TargetEnergy'], true)) {
            $this->WriteAttributeInteger('Deadline', 0);
        }

        $this->EvaluateSchedule();

        return true;
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

        $target = (float) $this->GetValue('TargetEnergy');
        $charged = $session - $base;
        $need = $target - $charged;
        $deadlineText = date('H:i', $deadline);

        if ($need <= 0.05) {
            return [false, sprintf('Ziel erreicht (%s kWh)', self::Num($charged, 1))];
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
            return [true, sprintf('Lädt: noch %s kWh bis %s', self::Num($need, 1), $deadlineText)];
        }

        return [false, sprintf('Start um %s (%s kWh bis %s)', date('H:i', $startAt), self::Num($need, 1), $deadlineText)];
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
