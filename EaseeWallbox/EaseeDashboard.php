<?php

/**
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

/**
 * Erzeugt die HTML-Übersicht für die Variable "Dashboard" (~HTMLBox).
 * Wird nach jedem Abruf, jedem Befehl und jeder Preisänderung neu
 * aufgebaut - ein eigenes Ereignis ist nicht mehr nötig.
 */
trait EaseeDashboard
{
    private function BuildDashboard(): string
    {
        $opMode = (int) $this->GetValue('Status');
        $statusText = self::StatusText((int) $this->GetValue('Status'));
        $color = self::StatusColor($opMode, (int) $this->GetValue('ErrorCode'));

        $power = (float) $this->GetValue('Power');
        $maxKW = max(1, $this->ReadPropertyInteger('MaxPowerKW'));
        $percent = min(100, max(0, $power / $maxKW * 100));

        $rssi = (int) $this->GetValue('WiFiRSSI');
        [$wifiColor, $wifiText] = self::WifiQuality($rssi);

        $chargerName = $this->ReadAttributeString('ChargerName');
        $chargerId = $this->ReadAttributeString('ActiveChargerID');
        $lastError = (string) $this->GetValue('LastError');

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $n = fn ($v, $d = 2) => number_format((float) $v, $d, ',', '.');

        $chip = function (string $label, bool $on) use ($e) {
            return '<span class="ew-chip"><i style="background:' . ($on ? '#34b36b' : '#e5484d') . '"></i>'
                . $e($label) . '</span>';
        };
        $kpi = function (string $label, string $value, string $unit = '') use ($e) {
            return '<div class="ew-kpi"><div class="ew-l">' . $e($label) . '</div><div class="ew-v">'
                . $e($value) . ' <small>' . $e($unit) . '</small></div></div>';
        };

        $html = '<style>' . self::DashboardCss() . '</style>
<div class="ew">
  <div class="ew-head">
    <div><div class="ew-title">⚡ ' . $e($chargerName !== '' ? $chargerName : 'Easee Wallbox') . '</div>
         <div class="ew-sub">' . $e($chargerId) . ' · Firmware ' . $e($this->GetValue('Firmware'))
            . ' · WLAN <span style="color:' . $wifiColor . '">' . $rssi . ' dBm (' . $e($wifiText) . ')</span></div></div>
    <div class="ew-time">Letztes Update<br><b>' . $e(self::FormatTime((int) $this->GetValue('LastUpdate'))) . '</b></div>
  </div>

  <div class="ew-status" style="border-color:' . $color . ';background:' . $color . '22">
    <span class="ew-dot" style="background:' . $color . ';box-shadow:0 0 12px ' . $color . '"></span>'
            . $e($statusText) . '
    <div class="ew-reason">' . $e($this->GetValue('Reason')) . '</div>
  </div>

  <div class="ew-chips">'
            . $chip('Online', (bool) $this->GetValue('Online'))
            . $chip('API', (bool) $this->GetValue('ApiOk'))
            . $chip('Fahrzeug', (bool) $this->GetValue('VehicleConnected'))
            . $chip('Ladung', (bool) $this->GetValue('ChargingActive'))
            . $chip('Kabel verriegelt', (bool) $this->GetValue('CableLocked'))
            . $chip('Dauerhaft verriegelt', (bool) $this->GetValue('CableLockPermanent'))
            . $chip('Smart Charging', (bool) $this->GetValue('SmartCharging')) . '
  </div>

  <div class="ew-power">
    <div class="ew-l">Aktuelle Ladeleistung</div>
    <div class="ew-big">' . $n($power) . ' <small>kW</small></div>
    <div class="ew-bar"><div style="width:' . round($percent, 1) . '%"></div></div>
    <div class="ew-bartext">' . $n($power) . ' von ' . $maxKW . ' kW · ' . $n($this->GetValue('Current'), 1) . ' A · '
            . (int) $this->GetValue('PhaseCount') . '-phasig</div>
    <div class="ew-phases">
      <div><span>L1</span>' . $n($this->GetValue('CurrentL1'), 1) . ' A</div>
      <div><span>L2</span>' . $n($this->GetValue('CurrentL2'), 1) . ' A</div>
      <div><span>L3</span>' . $n($this->GetValue('CurrentL3'), 1) . ' A</div>
    </div>
  </div>

  <div class="ew-grid">'
            . $kpi('Session Energie', $n($this->GetValue('SessionEnergy')), 'kWh')
            . $kpi('Session Kosten', $n($this->GetValue('SessionCost')), '€')
            . $kpi('Gesamtenergie', $n($this->GetValue('LifetimeEnergy'), 0), 'kWh')
            . $kpi('Gesamtkosten', $n($this->GetValue('LifetimeCost')), '€')
            . ($this->CurrentSoc() !== null ? $kpi('Akkustand Fahrzeug', (string) $this->CurrentSoc(), '%') : '')
            . $kpi('Heute', $n($this->GetValue('EnergyToday')) . ' kWh', $n($this->GetValue('CostToday')) . ' €')
            . $kpi('Dieser Monat', $n($this->GetValue('EnergyMonth'), 1) . ' kWh', $n($this->GetValue('CostMonth')) . ' €')
            . $kpi('Dieses Jahr', $n($this->GetValue('EnergyYear'), 0) . ' kWh', $n($this->GetValue('CostYear')) . ' €')
            . $kpi('Ladestrom-Grenze', (string) (int) $this->GetValue('ChargeLimit'), 'A')
            . $kpi('Strompreis', $n($this->GetValue('EnergyPrice'), 4), '€/kWh')
            . '</div>'
            . $this->BuildScheduleLine()
            . $this->BuildPowerChart()
            . $this->BuildMonthChart()
            . $this->BuildHistoryTable();

        if ($lastError !== '') {
            $html .= '<div class="ew-error"><b>Letzter Fehler:</b><br>' . $e($lastError) . '</div>';
        }

        return $html . '</div>';
    }

