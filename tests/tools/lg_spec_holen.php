<?php

declare(strict_types=1);

/*
 * Holt die aktuelle LG-Spezifikation und schreibt daraus die Prüfstand-Beispiele.
 *
 *   php tests/tools/lg_spec_holen.php            Seiten laden, tests/fixtures/lg_examples.json neu schreiben
 *   php tests/tools/lg_spec_holen.php --pruefen  nur vergleichen, nichts schreiben (Exit 1 bei Abweichung)
 *
 * Die Entwicklerseiten von LG sind Next.js-Seiten; die OpenAPI steckt als "yamlJson" im
 * RSC-Datenstrom (self.__next_f.push). Übernommen werden je Gerätetyp das Profil-, das
 * Status- und die Befehlsbeispiele sowie die MQTT-Beispielnachrichten der Connect-API.
 */

const PAGES = [
    'connect'  => 'https://smartsolution.developer.lge.com/en/apiManage/thinq_connect',
    'profiles' => 'https://smartsolution.developer.lge.com/en/apiManage/device_profile',
];
const TARGET = __DIR__ . '/../fixtures/lg_examples.json';

// Device type names in the device list differ from the example names for a few types.
const DEVICE_TYPE_NAMES = [
    'main_washcombo' => 'DEVICE_WASHCOMBO_MAIN',
    'mini_washcombo' => 'DEVICE_WASHCOMBO_MINI',
    'pet_peeder'     => 'DEVICE_PET_FEEDER',
];

function fetchPage(string $url): string
{
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'header' => "User-Agent: Mozilla/5.0 (LGThinQ-Pruefstand)\r\n"]]);
    $html = @file_get_contents($url, false, $ctx);
    if (!is_string($html) || $html === '') {
        fwrite(STDERR, "Seite nicht erreichbar: $url\n");
        exit(2);
    }
    return $html;
}

/** @return array<string, mixed> */
function extractOpenApi(string $html, string $url): array
{
    preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)<\/script>/s', $html, $m);
    $payload = '';
    foreach ($m[1] as $chunk) {
        $decoded = json_decode('"' . $chunk . '"');
        if (is_string($decoded)) {
            $payload .= $decoded;
        }
    }
    $pos = strpos($payload, '"yamlJson":{');
    if ($pos === false) {
        fwrite(STDERR, "Keine OpenAPI in $url gefunden (Seitenaufbau geändert?)\n");
        exit(2);
    }
    $pos += strlen('"yamlJson":');
    $depth = 0;
    $inString = false;
    $escaped = false;
    $len = strlen($payload);
    for ($i = $pos; $i < $len; $i++) {
        $c = $payload[$i];
        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($c === '\\') {
                $escaped = true;
            } elseif ($c === '"') {
                $inString = false;
            }
            continue;
        }
        if ($c === '"') {
            $inString = true;
        } elseif ($c === '{') {
            $depth++;
        } elseif ($c === '}' && --$depth === 0) {
            break;
        }
    }
    $doc = json_decode(substr($payload, $pos, $i - $pos + 1), true);
    if (!is_array($doc) || !isset($doc['openapi'])) {
        fwrite(STDERR, "OpenAPI aus $url nicht lesbar\n");
        exit(2);
    }
    return $doc;
}

/** @return mixed */
function resolveExample(array $examples, string $name)
{
    $node = $examples[$name] ?? null;
    for ($hops = 0; is_array($node) && isset($node['$ref']) && $hops < 5; $hops++) {
        $node = $examples[basename((string)$node['$ref'])] ?? null;
    }
    return $node;
}

$connect = extractOpenApi(fetchPage(PAGES['connect']), PAGES['connect']);
$profiles = extractOpenApi(fetchPage(PAGES['profiles']), PAGES['profiles']);
$examples = $profiles['components']['examples'] ?? [];

$devices = [];
foreach (array_keys($examples) as $name) {
    if (!preg_match('/^(.+)-profile-example$/', (string)$name, $mm)) {
        continue;
    }
    $type = $mm[1];
    $profile = resolveExample($examples, $name);
    $status = resolveExample($examples, $type . '-object-example');
    $commands = [];
    foreach ($examples as $cname => $cex) {
        if (preg_match('/^' . preg_quote($type, '/') . '-command-example(-\d+)?$/', (string)$cname)) {
            $c = resolveExample($examples, (string)$cname);
            $commands[] = ['description' => (string)($c['description'] ?? ''), 'value' => $c['value'] ?? null];
        }
    }
    $devices[$type] = [
        'title'      => (string)($profiles['components']['schemas'][$type . '-profile']['title'] ?? $type),
        'deviceType' => DEVICE_TYPE_NAMES[$type] ?? 'DEVICE_' . strtoupper($type),
        'profile'    => $profile['value'] ?? null,
        'status'     => $status['value'] ?? null,
        'commands'   => $commands,
    ];
}
ksort($devices);

$cex = $connect['components']['examples'] ?? [];
$mqtt = [
    'DEVICE_STATUS'        => resolveExample($cex, 'event_message_example'),
    'DEVICE_REGISTERED'    => resolveExample($cex, 'register_device_example')['value'] ?? null,
    'DEVICE_UNREGISTERED'  => resolveExample($cex, 'delete_device_example')['value'] ?? null,
    'DEVICE_ALIAS_CHANGED' => resolveExample($cex, 'change_nickname_example')['value'] ?? null,
    'DEVICE_PUSH'          => resolveExample($cex, 'complete_operation_example')['value'] ?? null,
];
if (isset($mqtt['DEVICE_STATUS']['value'])) {
    $mqtt['DEVICE_STATUS'] = $mqtt['DEVICE_STATUS']['value'];
}

$fixture = [
    '_quelle' => [
        'seiten'   => array_values(PAGES),
        'abgerufen' => date('Y-m-d'),
        'hinweis'  => 'Erzeugt von tests/tools/lg_spec_holen.php — nicht von Hand bearbeiten. deviceType ist aus dem Beispielnamen abgeleitet.',
        'openapi'  => ['connect' => sha1(json_encode($connect)), 'profiles' => sha1(json_encode($profiles))],
    ],
    'devices' => $devices,
    'mqtt'    => $mqtt,
];

$old = is_file(TARGET) ? json_decode((string)file_get_contents(TARGET), true) : null;
$changed = [];
if (is_array($old)) {
    foreach (array_unique(array_merge(array_keys($old['devices'] ?? []), array_keys($devices))) as $type) {
        if (json_encode($old['devices'][$type] ?? null) !== json_encode($devices[$type] ?? null)) {
            $changed[] = $type;
        }
    }
    if (json_encode($old['mqtt'] ?? null) !== json_encode($mqtt)) {
        $changed[] = '(MQTT-Beispiele)';
    }
}

printf("%d Gerätetypen, %d MQTT-Beispiele. ", count($devices), count(array_filter($mqtt)));
echo is_array($old) ? ($changed === [] ? "Unverändert gegenüber der Datei.\n" : 'Geändert: ' . implode(', ', $changed) . "\n") : "Neue Datei.\n";

if (in_array('--pruefen', $argv, true)) {
    exit($changed === [] && is_array($old) ? 0 : 1);
}
file_put_contents(TARGET, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo 'Geschrieben: ' . realpath(TARGET) . "\n";
