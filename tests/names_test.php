<?php

declare(strict_types=1);

/*
 * Names and captions of every LG example device (59 types) and the live air conditioner, as a
 * German Symcon shows them: variable names, switch captions, enumeration captions, prefixes and
 * suffixes. tests/fixtures/names_de.json is the reference; a change shows up as a difference.
 * After checking it, write the new state with:  php tests/names_test.php --golden
 */

require __DIR__ . '/bootstrap.php';

const NAMES_GOLDEN = __DIR__ . '/fixtures/names_de.json';

/** @return array<string, array<string, string|array<int, string>>> ident => name, or [name, captions…] */
function texts(int $inst): array
{
    $out = [];
    foreach (World::idents($inst) as $ident) {
        $v = World::variable($inst, $ident);
        $p = $v['customPresentation'] ?? [];
        $p = is_string($p) ? (json_decode($p, true) ?: []) : (array)$p;
        $captions = [];
        foreach (['PREFIX', 'SUFFIX', 'CAPTION_ON', 'CAPTION_OFF'] as $key) {
            if (is_string($p[$key] ?? null) && $p[$key] !== '') {
                $captions[] = $key . '=' . $p[$key];
            }
        }
        foreach (json_decode(is_string($p['OPTIONS'] ?? null) ? $p['OPTIONS'] : '[]', true) ?: [] as $o) {
            $captions[] = json_encode($o['Value'] ?? null) . '=' . ($o['Caption'] ?? '');
        }
        foreach (json_decode(is_string($p['INTERVALS'] ?? null) ? $p['INTERVALS'] : '[]', true) ?: [] as $i) {
            $captions[] = ($i['IntervalMinValue'] ?? '') . '..' . ($i['IntervalMaxValue'] ?? '') . '=' . ($i['ConstantValue'] ?? '');
        }
        $out[$ident] = $captions === [] ? $v['name'] : array_merge([$v['name']], $captions);
    }
    ksort($out);
    return $out;
}

/** @return array<string, array<string, mixed>> type => texts */
function allTexts(string $language): array
{
    $types = array_keys(FakeThinQCloud::examples()['devices']);
    $types[] = 'live_ac';
    $rows = [];
    foreach ($types as $type) {
        World::start();
        Kernel::$language = $language;
        World::quiet();
        [$inst] = $type === 'live_ac' ? World::liveAc() : World::example($type);
        $rows[$type] = texts($inst);
    }
    return $rows;
}

section('Deutsche Namen und Beschriftungen');
$de = allTexts('de');
$golden = is_file(NAMES_GOLDEN) ? json_decode((string)file_get_contents(NAMES_GOLDEN), true) : null;
if (in_array('--golden', $argv, true) || !is_array($golden)) {
    file_put_contents(NAMES_GOLDEN, json_encode($de, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "Stand geschrieben: " . NAMES_GOLDEN . "\n";
} else {
    $diff = [];
    foreach (array_unique(array_merge(array_keys($golden), array_keys($de))) as $type) {
        foreach (array_unique(array_merge(array_keys($golden[$type] ?? []), array_keys($de[$type] ?? []))) as $ident) {
            $old = $golden[$type][$ident] ?? null;
            $new = $de[$type][$ident] ?? null;
            if ($old !== $new) {
                $diff[] = sprintf('%s %s: %s → %s', $type, $ident, json_encode($old, JSON_UNESCAPED_UNICODE), json_encode($new, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    check($diff === [], count($de) . ' Geräte heißen auf Deutsch wie in names_de.json' . ($diff === [] ? '' : " — geändert:\n  "
        . implode("\n  ", array_slice($diff, 0, 40)) . (count($diff) > 40 ? "\n  … und " . (count($diff) - 40) . ' weitere' : '')
        . "\n  (gewollt? dann: php tests/names_test.php --golden)"));
}

done();
