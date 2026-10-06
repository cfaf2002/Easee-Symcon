<?php

/**
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

/**
 * Lade-Erinnerung am Abend:
 * Steht das eigene Auto ab der eingestellten Uhrzeit (Standard 21:00) am Ort
 * der Location Control, ist aber nicht an der Wallbox angesteckt, gibt es
 * einmal pro Abend eine Meldung in der Visualisierung und einen Hinweis in der Kachel.
 *
 * "Zu Hause" kommt wahlweise aus einer Ja/Nein-Variable (z. B. „Zu Hause“ der
 * Volvo-Instanz) oder aus Breiten-/Längengrad-Variablen im Vergleich zum
 * Standort der Location Control.
 */
trait EaseeReminder
{
    private const TILE_VISU_GUID = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    private const REMINDER_END_HOUR = 6;        // die Abend-Erinnerung gilt bis 6 Uhr morgens

    private function RegisterReminderProperties(): void
    {
        $this->RegisterPropertyBoolean('ReminderEnabled', false);
        $this->RegisterPropertyString('ReminderTime', '{"hour":21,"minute":0,"second":0}');
        $this->RegisterPropertyInteger('ReminderSource', 0);    // 0 = Variable „zu Hause“, 1 = Breite/Länge
        $this->RegisterPropertyInteger('ReminderHomeVariable', 0);
        $this->RegisterPropertyInteger('ReminderLatVariable', 0);
        $this->RegisterPropertyInteger('ReminderLonVariable', 0);
        $this->RegisterPropertyInteger('ReminderRadius', 150);
        $this->RegisterPropertyInteger('ReminderDelay', 10);    // Minuten nach der Ankunft Zeit zum Anstecken
        $this->RegisterPropertyInteger('ReminderSocBelow', 100);

        $this->RegisterAttributeInteger('ReminderArrived', 0);
        $this->RegisterAttributeString('ReminderSent', '');
        $this->RegisterAttributeString('ReminderWatched', '[]');
    }

    /** Variable anlegen, Quellvariablen beobachten und als Referenz eintragen. */
    private function SetupReminder(): void
    {
        $active = $this->ReminderActive();
        $this->MaintainVariable('ChargeReminder', 'Lade-Erinnerung', VARIABLETYPE_BOOLEAN, self::PBool(
            'OK', 'Bitte anstecken', 'plug-circle-exclamation', 0xE67E22
        ), 25, $active);

        $old = json_decode($this->ReadAttributeString('ReminderWatched'), true) ?: [];
        $new = $active ? $this->ReminderSources() : [];
        foreach ($old as $id) {
            if (!in_array($id, $new, true)) {
                $this->UnregisterMessage((int) $id, VM_UPDATE);
                $this->UnregisterReference((int) $id);
            }
        }
        // Anmeldungen überleben keinen Neustart von Symcon -> immer neu anmelden
        foreach ($new as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
            $this->RegisterReference($id);
        }
        $this->WriteAttributeString('ReminderWatched', json_encode($new));
    }

    private function ReminderActive(): bool
    {
        return $this->ReadPropertyBoolean('ReminderEnabled') && !$this->IsGuest() && count($this->ReminderSources()) > 0;
    }

    /** @return int[] gültige Quellvariablen je nach Einstellung */
    private function ReminderSources(): array
    {
        $ids = $this->ReadPropertyInteger('ReminderSource') === 1
            ? [$this->ReadPropertyInteger('ReminderLatVariable'), $this->ReadPropertyInteger('ReminderLonVariable')]
            : [$this->ReadPropertyInteger('ReminderHomeVariable')];
        foreach ($ids as $id) {
            if ($id <= 0 || !@IPS_VariableExists($id)) {
                return [];
            }
        }
        return $ids;
    }

    private function IsReminderSource(int $senderId): bool
    {
        return in_array($senderId, json_decode($this->ReadAttributeString('ReminderWatched'), true) ?: [], true);
    }

