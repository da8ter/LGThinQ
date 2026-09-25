<?php

declare(strict_types=1);

/*
 * Findings of the review from 25.09.2026, Device side, as open checks (see review_bridge_test.php
 * for the scheme). Every finding starts from a fresh world.
 */

require __DIR__ . '/bootstrap.php';

$lgCommand = static fn(string $type): array => FakeThinQCloud::examples()['devices'][$type]['commands'][0]['value'];
$push = static function (string $deviceId, array $report): void {
    World::$cloud->deviceReports($deviceId, $report);
    World::flushMqtt();
};
$washerRun = [['location' => ['locationName' => 'MAIN'], 'runState' => ['currentState' => 'RUNNING']]];
$zip = static function (string $dataUri, string $name): string {
    $tmp = tempnam(sys_get_temp_dir(), 'lgtqr');
    file_put_contents($tmp, base64_decode(substr($dataUri, strlen('data:application/zip;base64,'))));
    $za = new ZipArchive();
    $za->open($tmp);
    $content = (string)$za->getFromName($name);
    $za->close();
    unlink($tmp);
    return $content;
};

section('F3 Einrichtung während einer Störung');
World::start();
[$w, $wid] = World::example('washer');
World::$cloud->down = true;
IPS_ApplyChanges($w);
World::$cloud->down = false;
$type = World::attr($w, 'DeviceType');
$prof = json_decode((string)World::attr($w, 'LastProfile'), true);
$push($wid, $washerRun);
$val = World::value($w, 'RUN_STATE_CURRENT_STATE');
World::start();
[$w2] = World::example('washer');
World::$cloud->fail('GET devices/{id}/profile', 503);
IPS_ApplyChanges($w2);
$n = count(World::idents($w2));
LGTQD_CleanupVariables($w2, true);
$lost = $n - count(World::idents($w2));
befund('F3', $type === 'DEVICE_WASHER' && isset($prof['property']) && $val === 'RUNNING' && $lost === 0, 'Ein Lauf während einer Störung behält Profil und Gerätetyp',
    sprintf('nach Ausfall: DeviceType "%s", LastProfile %s, Push-Wert "%s"; nur GET profile gestört: Aufräumen löschte %d von %d Variablen',
        $type, json_encode($prof), $val, $lost, $n));

section('F5 Profil nach einem Push (behoben)');
World::start();
[$w, $wid] = World::example('washer');
$push($wid, $washerRun);
$prof = json_decode((string)World::attr($w, 'LastProfile'), true) ?: [];
World::quiet();
RequestAction(World::varId($w, 'OPERATION_WASHER_OPERATION_MODE'), 'START');
$body = World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? null;
check(isset($prof['property'], $prof['error'], $prof['notification']) && sameJson($body, $lgCommand('washer')), 'nach einem Push bleibt das Profil vollständig, START geht mit location raus');
World::start();
[$ac, $acid] = World::liveAc();
$stripped = json_decode((string)World::attr($ac, 'LastProfile'), true)['property'];
Kernel::$instances[$ac]['attributes']['LastProfile'] = (string)json_encode($stripped); // the state of the live AC
World::quiet();
RequestAction(World::varId($ac, 'TEMPERATURE_TARGET_TEMPERATURE'), 24);
$acBody = World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? null;
check(sameJson($acBody, ['temperature' => ['unit' => 'C', 'targetTemperature' => 24]]) && World::$cloud->calls('GET devices/{id}/profile') === [],
    'eine gespeicherte Kopie ohne Hülle wird beim Lesen repariert, 24 °C geht ohne neuen Profilabruf mit unit raus: ' . json_encode($acBody));

section('F7 Grenzen je Fach (behoben)');
World::start();
[$f] = World::example('refrigerator');
World::quiet();
$ok = @RequestAction(World::varId($f, 'FREEZER_TEMPERATURE_TARGET_TEMPERATURE'), -18);
$fb = World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? null;
$rej = World::$cloud->lastRejection;
World::start();
[$wc] = World::example('wine_cellar');
World::quiet();
@RequestAction(World::varId($wc, 'WINE_LOWER_TEMPERATURE_TARGET_TEMPERATURE'), 7);
$wb = World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? null;
check($ok && ($fb['temperatureInUnits']['targetTemperatureC'] ?? null) === -18, 'Gefrierfach -18 °C geht unverändert raus und LG nimmt es an: ' . json_encode($fb));
check(($wb['temperatureInUnits']['targetTemperatureC'] ?? null) === 7, 'Weinfach unten 7 °C geht unverändert raus: ' . json_encode($wb));
World::start();
[$f] = World::example('refrigerator');
World::quiet();
@RequestAction(World::varId($f, 'FREEZER_TEMPERATURE_TARGET_TEMPERATURE'), -30);
check((World::$cloud->calls('POST devices/{id}/control')[0]['body']['temperatureInUnits']['targetTemperatureC'] ?? null) === -21, 'außerhalb des Bereichs: auf die Grenze des Gefrierfachs (-21), nicht des Kühlfachs');

