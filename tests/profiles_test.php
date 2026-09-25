<?php

declare(strict_types=1);

/*
 * Device matrix: every LG example device (tests/fixtures/lg_examples.json, 59 types) plus the
 * live air conditioner, each in a fresh world. Per type: variables, how many of them the module
 * fills from the example status (its own engine decides), warnings/errors during setup, and whether LG's own command examples come out of
 * RequestAction the way LG documents them. The table is compared with
 * tests/fixtures/profiles_golden.json — a fix that changes it shows up as a difference;
 * after checking it, write the new state with:  php tests/profiles_test.php --golden
 */

require __DIR__ . '/bootstrap.php';

const GOLDEN = __DIR__ . '/fixtures/profiles_golden.json';
const GENERIC = ['INFO', 'STATUS', 'LASTUPDATE'];

function identFor(string $resource, string $property, ?string $location): string
{
    $snake = static fn(string $s): string => strtoupper((string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $s));
    $base = $snake($resource) . '_' . $snake($property);
    return ($location !== null && strtoupper($location) !== 'MAIN') ? strtoupper($location) . '_' . $base : $base;
}

/** First writable leaf of a command: [resource, property, value, location of an element list]. */
function commandLeaf(array $cmd): ?array
{
    foreach ($cmd as $resource => $values) {
        if ($resource === 'location' || !is_array($values)) {
            continue;
        }
        if (in_array($resource, ['washer', 'dryer'], true)) {
            $leaf = commandLeaf($values);
            return $leaf === null ? null : [$leaf[0], $leaf[1], $leaf[2], $leaf[3], $resource];
        }
        foreach ($values as $prop => $v) {
            if (!in_array($prop, ['locationName', 'switchName', 'unit'], true)) {
                return [(string)$resource, (string)$prop, $v, $values['locationName'] ?? ($values['switchName'] ?? null), null];
            }
        }
    }
    return null;
}

