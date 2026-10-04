<?php

declare(strict_types=1);

/**
 * Testumgebung für das Easee-Modul – ohne laufendes IP-Symcon.
 * Bildet IPSModuleStrict und die benötigten Symcon-Funktionen schlank nach.
 *
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const VM_UPDATE = 10603;
const IPS_KERNELSTARTED = 10001;
const KL_ERROR = 10206;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const VARIABLE_PRESENTATION_SLIDER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
const VARIABLE_PRESENTATION_DATE_TIME = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
const VARIABLE_PRESENTATION_SWITCH = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
const VARIABLE_PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
const VARIABLE_PRESENTATION_WEB_CONTENT = '{9DE1D610-5106-97FB-714D-1AADEDF8377A}';
const VARIABLE_TEMPLATE_VALUE_PRESENTATION_BATTERY = '{7BD38CF5-07F2-5B5B-8F7F-15398B823BFC}';

$GLOBALS['ext'] = [];        // fremde Variablen (z. B. Akkustand des Autos)
$GLOBALS['log'] = [];

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_GetInstanceListByModuleID(string $guid): array { return []; }
function IPS_SemaphoreEnter(string $n, int $t): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
function IPS_InstanceExists(int $id): bool { return false; }
function IPS_VariableExists(int $id): bool { return array_key_exists($id, $GLOBALS['ext']); }
function GetValue(int $id): mixed { return $GLOBALS['ext'][$id]; }
function IPS_SetProperty(int $id, string $n, mixed $v): bool { $GLOBALS['mod']->p[$n] = $v; return true; }
function IPS_ApplyChanges(int $id): bool { $GLOBALS['mod']->ApplyChanges(); return true; }

class IPSModuleStrict
{
    public int $InstanceID = 12345;
    public array $p = [];            // Eigenschaften
    public array $a = [];            // Attribute
    public array $v = [];            // Variablenwerte
    public array $pres = [];         // Darstellungen
    public array $ids = [];
    public array $actions = [];
    public array $timers = [];
    public array $msgs = [];
    public array $buffers = [];
    public array $pushes = [];
    public ?string $tile = null;
    public int $status = 0;

    public function Create(): void {}
    public function ApplyChanges(): void {}

    protected function RegisterPropertyBoolean(string $n, bool $d): bool { $this->p[$n] = $d; return true; }
    protected function RegisterPropertyInteger(string $n, int $d): bool { $this->p[$n] = $d; return true; }
    protected function RegisterPropertyFloat(string $n, float $d): bool { $this->p[$n] = $d; return true; }
    protected function RegisterPropertyString(string $n, string $d): bool { $this->p[$n] = $d; return true; }
    protected function ReadPropertyBoolean(string $n): bool { return (bool) $this->p[$n]; }
    protected function ReadPropertyInteger(string $n): int { return (int) $this->p[$n]; }
    protected function ReadPropertyFloat(string $n): float { return (float) $this->p[$n]; }
    protected function ReadPropertyString(string $n): string { return (string) $this->p[$n]; }
    protected function RegisterAttributeBoolean(string $n, bool $d): bool { $this->a[$n] = $d; return true; }
    protected function RegisterAttributeInteger(string $n, int $d): bool { $this->a[$n] = $d; return true; }
    protected function RegisterAttributeFloat(string $n, float $d): bool { $this->a[$n] = $d; return true; }
    protected function RegisterAttributeString(string $n, string $d): bool { $this->a[$n] = $d; return true; }
    public function ReadAttributeBoolean(string $n): bool { return (bool) $this->a[$n]; }
    public function ReadAttributeInteger(string $n): int { return (int) $this->a[$n]; }
    public function ReadAttributeFloat(string $n): float { return (float) $this->a[$n]; }
    public function ReadAttributeString(string $n): string { return (string) $this->a[$n]; }
    public function WriteAttributeBoolean(string $n, bool $v): bool { $this->a[$n] = $v; return true; }
    public function WriteAttributeInteger(string $n, int $v): bool { $this->a[$n] = $v; return true; }
    public function WriteAttributeFloat(string $n, float $v): bool { $this->a[$n] = $v; return true; }
    public function WriteAttributeString(string $n, string $v): bool { $this->a[$n] = $v; return true; }

    private function variable(string $ident, int $type, string|array $presentation): bool
    {
        if (is_string($presentation) && $presentation !== '' && $presentation[0] !== '~') {
            throw new Exception("Eigenes Profil statt Darstellung: $presentation");
        }
        $this->pres[$ident] = $presentation;
        if (array_key_exists($ident, $this->v)) {
            return false;
        }
        $this->v[$ident] = [false, 0, 0.0, ''][$type];
        $this->ids[$ident] = crc32($ident);
        return true;
    }
    public function RegisterVariableBoolean(string $i, string $n, string|array $p = '', int $pos = 0): bool { return $this->variable($i, 0, $p); }
    public function RegisterVariableInteger(string $i, string $n, string|array $p = '', int $pos = 0): bool { return $this->variable($i, 1, $p); }
    public function RegisterVariableFloat(string $i, string $n, string|array $p = '', int $pos = 0): bool { return $this->variable($i, 2, $p); }
    public function RegisterVariableString(string $i, string $n, string|array $p = '', int $pos = 0): bool { return $this->variable($i, 3, $p); }
    protected function MaintainVariable(string $i, string $n, int $t, string|array $p, int $pos, bool $keep): bool
    {
        if ($keep) {
            return $this->variable($i, $t, $p);
        }
        unset($this->v[$i], $this->ids[$i], $this->pres[$i]);
        return true;
    }
    protected function UnregisterVariable(string $i): bool { unset($this->v[$i], $this->ids[$i]); return true; }
    protected function EnableAction(string $i): bool { $this->actions[$i] = true; return true; }
    protected function DisableAction(string $i): bool { $this->actions[$i] = false; return true; }
    protected function GetIDForIdent(string $i): int|false { return $this->ids[$i] ?? false; }
    protected function GetValue(string $i): mixed { if (!array_key_exists($i, $this->v)) { throw new Exception("Variable fehlt: $i"); } return $this->v[$i]; }
    public array $writes = [];
    protected function SetValue(string $i, mixed $val): bool { if (!array_key_exists($i, $this->v)) { throw new Exception("Variable fehlt: $i"); } $this->v[$i] = $val; $this->writes[] = $i; return true; }
    protected function RegisterTimer(string $n, int $ms, string $s): bool { $this->timers[$n] = $ms; return true; }
    protected function SetTimerInterval(string $n, int $ms): bool { $this->timers[$n] = $ms; return true; }
    protected function RegisterMessage(int $s, int $m): bool { $this->msgs[$s] = $m; return true; }
    protected function UnregisterMessage(int $s, int $m): bool { unset($this->msgs[$s]); return true; }
    public function SetVisualizationType(int $t): void {}
    protected function UpdateVisualizationValue(mixed $v) { $this->tile = $v; $this->pushes[] = $v; }
    protected function SetBuffer(string $n, string $d): bool { $this->buffers[$n] = $d; return true; }
    protected function GetBuffer(string $n): string { return $this->buffers[$n] ?? ''; }
    protected function SetStatus(int $s): bool { $this->status = $s; return true; }
    protected function GetStatus(): int { return $this->status; }
    protected function UpdateFormField(string $f, string $p, mixed $v): bool { return true; }
    protected function SendDebug(string $m, string $d, int $f): bool { if (getenv('DEBUG')) { echo "  DBG $m: $d\n"; } return true; }
    protected function LogMessage(string $m, int $t): bool { $GLOBALS['log'][] = $m; return true; }
}

$failed = 0;
$passed = 0;
function check(bool $condition, string $message): void
{
    global $failed, $passed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    $condition ? $passed++ : $failed++;
}