section('F8 Mehrzonen- und Mehrkanalgeräte');
$missing = [];
foreach (['cooktop' => ['RIGHT_FRONT', 'LEFT_REAR'], 'plant_cultivator' => ['LOWER'], 'light_switch' => ['SWITCH_1', 'SWITCH_2', 'SWITCH_3'],
    'switch_strip' => ['SWITCH_1', 'SWITCH_USB'], 'washtower' => ['WASHER', 'DRYER']] as $t => $markers) {
    World::start();
    [$i] = World::example($t);
    $idents = World::idents($i);
    foreach ($markers as $m) {
        if (array_filter($idents, static fn(string $id): bool => str_contains($id, $m)) === []) {
            $missing[] = $t . ' ' . $m;
        }
    }
}
befund('F8', $missing === [], 'Jede Zone und jeder Kanal bekommt Variablen', 'ohne Variablen: ' . implode(', ', $missing));

section('F9 Energie (behoben)');
World::start();
[$ac, $acid] = World::liveAc();
$energy = array_values(array_intersect(['ENERGY_YESTERDAY', 'ENERGY_THIS_MONTH', 'ENERGY_LAST_MONTH'], World::idents($ac)));
check(count($energy) === 3, 'das Energieprofil {result: {property: [energyUsage]}} ergibt die drei Energievariablen');
check((World::timer($ac, 'UpdateEnergy')['interval'] ?? 0) === 6 * 3600 * 1000 && World::warningsLike('/Timer UpdateEnergy/') === [], 'Timer UpdateEnergy aus Create(), alle 6 h, ohne Warnung');
World::quiet();
LGTQD_UpdateEnergy($ac);
$usage = World::$cloud->calls('GET devices/energy/{id}/usage');
$periods = array_values(array_unique(array_map(static fn(array $r): string => (string)($r['query']['period'] ?? ''), $usage)));
sort($periods);
check(count($usage) === 3 && $periods === ['DAILY', 'MONTHLY'] && array_filter($usage, static fn(array $r): bool => $r['status'] !== 200) === [], 'Verbrauchsabfragen mit period DAILY/MONTHLY, alle angenommen');
check(World::value($ac, 'ENERGY_YESTERDAY') > 0.0, 'Verbrauch aus result.dataList[].useAmount');
World::$cloud->fail('GET devices/energy/{id}/profile', 503);
IPS_ApplyChanges($ac);
check(count(array_intersect(['ENERGY_YESTERDAY', 'ENERGY_THIS_MONTH', 'ENERGY_LAST_MONTH'], World::idents($ac))) === 3, 'ein Ausfall beim Energieprofil löscht die Energievariablen nicht');

section('F10 Profilabruf je Push (behoben)');
World::start();
[$ac, $acid] = World::liveAc();
World::quiet();
for ($i = 0; $i < 5; $i++) {
    $push($acid, ['temperature' => ['currentTemperature' => 21 + $i * 0.5]]);
    Kernel::advance(60);
}
$n = count(World::$cloud->calls('GET devices/{id}/profile'));
check($n <= 1, sprintf('Pushes mit Schlüsseln außerhalb des Profils holen es höchstens einmal neu (%d× GET profile bei 5 Pushes; Klimaanlage: airQualitySensor fehlt im Profil)', $n));

section('F11 Selbstheilung nach Störung beim Anlegen');
World::start();
$did = World::$cloud->addExampleDevice('washer');
World::$cloud->fail('GET devices/{id}/profile', 503, '', -1);
World::$cloud->fail('GET devices', 503, '', -1);
$w = World::addDevice($did);
Kernel::advance(15); // the single retry after 10 s fails as well
World::$cloud->faults = [];
$after = World::idents($w);
Kernel::advance(400);
$push($did, $washerRun);
$healed = in_array('RUN_STATE_CURRENT_STATE', World::idents($w), true);
befund('F11', $healed, 'Ein Gerät, das bei einer API-Störung angelegt wurde, heilt beim nächsten Push',
    sprintf('nach dem Anlegen: %s; nach dem Push: %s', implode(', ', $after), $healed ? 'geheilt' : 'unverändert — ERROR_LAST/PUSH_LAST zählen als Gerätevariablen'));

