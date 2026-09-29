<?php

declare(strict_types=1);

/**
 * Eigene Kachel für die Kachel-Visualisierung (HTML-SDK, ab Symcon 7).
 * Das HTML liegt in module.html; die Daten werden als JSON geschickt.
 */
trait EaseeTile
{
    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');

        // Startwerte direkt mitgeben, damit die Kachel sofort gefüllt ist
        return $html . '<script>handleMessage(' . json_encode(json_encode($this->TileData(true))) . ');</script>';
    }

    /** @param bool $withBackground Bild mitschicken (nur beim Laden/nach Änderung - kann groß sein) */
    private function PushTile(bool $withBackground = false): void
    {
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $this->UpdateVisualizationValue(json_encode($this->TileData($withBackground)));
        }
    }

    /** Hintergrundbild als data-URL (leer = kein Bild). */
    private function TileBackgroundUrl(): string
    {
        $base64 = trim($this->ReadPropertyString('TileBackground'));
        if ($base64 === '') {
            return '';
        }

        $head = base64_decode(substr($base64, 0, 24), true) ?: '';
        if (strncmp($head, "\x89PNG", 4) === 0) {
            $mime = 'image/png';
        } elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
        } elseif (strncmp($head, 'GIF8', 4) === 0) {
            $mime = 'image/gif';
        } else {
            $mime = 'image/jpeg';
        }

        return 'data:' . $mime . ';base64,' . $base64;
    }

    private function TileData(bool $withBackground = false): array
    {
        $opMode = (int) $this->GetValue('Status');
        $power = (float) $this->GetValue('Power');
        $maxKW = max(1, $this->ReadPropertyInteger('MaxPowerKW'));
        $schedule = '';
        if ($this->ScheduleEnabled() && (int) $this->GetValue('ScheduleMode') !== 0) {
            $schedule = (string) $this->GetValue('ScheduleInfo');
        }

        $data = [
            'name'      => $this->ReadAttributeString('ChargerName') ?: 'Easee Wallbox',
            'status'    => GetValueFormatted($this->GetIDForIdent('Status')),
            'color'     => self::StatusColor($opMode, (int) $this->GetValue('ErrorCode')),
            'power'     => self::Num($power),
            'percent'   => round(min(100, max(0, $power / $maxKW * 100)), 1),
            'maxKW'     => $maxKW,
            'sessionE'  => self::Num((float) $this->GetValue('SessionEnergy')),
            'sessionC'  => self::Num((float) $this->GetValue('SessionCost')),
            'todayE'    => self::Num((float) $this->GetValue('EnergyToday')),
            'todayC'    => self::Num((float) $this->GetValue('CostToday')),
            'monthE'    => self::Num((float) $this->GetValue('EnergyMonth'), 1),
            'monthC'    => self::Num((float) $this->GetValue('CostMonth')),
            'charging'  => (bool) $this->GetValue('ChargingActive'),
            'connected' => (bool) $this->GetValue('VehicleConnected'),
            'phases'    => (int) $this->GetValue('PhaseCount'),
            'current'   => self::Num((float) $this->GetValue('Current'), 1),
            'limit'     => (int) $this->GetValue('ChargeLimit'),
            'limitMax'  => $this->MaxAmpere(),
            'schedule'  => $schedule,
            'ok'        => (bool) $this->GetValue('ApiOk') && (bool) $this->GetValue('Online'),
            'updated'   => (int) $this->GetValue('LastUpdate') > 0 ? date('H:i', (int) $this->GetValue('LastUpdate')) : '-'
        ];

        if ($withBackground) {
            $data['bg'] = [
                'image' => $this->TileBackgroundUrl(),
                'dim'   => max(0, min(90, $this->ReadPropertyInteger('TileDim'))) / 100,
                'blur'  => max(0, min(20, $this->ReadPropertyInteger('TileBlur')))
            ];
        }

        return $data;
    }
}