    /** Steht das Auto zu Hause? null = unbekannt (dann keine Erinnerung). */
    private function VehicleAtHome(): ?bool
    {
        $sources = $this->ReminderSources();
        if (count($sources) === 0) {
            return null;
        }
        if ($this->ReadPropertyInteger('ReminderSource') === 0) {
            return (bool) GetValue($sources[0]);
        }

        $lat = (float) GetValue($sources[0]);
        $lon = (float) GetValue($sources[1]);
        $home = $this->HomeLocation();
        if ($home === null || ($lat == 0.0 && $lon == 0.0)) {
            return null;
        }
        return self::Meters($lat, $lon, $home[0], $home[1]) <= max(20, $this->ReadPropertyInteger('ReminderRadius'));
    }

    /** @return array{0:float,1:float}|null Standort aus Kern-Instanzen → Location Control */
    private function HomeLocation(): ?array
    {
        $ids = IPS_GetInstanceListByModuleID('{45E97A63-F870-408A-B259-2933F7EABF74}');
        if (count($ids) === 0) {
            return null;
        }
        $location = json_decode((string) @IPS_GetProperty($ids[0], 'Location'), true);
        if (!is_array($location) || !isset($location['latitude'], $location['longitude'])) {
            return null;
        }
        $lat = (float) $location['latitude'];
        $lon = (float) $location['longitude'];
        return ($lat == 0.0 && $lon == 0.0) ? null : [$lat, $lon];
    }

    private static function Meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }

    /**
     * Abend, zu dem ein Zeitpunkt gehört (Y-m-d), oder '' außerhalb des Zeitfensters.
     * Fenster: eingestellte Uhrzeit bis 6 Uhr morgens.
     */
    private function ReminderEvening(int $now): string
    {
        $t = json_decode($this->ReadPropertyString('ReminderTime'), true);
        $start = (int) ($t['hour'] ?? 21) * 60 + (int) ($t['minute'] ?? 0);
        $minute = (int) date('G', $now) * 60 + (int) date('i', $now);

        if ($minute >= $start) {
            return date('Y-m-d', $now);
        }
        if ($minute < self::REMINDER_END_HOUR * 60 && $start > self::REMINDER_END_HOUR * 60) {
            return date('Y-m-d', $now - 86400);            // nach Mitternacht: gehört zum Vorabend
        }
        return '';
    }

    /** Aktuelle Zeit (für Tests überschreibbar). */
    protected function ReminderNow(): int
    {
        return time();
    }

    /** Prüfen und ggf. melden. Wird nach jedem Abruf und bei Änderung der Quellvariablen aufgerufen. */
    private function EvaluateReminder(): void
    {
        if (!$this->ReminderActive() || @$this->GetIDForIdent('ChargeReminder') === false) {
            return;
        }

        $now = $this->ReminderNow();
        $atHome = $this->VehicleAtHome();
        $connected = (bool) $this->GetValue('VehicleConnected');

        // Zeitpunkt merken, ab dem das Auto unangesteckt zu Hause steht
        if ($atHome !== true || $connected) {
            $this->WriteAttributeInteger('ReminderArrived', 0);
            $this->SetValue('ChargeReminder', false);
            return;
        }
        if ($this->ReadAttributeInteger('ReminderArrived') === 0) {
            $this->WriteAttributeInteger('ReminderArrived', $now);
        }

        $evening = $this->ReminderEvening($now);
        $waited = $now - $this->ReadAttributeInteger('ReminderArrived') >= max(0, $this->ReadPropertyInteger('ReminderDelay')) * 60;
        $soc = $this->CurrentSoc();
        $needsCharge = $soc === null || $soc < $this->ReadPropertyInteger('ReminderSocBelow');

        $due = $evening !== '' && $waited && $needsCharge;
        $this->SetValue('ChargeReminder', $due);

        if ($due && $this->ReadAttributeString('ReminderSent') !== $evening) {
            $this->WriteAttributeString('ReminderSent', $evening);
            $text = 'Das Auto steht zu Hause, ist aber nicht an der Wallbox angesteckt.'
                . ($soc !== null ? ' Akkustand ' . $soc . ' %.' : '');
            $this->Notify('🔌 Bitte Auto anstecken', $text, true);
            $this->SendDebug('Lade-Erinnerung', $text, 0);
        }
    }
}