section('F12 Variablen, die erst mit dem Status auftauchen (behoben)');
World::start();
$did = World::$cloud->addExampleDevice('air_purifier');
World::$cloud->fail('GET devices/{id}/state', 503);
$i = World::addDevice($did);
$before = World::idents($i);
$push($did, ['timer' => ['absoluteStartTimer' => 'SET'], 'sleepTimer' => ['relativeStopTimer' => 'SET']]);
$new = array_values(array_diff(World::idents($i), $before));
check(in_array('TIMER_ABSOLUTE_START_TIMER', $new, true) && in_array('SLEEP_TIMER_RELATIVE_STOP_TIMER', $new, true),
    'ein Push legt Variablen an, die erst mit einem Statuswert entstehen (Timer SET): ' . implode(', ', $new));

section('F13 Bereich mit Schrittweite 0,5');
World::start();
[$ac, $acid] = World::liveAc();
$var = World::variable($ac, 'TEMPERATURE_TARGET_TEMPERATURE');
World::quiet();
@RequestAction(World::varId($ac, 'TEMPERATURE_TARGET_TEMPERATURE'), 23.5);
$sent = World::$cloud->calls('POST devices/{id}/control')[0]['body']['temperature']['targetTemperature'] ?? null;
$push($acid, ['temperature' => ['targetTemperature' => 23.5]]);
$shown = World::value($ac, 'TEMPERATURE_TARGET_TEMPERATURE');
befund('F13', $var['type'] === VARIABLETYPE_FLOAT && $sent === 23.5 && $shown === 23.5, 'Solltemperatur in 0,5-Schritten',
    sprintf('Variablentyp %s, 23,5 gesendet als %s, gemeldete 23,5 angezeigt als %s', ['Boolean', 'Integer', 'Float', 'String'][$var['type']], json_encode($sent), json_encode($shown)));

section('F15 Nachgeholte Einrichtung meldet an');
World::start();
IPS_SetProperty(World::$bridge, 'AccessToken', '');
IPS_ApplyChanges(World::$bridge);
[$w, $wid] = World::example('washer');
IPS_SetProperty(World::$bridge, 'AccessToken', World::$cloud->pat);
IPS_ApplyChanges(World::$bridge);
Kernel::advance(6);
befund('F15', isset(World::$cloud->eventSubs[$wid], World::$cloud->pushSubs[$wid]), 'InitialSetup abonniert Events und Pushes',
    sprintf('Variablen angelegt: %s; Event-Abo %s, Push-Abo %s', in_array('RUN_STATE_CURRENT_STATE', World::idents($w), true) ? 'ja' : 'nein',
        isset(World::$cloud->eventSubs[$wid]) ? 'ja' : 'nein', isset(World::$cloud->pushSubs[$wid]) ? 'ja' : 'nein'));

section('N2 Timer UNSET setzt Stunden und Minuten zurück');
World::start();
[$ac, $acid] = World::liveAc();
SetValueFloat(World::varId($ac, 'SLEEP_TIMER_RELATIVE_HOUR_TO_STOP'), 2.0);
$push($acid, ['sleepTimer' => ['relativeStopTimer' => 'UNSET']]);
$h = World::value($ac, 'SLEEP_TIMER_RELATIVE_HOUR_TO_STOP');
befund('N2', $h === 0.0, 'Sleep-Timer UNSET: Stunden auf 0', 'SLEEP_TIMER_RELATIVE_HOUR_TO_STOP bleibt ' . json_encode($h) . ' — Integer-Zugriff auf eine Float-Variable');

section('N3 MaintainReferences (behoben)');
World::start();
[$w] = World::example('washer');
check(!method_exists('LGThinQDevice', 'publicMaintainReferences') && !method_exists('LGThinQBridge', 'publicMaintainReferences')
    && !in_array(World::$bridge, Kernel::$instances[$w]['references'], true), 'kein Aufruf der SDK-Methode MaintainReferences, die es nicht gibt, und keine Referenz auf die Bridge (sonst Status 101 beim Reload)');

section('N5 MaintainVariable liefert false (behoben)');
World::start();
Kernel::$failMaintain['RUN_STATE_CURRENT_STATE'] = true;
[$w] = World::example('washer');
check(!in_array('RUN_STATE_CURRENT_STATE', World::idents($w), true) && World::logLines('/Variable.*RUN_STATE_CURRENT_STATE/') !== [], 'eine Variable, die Symcon nicht anlegt, steht im Meldungsprotokoll');

