<?php

/**
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

/**
 * Eigene Kachel für die Kachel-Visualisierung (HTML-SDK, ab Symcon 7).
 * Das HTML liegt in tile.html; die Daten werden als JSON geschickt.
 */
trait EaseeTile
{
    /** JSON so einbetten, dass kein Wert das Skript der Kachel beenden kann (z. B. "</script>"). */
    private const TILE_JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;   // kaputte Zeichen aus Fremddaten ersetzen statt Kachel abbrechen

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/tile.html');
        $data = json_encode($this->TileData(true), self::TILE_JSON);
        $this->SetBuffer('TileHash', '');

        // Startwerte direkt mitgeben, damit die Kachel sofort gefüllt ist
        return $html . '<script>handleMessage(' . json_encode($data, self::TILE_JSON) . ');</script>';
    }

    /** @param bool $withBackground Bilder mitschicken (nur beim Laden/nach Änderung - können groß sein) */
    private function PushTile(bool $withBackground = false): void
    {
        $json = json_encode($this->TileData($withBackground), self::TILE_JSON);

        // Nur senden, wenn sich etwas geändert hat (spart Last bei vielen offenen Kacheln)
        $hash = md5($json);
        if (!$withBackground && $this->GetBuffer('TileHash') === $hash) {
            return;
        }
        $this->SetBuffer('TileHash', $hash);
        $this->UpdateVisualizationValue($json);
    }

    /** Hintergrundbild als data-URL (leer = kein Bild). */
    private function TileBackgroundUrl(): string
    {
        return $this->ImageDataUrl('TileBackground');
    }

    /**
     * Bild aus einer Eigenschaft (SelectFile, base64) als data-URL.
     * Wird einmal verkleinert und zwischengespeichert: große Fotos würden
     * sonst bei jedem Öffnen der Kachel mehrere MB übertragen.
     */
    private function ImageDataUrl(string $property): string
    {
        $base64 = trim($this->ReadPropertyString($property));
        if ($base64 === '') {
            return '';
        }

        $key = $property . ':' . md5($base64);
        $cache = json_decode($this->ReadAttributeString('ImageCache'), true) ?: [];
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $raw = base64_decode($base64, true);
        $url = $raw === false ? '' : self::ShrinkImage($raw, $property === 'TileBackground' ? 1600 : 800);

        // Nur das aktuelle Bild je Eigenschaft behalten
        foreach (array_keys($cache) as $k) {
            if (str_starts_with($k, $property . ':')) {
                unset($cache[$k]);
            }
        }
        $cache[$key] = $url;
        $this->WriteAttributeString('ImageCache', json_encode($cache));
        return $url;
    }

    /** Erlaubt nur echte Bilder (PNG, JPEG, WebP, GIF) und verkleinert sie auf $max Pixel. */
    private static function ShrinkImage(string $raw, int $max): string
    {
        if (strncmp($raw, "\x89PNG", 4) === 0) {
            $mime = 'image/png';
        } elseif (strncmp($raw, "\xFF\xD8", 2) === 0) {
            $mime = 'image/jpeg';
        } elseif (strncmp($raw, 'RIFF', 4) === 0 && substr($raw, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
        } elseif (strncmp($raw, 'GIF8', 4) === 0) {
            $mime = 'image/gif';
        } else {
            return '';                        // kein Bild -> nicht einbetten
        }

        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($raw)) !== false) {
            $w = imagesx($img);
            $h = imagesy($img);
            if (max($w, $h) > $max) {
                $f = $max / max($w, $h);
                $out = imagecreatetruecolor(max(1, (int) round($w * $f)), max(1, (int) round($h * $f)));
                $png = $mime !== 'image/jpeg';
                if ($png) {               // Transparenz (z. B. freigestelltes Auto) erhalten
                    imagealphablending($out, false);
                    imagesavealpha($out, true);
                    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
                }
                imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
                ob_start();
                $png ? imagepng($out, null, 9) : imagejpeg($out, null, 82);
                $raw = (string) ob_get_clean();
                $mime = $png ? 'image/png' : 'image/jpeg';
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($raw);
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
            'status'    => self::StatusText($opMode),
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
            'soc'       => $this->CurrentSoc(),
            'socTarget' => ($this->CurrentSoc() !== null && @$this->GetIDForIdent('TargetSoc') !== false
                            && (int) $this->GetValue('ScheduleMode') === 2) ? (int) $this->GetValue('TargetSoc') : null,
            'progress'  => $this->ChargeProgress(),
            'theme'     => $this->ReadPropertyInteger('TileTheme'),      // 0 = Symcon-Design, 1 = Dunkel, 2 = Hell
            'guest'     => $this->IsGuest(),
            'reminder'  => @$this->GetIDForIdent('ChargeReminder') !== false && (bool) $this->GetValue('ChargeReminder'),
            'updated'   => (int) $this->GetValue('LastUpdate') > 0 ? date('H:i', (int) $this->GetValue('LastUpdate')) : '-'
        ];

        if ($withBackground) {
            $data['car'] = [
                'image'  => $this->IsGuest() ? '' : $this->ImageDataUrl('CarImage'),   // Gastladung: neutrales Auto
                'mirror' => $this->ReadPropertyBoolean('CarMirror'),
                'px'     => max(0, min(100, $this->ReadPropertyInteger('CarPortX'))),
                'py'     => max(0, min(100, $this->ReadPropertyInteger('CarPortY')))
            ];
            $data['bg'] = [
                'image' => $this->TileBackgroundUrl(),
                'dim'   => max(0, min(90, $this->ReadPropertyInteger('TileDim'))) / 100,
                'blur'  => max(0, min(20, $this->ReadPropertyInteger('TileBlur')))
            ];
        }

        return $data;
    }

    /**
     * Ladefortschritt für den Akku im Auto-Symbol in Prozent:
     * Akkustand des Fahrzeugs, sonst Fortschritt zum kWh-Ziel von "Fertig bis",
     * sonst null (dann nur Animation beim Laden).
     */
    private function ChargeProgress(): ?int
    {
        $soc = $this->CurrentSoc();
        if ($soc !== null) {
            return $soc;
        }

        if ($this->ScheduleEnabled() && (int) $this->GetValue('ScheduleMode') === 2 && (bool) $this->GetValue('VehicleConnected')) {
            $target = (float) $this->GetValue('TargetEnergy');
            if ($target > 0) {
                $charged = max(0.0, (float) $this->GetValue('SessionEnergy') - $this->ReadAttributeFloat('DeadlineBaseEnergy'));
                return (int) round(min(100, $charged / $target * 100));
            }
        }

        return null;
    }
}
