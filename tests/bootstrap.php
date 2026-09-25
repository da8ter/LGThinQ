<?php

declare(strict_types=1);

/*
 * Prüfstand LG ThinQ: the three real modules (Bridge, Device, Configurator) on a Symcon
 * kernel in memory (tests/sdk) and a fake LG cloud with MQTT (tests/fake). No Symcon,
 * no network, no LG account. Run everything with tests/run.sh.
 */

date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL);

// SDK constants as defined in Symcon 9.1 (read from the Docker system on 25.09.2026)
foreach ([
    'KR_CREATE' => 10101, 'KR_INIT' => 10102, 'KR_READY' => 10103, 'KR_UNINIT' => 10104, 'KR_SHUTDOWN' => 10105,
    'IPS_KERNELMESSAGE' => 10100, 'IPS_KERNELSTARTED' => 10001,
    'KL_MESSAGE' => 10201, 'KL_SUCCESS' => 10202, 'KL_NOTIFY' => 10203, 'KL_WARNING' => 10204, 'KL_ERROR' => 10205,
    'KL_DEBUG' => 10206, 'KL_CUSTOM' => 10207,
    'IS_SBASE' => 100, 'IS_CREATING' => 101, 'IS_ACTIVE' => 102, 'IS_DELETING' => 103, 'IS_INACTIVE' => 104,
    'IS_NOTCREATED' => 105, 'IS_EBASE' => 200,
    'OBJECTTYPE_CATEGORY' => 0, 'OBJECTTYPE_INSTANCE' => 1, 'OBJECTTYPE_VARIABLE' => 2, 'OBJECTTYPE_SCRIPT' => 3,
    'OBJECTTYPE_EVENT' => 4, 'OBJECTTYPE_MEDIA' => 5, 'OBJECTTYPE_LINK' => 6,
    'VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3,
    'VM_UPDATE' => 10603, 'IM_CHANGESTATUS' => 10505, 'FM_CONNECT' => 11101, 'FM_DISCONNECT' => 11102,
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
    'VARIABLE_PRESENTATION_SWITCH' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
    'VARIABLE_PRESENTATION_SLIDER' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}',
    'VARIABLE_PRESENTATION_ENUMERATION' => '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}',
    'VARIABLE_PRESENTATION_DATE_TIME' => '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}',
    'VARIABLE_PRESENTATION_DURATION' => '{08A6AF76-394E-D354-48D5-BFC690488E4E}',
    'VARIABLE_PRESENTATION_LEGACY' => '{4153A8D4-5C33-C65F-C1F3-7B61AAF99B1C}',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once __DIR__ . '/../libs/ThinQClock.php';
require_once __DIR__ . '/sdk/KernelRuntime.php';
require_once __DIR__ . '/sdk/Kernel.php';
require_once __DIR__ . '/sdk/IPSModule.php';
require_once __DIR__ . '/sdk/IPSModuleStrict.php';
require_once __DIR__ . '/sdk/functions.php';
require_once __DIR__ . '/fake/ThinQCommandCheck.php';
require_once __DIR__ . '/fake/FakeThinQCloud.php';
require_once __DIR__ . '/fake/ThinQHttpTransport.php'; // before the Bridge, see the class_exists guard there
require_once __DIR__ . '/fake/FakeMqttClient.php';
require_once __DIR__ . '/sdk/World.php';

// PHP warnings reach the Symcon log unless silenced with @ (Symcon behaves the same).
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }
    Kernel::recordWarning($severity, $message, $file, $line);
    return true;
});

$GLOBALS['checks'] = 0;
$GLOBALS['befunde'] = [];

function check(bool $condition, string $label): void
{
    $GLOBALS['checks']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
    echo "ok   $label\n";
}

/**
 * A finding of the review: $fixed is true once the defect is gone. Open findings do not fail
 * the run (tests/run.sh --streng does); a fixed one is announced so its check can become check().
 */
function befund(string $id, bool $fixed, string $label, string $detail = ''): void
{
    $GLOBALS['befunde'][$id] = $fixed;
    echo ($fixed ? 'BEHOBEN ' : 'OFFEN   ') . $id . '  ' . $label . ($detail !== '' && !$fixed ? "\n          " . $detail : '') . "\n";
}

/** JSON equality: object keys in any order, list order and value types count. */
function sameJson(mixed $a, mixed $b): bool
{
    $norm = static function (mixed $v) use (&$norm): mixed {
        if (!is_array($v)) {
            return $v;
        }
        $v = array_map($norm, $v);
        if (!array_is_list($v)) {
            ksort($v);
        }
        return $v;
    };
    return $norm($a) === $norm($b);
}

function section(string $title): void
{
    echo "== $title\n";
}

function done(): void
{
    $open = array_keys(array_filter($GLOBALS['befunde'], static fn(bool $fixed): bool => !$fixed));
    $fixed = array_keys(array_filter($GLOBALS['befunde']));
    $line = $open === [] ? "\nAlle {$GLOBALS['checks']} Prüfungen bestanden." : "\n{$GLOBALS['checks']} Prüfungen bestanden, "
        . count($open) . ' Befunde offen: ' . implode(' ', $open) . '.';
    if ($fixed !== []) {
        $line .= ' Behoben: ' . implode(' ', $fixed) . '.';
    }
    echo $line . "\n";
    exit(in_array('--streng', $GLOBALS['argv'] ?? [], true) && $open !== [] ? 1 : 0);
}