section('N6 Support-Paket (behoben)');
$summary = json_decode($zip(LGTQD_UIExportSupportBundle($w), '50_capabilities_summary.json'), true);
check(($summary['descriptorCount'] ?? 0) >= count(World::idents($w)) - 3, 'Support-Paket zählt die Fähigkeiten (' . json_encode($summary['descriptorCount'] ?? null) . ' bei ' . count(World::idents($w)) . ' Variablen)');

section('N7 Regeln aus CLAUDE.md');
$root = dirname(__DIR__);
$accepted = ['LG ThinQ Device/module.php' => 940, 'LG ThinQ Bridge/module.php' => 866, 'LG ThinQ Device/libs/CapabilityControlBuilder.php' => 692, 'LG ThinQ Bridge/libs/ThinQMqttSetupWizard.php' => 550];
$long = [];
foreach (array_merge(glob($root . '/*/module.php'), glob($root . '/*/libs/*.php'), glob($root . '/libs/*.php')) as $file) {
    $rel = substr($file, strlen($root) + 1);
    $lines = count(file($file));
    if ($lines > max(500, $accepted[$rel] ?? 0)) {
        $long[] = $rel . ' ' . $lines . (isset($accepted[$rel]) ? ' (angenommen ' . $accepted[$rel] . ')' : '');
    }
}
befund('N7a', $long === [], 'Dateien bis 500 Zeilen (oder die in REQUIREMENTS.md angenommene Länge)', implode(', ', $long));
$profiles = [];
foreach (glob($root . '/*/module.php') as $file) {
    if (preg_match_all("/'~[A-Za-z0-9.]+'/", (string)file_get_contents($file), $m)) {
        $profiles[] = basename(dirname($file)) . ': ' . implode(', ', array_unique($m[0]));
    }
}
befund('N7b', $profiles === [], 'Keine Variablenprofile für eigene Variablen', implode('; ', $profiles));
$german = [];
foreach (glob($root . '/*/form.json') as $file) {
    array_walk_recursive(json_decode((string)file_get_contents($file), true), static function ($v, $k) use (&$german, $file): void {
        if (in_array($k, ['caption', 'label'], true) && preg_match('/[äöüÄÖÜß]| einrichten| erzeugen| anzeigen|Hinweis|Diagnose/u', (string)$v)) {
            $german[] = basename(dirname($file)) . ': "' . mb_strimwidth((string)$v, 0, 40, '…') . '"';
        }
    });
}
befund('N7c', $german === [], 'Formulare mit englischen Quelltexten (Übersetzung über locale.json)', implode(', ', $german));
World::start();
Kernel::$language = 'en';
[$w] = World::example('washer');
[$ac] = World::liveAc();
$names = [];
foreach ([$w, $ac] as $inst) {
    foreach (World::idents($inst) as $ident) {
        $name = World::variable($inst, $ident)['name'];
        if (preg_match('/Letzte|Startzeit|Stoppzeit|Minuten|Stunden|Relativ|Absolut|[äöüß]/u', $name)) {
            $names[] = $ident . ' "' . $name . '"';
        }
    }
}
befund('N7d', $names === [], 'Englisches Symcon zeigt englische Variablennamen', count($names) . ' deutsche Namen, u. a. ' . implode(', ', array_slice($names, 0, 4)));

section('N8 Teilberichte je Fach (behoben)');
World::start();
[$f, $fid] = World::example('refrigerator');
$push($fid, ['temperature' => [['locationName' => 'FREEZER', 'targetTemperature' => -20, 'unit' => 'C']]]);
$push($fid, ['temperature' => [['locationName' => 'FRIDGE', 'targetTemperature' => 3, 'unit' => 'C']]]);
$fr = World::value($f, 'FRIDGE_TEMPERATURE_TARGET_TEMPERATURE');
$fz = World::value($f, 'FREEZER_TEMPERATURE_TARGET_TEMPERATURE');
check($fr === 3 && $fz === -20, sprintf('Teilberichte je Fach nach locationName zusammengeführt (FREEZER -20, dann FRIDGE 3: FRIDGE %s, FREEZER %s)', json_encode($fr), json_encode($fz)));
$push($fid, ['temperature' => ['locationName' => 'FREEZER', 'targetTemperature' => -18, 'unit' => 'C']]);
check(World::value($f, 'FREEZER_TEMPERATURE_TARGET_TEMPERATURE') === -18 && World::value($f, 'FRIDGE_TEMPERATURE_TARGET_TEMPERATURE') === 3, 'auch ein einzelnes Fach als Objekt statt Liste');

