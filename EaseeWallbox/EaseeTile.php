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
        return $html . '<script>handleMessage(' . json_encode(json_encode($this->TileData())) . ');</script>';
    }

    private function PushTile(): void
    {
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $this->UpdateVisualizationValue(json_encode($this->TileData()));
        }
    }

    private function TileData(): array
    {
        $opMode = (int) $this->GetValue('Status');
        $power = (float) $this->GetValue('Power');
        $maxKW = max(1, $this->ReadPropertyInteger('MaxPowerKW'));
        $schedule = '';
        if ($this->ScheduleEnabled() && (int) $this->GetValue('ScheduleMode') !== 0) {
            $schedule = (string) $this->GetValue('ScheduleInfo');
        }

        return [
            'name'      => $this->ReadAttributeString('ChargerName') ?: 'Easee Wallbox',
            'status'    => GetValueFormatted($this->GetIDForIdent('Status')),
            'color'     => self::StatusColor($opMode, (int) $this->GetValue('ErrorCode')),
            'power'     => self::Num($power),
            'percent'   => round(min(100, max(0, $power / $maxKW * 100)), 1),
            'maxKW'     => $maxKW,
            'sessionE'  => self::Num((float) $this->GetValue('SessionEnergy')),
            'sessionC'  => self::Num((float) $this->GetValue('SessionCost')),
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
    }
}