    private function BuildPowerChart(): string
    {
        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archives) === 0) {
            return '';
        }

        $values = @AC_GetLoggedValues($archives[0], $this->GetIDForIdent('Power'), time() - 86400, time(), 0);
        if (!is_array($values) || count($values) < 2) {
            return '<div class="ew-box ew-empty">Noch keine Archivdaten für die Ladeleistung (24 h).</div>';
        }

        usort($values, fn ($a, $b) => $a['TimeStamp'] <=> $b['TimeStamp']);

        // Zeitachse statt Index-Achse: Punkte liegen an ihrer echten Uhrzeit
        $w = 900;
        $h = 160;
        $p = 20;
        $from = time() - 86400;
        $max = max(1.0, (float) max(array_column($values, 'Value')));

        $points = [];
        foreach ($values as $v) {
            $x = $p + ($w - 2 * $p) * max(0, $v['TimeStamp'] - $from) / 86400;
            $y = $h - $p - ($h - 2 * $p) * ((float) $v['Value'] / $max);
            $points[] = round($x, 1) . ',' . round($y, 1);
        }
        // Linie bis "jetzt" mit letztem Wert fortführen
        $last = end($values);
        $points[] = ($w - $p) . ',' . round($h - $p - ($h - 2 * $p) * ((float) $last['Value'] / $max), 1);

        return '<div class="ew-box"><div class="ew-boxhead"><span>Ladeleistung letzte 24 Stunden</span>'
            . '<span>max. ' . number_format($max, 1, ',', '.') . ' kW</span></div>'
            . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" class="ew-chart">'
            . '<line x1="' . $p . '" y1="' . ($h - $p) . '" x2="' . ($w - $p) . '" y2="' . ($h - $p) . '" class="ew-axis"/>'
            . '<polyline points="' . implode(' ', $points) . '" class="ew-line"/></svg>'
            . '<div class="ew-axislabels"><span>-24 h</span><span>-12 h</span><span>jetzt</span></div></div>';
    }

    private function BuildScheduleLine(): string
    {
        if (!$this->ScheduleEnabled()) {
            return '';
        }

        $mode = (self::SCHEDULE_TEXT[(int) $this->GetValue('ScheduleMode')] ?? '');
        $info = (string) $this->GetValue('ScheduleInfo');

        return '<div class="ew-box ew-sched"><span>🕒 Zeitsteuerung: <b>' . htmlspecialchars($mode) . '</b></span>'
            . '<span>' . htmlspecialchars($info) . '</span></div>';
    }

    private function BuildMonthChart(): string
    {
        $stats = $this->ReadStats();
        if (count($stats) === 0) {
            return '';
        }

        // Letzte 12 Monate, fehlende Monate als 0
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $key = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
            $months[$key] = $stats[$key] ?? ['e' => 0, 'c' => 0];
        }

        $max = max(1.0, max(array_map(fn ($m) => (float) $m['e'], $months)));
        $names = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mär', '04' => 'Apr', '05' => 'Mai', '06' => 'Jun',
                  '07' => 'Jul', '08' => 'Aug', '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Dez'];

        $bars = '';
        foreach ($months as $key => $m) {
            $h = round((float) $m['e'] / $max * 100, 1);
            $title = number_format((float) $m['e'], 1, ',', '.') . ' kWh · ' . number_format((float) $m['c'], 2, ',', '.') . ' €';
            $bars .= '<div class="ew-mcol" title="' . $title . '">'
                . '<div class="ew-mval">' . ((float) $m['e'] > 0 ? round((float) $m['e']) : '') . '</div>'
                . '<div class="ew-mbar"><div style="height:' . $h . '%"></div></div>'
                . '<div class="ew-mlab">' . $names[substr($key, 5, 2)] . '</div></div>';
        }

        return '<div class="ew-box"><div class="ew-boxhead"><span>Energie pro Monat (kWh)</span></div>'
            . '<div class="ew-months">' . $bars . '</div></div>';
    }

    private function BuildHistoryTable(): string
    {
        $history = json_decode($this->ReadAttributeString('History'), true);
        if (!is_array($history) || count($history) === 0) {
            return '<div class="ew-box ew-empty">Noch keine abgeschlossenen Ladevorgänge erfasst.</div>';
        }

        $rows = '';
        foreach (array_slice(array_reverse($history), 0, 10) as $s) {
            $rows .= '<tr><td>' . date('d.m.Y H:i', (int) ($s['end'] ?? 0)) . '</td>'
                . '<td>' . self::FormatDuration((int) ($s['duration'] ?? 0)) . '</td>'
                . '<td>' . number_format((float) ($s['energy'] ?? 0), 2, ',', '.') . ' kWh</td>'
                . '<td>' . number_format((float) ($s['cost'] ?? 0), 2, ',', '.') . ' €</td></tr>';
        }

        return '<div class="ew-box"><div class="ew-boxhead"><span>Letzte Ladevorgänge</span></div>'
            . '<table class="ew-table"><tr><th>Ende</th><th>Dauer</th><th>Energie</th><th>Kosten</th></tr>'
            . $rows . '</table></div>';
    }

    private static function StatusColor(int $opMode, int $errorCode): string
    {
        if ($errorCode !== 0) {
            return '#e5484d';
        }

        // Zustandsfarben nach Hausstil (STYLEGUIDE.md): ok, warn, bad, info, off
        return [
            0 => '#6b7785', 1 => '#8796a5', 2 => '#e2a63b', 3 => '#34b36b', 4 => '#4b8ef0',
            5 => '#e5484d', 6 => '#4b8ef0', 7 => '#e67e22', 8 => '#8796a5'
        ][$opMode] ?? '#8796a5';
    }

    private static function WifiQuality(int $rssi): array
    {
        if ($rssi === 0) {
            return ['#8796a5', 'unbekannt'];
        }
        if ($rssi >= -60) {
            return ['#34b36b', 'sehr gut'];
        }
        if ($rssi >= -70) {
            return ['#e2a63b', 'gut'];
        }
        if ($rssi >= -80) {
            return ['#e67e22', 'ausreichend'];
        }
        return ['#e5484d', 'schwach'];
    }

    private static function FormatTime(int $ts): string
    {
        return $ts > 0 ? date('d.m.Y H:i:s', $ts) : '-';
    }

    private static function FormatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        return $h > 0 ? sprintf('%d h %02d min', $h, $m) : $m . ' min';
    }

    private static function DashboardCss(): string
    {
        return '
.ew{font-family:Arial,Helvetica,sans-serif;color:#fff;background:radial-gradient(circle at top left,#223946,#12161b 60%);padding:14px;border-radius:16px}
.ew small{font-size:12px;font-weight:500;color:#cfd8dc}
.ew-head{color:#fff;display:flex;justify-content:space-between;gap:12px;background:linear-gradient(135deg,#00b7d8,#008ba8);border-radius:14px;padding:14px 18px;margin-bottom:12px}
.ew-title{font-size:24px;font-weight:800}
.ew-sub{font-size:12px;opacity:.9;margin-top:4px}
.ew-time{text-align:right;font-size:12px;line-height:1.5}
.ew-status{border:1px solid;border-radius:14px;padding:14px;text-align:center;font-size:24px;font-weight:800}
.ew-dot{display:inline-block;width:15px;height:15px;border-radius:50%;margin-right:10px;vertical-align:middle}
.ew-reason{font-size:13px;font-weight:500;color:#d9e6f2;margin-top:6px}
.ew-chips{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}
.ew-chip{display:flex;align-items:center;gap:7px;background:#20272f;border:1px solid #303943;border-radius:999px;padding:6px 12px;font-size:13px;font-weight:700}
.ew-chip i{width:10px;height:10px;border-radius:50%;display:inline-block}
.ew-power{background:linear-gradient(145deg,#214f77,#173a59);border:1px solid #4ea3ff;border-radius:14px;padding:16px}
.ew-l{font-size:12px;color:#cfd8dc;font-weight:700;margin-bottom:6px}
.ew-big{font-size:52px;font-weight:900;text-shadow:0 0 12px rgba(78,163,255,.4)}
.ew-bar{height:16px;background:#2b3138;border-radius:10px;overflow:hidden;margin-top:8px}
.ew-bar div{height:100%;background:linear-gradient(90deg,#3498db,#2ecc71)}
.ew-bartext{text-align:center;font-size:12px;color:#d9e6f2;margin-top:5px}
.ew-phases{display:flex;gap:10px;margin-top:12px}
.ew-phases div{flex:1;background:rgba(255,255,255,.08);border-radius:10px;padding:8px;text-align:center;font-weight:800}
.ew-phases span{display:block;font-size:11px;color:#cfd8dc;font-weight:500}
.ew-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px}
.ew-kpi,.ew-box{background:#20252b;border:1px solid #30363d;border-radius:14px;padding:12px}
.ew-v{font-size:22px;font-weight:800}
.ew-box{margin-top:12px}
.ew-boxhead{display:flex;justify-content:space-between;font-weight:800;margin-bottom:8px}
.ew-empty{color:#cfd8dc}
.ew-chart{width:100%;height:160px}
.ew-axis{stroke:#4b5661;stroke-width:1}
.ew-line{fill:none;stroke:#2ecc71;stroke-width:3;stroke-linejoin:round}
.ew-axislabels{display:flex;justify-content:space-between;font-size:11px;color:#8b98a5}
.ew-table{width:100%;border-collapse:collapse;font-size:13px;color:#e5edf4}
.ew-table th{text-align:left;color:#cfd8dc;padding:6px 8px;border-bottom:1px solid #30363d}
.ew-table td{padding:6px 8px;border-bottom:1px solid #262b31;color:#e5edf4}
.ew-sched{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.ew-months{display:flex;gap:6px;align-items:flex-end;height:150px}
.ew-mcol{flex:1;display:flex;flex-direction:column;align-items:center;height:100%}
.ew-mval{font-size:10px;color:#cfd8dc;height:14px}
.ew-mbar{flex:1;width:100%;display:flex;align-items:flex-end}
.ew-mbar div{width:100%;background:linear-gradient(180deg,#00b7d8,#008ba8);border-radius:4px 4px 0 0;min-height:2px}
.ew-mlab{font-size:11px;color:#8b98a5;margin-top:4px}
.ew-error{margin-top:12px;padding:10px;background:#471c1c;border:1px solid #e74c3c;border-radius:10px;color:#ffd6d6}
@media (max-width:700px){.ew-grid{grid-template-columns:repeat(2,1fr)}.ew-head{flex-direction:column}.ew-time{text-align:left}.ew-big{font-size:36px}}
';
    }
}