section('N9 HTTP 500 ohne Rumpf (behoben)');
World::start();
[$w, $wid] = World::example('washer');
World::$cloud->fail('GET devices/{id}/state', 500, '');
LGTQD_UpdateStatus($w);
$st = json_decode((string)World::attr($w, 'LastStatus'), true);
check(isset($st['runState']), 'ein 500 ohne Rumpf überschreibt den Gerätestatus nicht');
World::$cloud->fail('GET devices/{id}/state', 500, '');
$caught = '';
try {
    LGTQ_GetDeviceStatus(World::$bridge, $wid);
} catch (\Throwable $e) {
    $caught = $e->getMessage();
}
check(str_contains($caught, 'HTTP 500 error from') && str_contains($caught, '(empty body)'), 'LGTQ_GetDeviceStatus: ein 500 ohne Rumpf ist ein Fehler, kein leeres Ergebnis');

section('N10 Selbstheilung ohne das Attribut LastSelfHealTs');
World::start();
[$w, $wid] = World::example('washer');
unset(Kernel::$instances[$w]['attributes']['LastSelfHealTs']); // instance from before the attribute, module updated without reload
foreach (World::idents($w) as $ident) {
    if (!in_array($ident, ['INFO', 'STATUS', 'LASTUPDATE'], true)) {
        Kernel::deleteObject(World::varId($w, $ident));
        Kernel::$failMaintain[$ident] = true; // every heal keeps failing
    }
}
World::quiet();
for ($i = 0; $i < 3; $i++) {
    $push($wid, $washerRun);
    Kernel::advance(30);
}
$heals = substr_count(World::debugText($w), 'Self-heal: recreating');
$attrWarn = count(World::warningsLike('/Attribut LastSelfHealTs nicht gefunden/'));
befund('N10', $heals <= 1 && $attrWarn === 0, 'Selbstheilung bleibt gedrosselt, auch wenn das Attribut noch fehlt',
    sprintf('%d Heilversuche bei 3 Pushes in 90 s, %d Warnungen "Attribut LastSelfHealTs nicht gefunden"', $heals, $attrWarn));

section('N11 locale.json');
$dups = [];
foreach (glob($root . '/*/locale.json') as $file) {
    foreach (jsonDuplicateKeys((string)file_get_contents($file)) as $d) {
        $dups[] = basename(dirname($file)) . ': ' . $d;
    }
}
befund('N11', $dups === [], 'locale.json ohne doppelte Schlüssel', count($dups) . ' doppelt, u. a. ' . implode(', ', array_slice($dups, 0, 4)));

section('N12 Energiewerte sofort (behoben)');
World::start();
[$ac] = World::liveAc();
check(is_float(World::value($ac, 'ENERGY_YESTERDAY')) && World::value($ac, 'ENERGY_YESTERDAY') > 0.0, 'Energiewerte gleich nach dem Einrichten, nicht erst nach 6 h');
World::quiet();
IPS_ApplyChanges($ac);
check(World::$cloud->calls('GET devices/energy/{id}/usage') === [], 'ein weiteres ApplyChanges innerhalb von 6 h fragt den Verbrauch nicht erneut ab');

/** Keys that occur twice in the same JSON object ("path.key"). */
function jsonDuplicateKeys(string $json): array
{
    $dups = [];
    $stack = [];
    $len = strlen($json);
    $expectKey = false;
    for ($i = 0; $i < $len; $i++) {
        $c = $json[$i];
        if ($c === '{') {
            $stack[] = ['keys' => [], 'isObject' => true];
            $expectKey = true;
        } elseif ($c === '[') {
            $stack[] = ['keys' => [], 'isObject' => false];
        } elseif ($c === '}' || $c === ']') {
            array_pop($stack);
        } elseif ($c === ',') {
            $expectKey = ($stack[count($stack) - 1]['isObject'] ?? false);
        } elseif ($c === '"') {
            $j = $i + 1;
            while ($j < $len && $json[$j] !== '"') {
                $j += $json[$j] === '\\' ? 2 : 1;
            }
            $str = (string)json_decode(substr($json, $i, $j - $i + 1));
            $i = $j;
            if ($expectKey) {
                $top = count($stack) - 1;
                if (isset($stack[$top]['keys'][$str])) {
                    $dups[] = $str;
                }
                $stack[$top]['keys'][$str] = true;
                $expectKey = false;
            }
        }
    }
    return $dups;
}

done();