function tryCommand(int $inst, array $profile, array $cmd): string
{
    $own = ThinQCommandCheck::validate($profile, $cmd);
    if ($own !== null) {
        return 'LG-Beispiel passt nicht zum eigenen Profil (' . $own . ')';
    }
    $leaf = commandLeaf($cmd);
    if ($leaf === null) {
        return 'kein Wert im Befehl';
    }
    [$res, $prop, $value, $loc, $part] = $leaf;
    $ident = identFor($res, $prop, $loc);
    if ($part !== null) {
        $ident = strtoupper($part) . '_' . $ident;
    }
    $vid = World::varId($inst, $ident);
    if ($vid === 0) {
        return 'keine Variable ' . $ident;
    }
    if (Kernel::$variables[$vid]['action'] === 0) {
        return 'keine Aktion auf ' . $ident;
    }
    $before = count(World::$cloud->requests);
    $ok = @RequestAction($vid, $value);
    $sent = array_values(array_filter(array_slice(World::$cloud->requests, $before), static fn(array $r): bool => $r['method'] === 'POST' && str_ends_with($r['path'], '/control')));
    if ($sent === []) {
        return 'kein Befehl gesendet (' . $ident . ')';
    }
    $body = $sent[0]['body'];
    if ($sent[0]['status'] >= 400) {
        return 'abgelehnt: ' . World::$cloud->lastRejection . ' — gesendet ' . json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    if (sameJson($body, $cmd)) {
        return 'gleich';
    }
    $part = onlyLeaf($cmd, $res, $prop, $part);
    if ($part !== $cmd && sameJson($body, $part)) {
        return 'gleich (Teil; LG bündelt ' . leafCount($cmd) . ' Eigenschaften)';
    }
    return 'anders: ' . json_encode($body, JSON_UNESCAPED_SLASHES) . ($ok ? '' : ' (RequestAction false)');
}

/** The command reduced to one property: its resource keeps selectors, the zone keeps its location. */
function onlyLeaf(array $cmd, string $res, string $prop, ?string $part): array
{
    if ($part !== null) {
        return [$part => onlyLeaf($cmd[$part], $res, $prop, null)];
    }
    $out = isset($cmd['location']) ? ['location' => $cmd['location']] : [];
    $keep = array_intersect_key($cmd[$res], array_flip(['locationName', 'switchName', 'unit']));
    $out[$res] = $keep + [$prop => $cmd[$res][$prop]];
    return $out;
}

function leafCount(array $cmd): int
{
    $n = 0;
    foreach ($cmd as $k => $v) {
        if ($k === 'location') {
            continue;
        }
        if (in_array($k, ['washer', 'dryer'], true)) {
            $n += leafCount((array)$v);
            continue;
        }
        $n += count(array_diff_key((array)$v, array_flip(['locationName', 'switchName', 'unit'])));
    }
    return $n;
}

/** How many capability variables the module can fill from the stored status (its own engine decides). */
function readCoverage(int $inst): int
{
    $profile = json_decode((string)World::attr($inst, 'LastProfile'), true) ?: [];
    $status = json_decode((string)World::attr($inst, 'LastStatus'), true) ?: [];
    $engine = new CapabilityEngine($inst, dirname(__DIR__) . '/LG ThinQ Device');
    $engine->buildPlan((string)World::attr($inst, 'DeviceType'), $profile, $status);
    return count(array_diff_key($engine->readValues($status), array_flip(['ERROR_LAST', 'PUSH_LAST'])));
}

$types = array_keys(FakeThinQCloud::examples()['devices']);
$types[] = 'live_ac';
$rows = [];
$unexpected = [];
foreach ($types as $type) {
    World::start();
    World::quiet();
    [$inst, $did] = $type === 'live_ac' ? World::liveAc() : World::example($type);
    $idents = array_values(array_diff(World::idents($inst), GENERIC));
    $read = readCoverage($inst);
    $warnings = array_values(array_filter(Kernel::$warnings, static fn(string $w): bool => !str_contains($w, 'Timer UpdateEnergy does not exist')));
    $errors = array_map(static fn(array $l): string => $l['text'], array_values(array_filter(Kernel::$log, static fn(array $l): bool => $l['type'] === 'ERROR')));
    foreach (array_merge($warnings, $errors) as $w) {
        $unexpected[] = $type . ': ' . $w;
    }
    $commands = [];
    foreach (World::$cloud->devices[$did]['commands'] as $c) {
        if (is_array($c['value'])) {
            $commands[] = ($c['description'] !== '' ? $c['description'] : 'Befehl') . ' => ' . tryCommand($inst, World::$cloud->devices[$did]['profile'], $c['value']);
        }
    }
    $rows[$type] = ['variablen' => count($idents), 'gelesen' => $read, 'warnungen' => count($warnings), 'fehler' => count($errors),
        'idents' => $idents, 'befehle' => $commands];
}

section('Gerätematrix (' . count($rows) . ' Typen)');
printf("%-28s %5s %6s %4s %4s  %s\n", 'Typ', 'Var.', 'gelesen', 'Warn', 'Fehl', 'LG-Befehlsbeispiel');
foreach ($rows as $type => $r) {
    printf("%-28s %5d %6d %4d %4d  %s\n", $type, $r['variablen'], $r['gelesen'], $r['warnungen'], $r['fehler'], $r['befehle'] === [] ? '—' : implode(' | ', $r['befehle']));
}
$stats = ['gleich' => 0, 'anders' => 0, 'abgelehnt' => 0, 'keine Variable' => 0, 'LG-Beispiel' => 0, 'sonst' => 0];
foreach ($rows as $r) {
    foreach ($r['befehle'] as $b) {
        $hit = false;
        foreach (array_keys($stats) as $k) {
            if (str_contains($b, '=> ' . $k)) {
                $stats[$k]++;
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            $stats['sonst']++;
        }
    }
}
echo "\nBefehlsbeispiele: " . implode(', ', array_map(static fn(string $k, int $n): string => "$k $n", array_keys($stats), $stats)) . "\n\n";

check(count(array_filter($rows, static fn(array $r): bool => $r['variablen'] === 0)) === 0, 'jeder Typ bekommt mindestens eine Variable (ERROR_LAST/PUSH_LAST zählen mit)');
check($unexpected === [], 'keine Warnungen und Fehler beim Einrichten (außer dem bekannten Energie-Timer, F9)' . ($unexpected === [] ? '' : ":\n  " . implode("\n  ", array_slice($unexpected, 0, 20))));

$golden = is_file(GOLDEN) ? json_decode((string)file_get_contents(GOLDEN), true) : null;
if (in_array('--golden', $argv, true) || !is_array($golden)) {
    file_put_contents(GOLDEN, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "Stand geschrieben: " . GOLDEN . "\n";
} else {
    $diff = [];
    foreach (array_unique(array_merge(array_keys($golden), array_keys($rows))) as $type) {
        if (json_encode($golden[$type] ?? null) !== json_encode($rows[$type] ?? null)) {
            $old = $golden[$type] ?? [];
            $new = $rows[$type] ?? [];
            $diff[] = sprintf('%s: Variablen %s→%s, neu [%s], weg [%s]%s', $type, $old['variablen'] ?? '-', $new['variablen'] ?? '-',
                implode(', ', array_diff($new['idents'] ?? [], $old['idents'] ?? [])), implode(', ', array_diff($old['idents'] ?? [], $new['idents'] ?? [])),
                json_encode($old['befehle'] ?? []) !== json_encode($new['befehle'] ?? []) ? ', Befehle geändert' : '');
        }
    }
    check($diff === [], 'Matrix unverändert gegenüber profiles_golden.json' . ($diff === [] ? '' : " — geändert:\n  " . implode("\n  ", $diff)
        . "\n  (gewollt? dann: php tests/profiles_test.php --golden)"));
}

done();
