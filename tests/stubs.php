<?php

declare(strict_types=1);

/**
 * Ladetest mit den offiziellen Symcon-Stubs (https://github.com/symcon/SymconStubs).
 *
 * Lädt die Bibliothek wie Symcon über den Modul-Loader, legt die Instanz an,
 * öffnet das Formular und prüft Variablen, Darstellungen und Kachel – ohne Easee-Cloud.
 *
 * Copyright (c) 2026 Armin Frohwerk
 *
 * Aufruf: php tests/stubs.php <Pfad zu SymconStubs>
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

// Veraltete Stub-Aufrufe und Ident-Hinweise der Stubs ausblenden
set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

// Die Stubs verlangen für Timer eine Testuhr (getTime). In eine Kopie die normale Uhrzeit eintragen.
$copy = sys_get_temp_dir() . '/easee-stubs-' . getmypid();
@mkdir($copy);
foreach (glob($stubs . '/*.php') as $file) {
    $code = (string) file_get_contents($file);
    if (basename($file) === 'ModuleStrictStubs.php') {
        $code = str_replace(
            "throw new Exception('getTime needs to be implemented by module under test');\n    }\n}",
            "return time();\n    }\n}",
            $code
        );
    }
    file_put_contents($copy . '/' . basename($file), $code);
}
register_shutdown_function(static function () use ($copy): void {
    array_map('unlink', glob($copy . '/*.php'));
    @rmdir($copy);
});

require $copy . '/autoload.php';

\IPS\Kernel::reset();
// Dashboard nutzt die Darstellung „Webinhalt“, kein Profil nötig
\IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

$failed = 0;
function ok(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
}

echo 'Easee Wallbox' . PHP_EOL;
try {
    $id = IPS_CreateInstance('{327DC3AE-621D-4C6B-A4D8-6D6DBE25308D}');
    ok($id > 0, 'Instanz angelegt');
    $form = json_decode(IPS_GetConfigurationForm($id), true);
    ok(is_array($form) && isset($form['elements']), 'Formular ist gültiges JSON');
    ok(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Ohne Zugangsdaten Status 201');
    foreach (['Status', 'ChargingActive', 'Power', 'ChargeLimit', 'SessionEnergy', 'EnergyToday', 'VehicleConnected', 'LastUpdate'] as $ident) {
        ok(@IPS_GetObjectIDByIdent($ident, $id) !== false, 'Variable ' . $ident);
    }
    $p = IPS_GetVariable(IPS_GetObjectIDByIdent('ChargeLimit', $id))['VariablePresentation'];
    ok(($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SLIDER, 'Ladestrom-Grenze als Schieberegler');
    $p = IPS_GetVariable(IPS_GetObjectIDByIdent('Status', $id))['VariablePresentation'];
    ok(($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_ENUMERATION, 'Status als Aufzählung');

    IPS_SetProperty($id, 'EnableSchedule', true);
    IPS_SetProperty($id, 'TileTheme', 1);
    IPS_ApplyChanges($id);
    foreach (['ScheduleMode', 'ScheduleStart', 'ReadyBy', 'TargetEnergy'] as $ident) {
        ok(@IPS_GetObjectIDByIdent($ident, $id) !== false, 'Zeitsteuerung: Variable ' . $ident);
    }
    $tile = EASEE_GetVisualizationTile($id);
    ok(str_contains($tile, 'handleMessage(') && str_contains($tile, 'theme'), 'Kachel-HTML mit Startdaten');
    ok(is_string(EASEE_GetStatistics($id)), 'EASEE_GetStatistics');
} catch (Throwable $e) {
    ok(false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}

echo PHP_EOL . ($failed === 0 ? 'Ladetest bestanden.' : $failed . ' Prüfung(en) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
