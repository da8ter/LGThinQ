<?php

declare(strict_types=1);

/*
 * Findings of the review from 25.09.2026, Bridge side. All are fixed and each is a check() that
 * fails if its defect comes back. IDs: F = the 15 reported findings, N = the ones cut by the
 * 15-item cap, W = found while building this bench. Device side: review_device_test.php.
 * A new finding starts as befund() (OFFEN until fixed, then BEHOBEN; tests/run.sh --streng fails
 * while one is open) and becomes a check() with its fix.
 */

require __DIR__ . '/bootstrap.php';

section('F1 Geheimnisse im Protokoll (behoben)');
// Raw PEM ("-----BEGIN"), base64 PEM as the Client Socket stores it ("LS0tLS1CRUdJTi") and the PAT,
// anywhere in the message log or in any instance debug.
$leaks = static function (): array {
    $text = implode("\n", array_column(Kernel::$log, 'text'));
    foreach (Kernel::$debug as $lines) {
        foreach ($lines as [$message, $data]) {
            $text .= "\n" . $message . ' ' . $data;
        }
    }
    $found = [];
    foreach (['PAT' => World::$cloud->pat, 'PEM' => '-----BEGIN', 'PEM base64' => 'LS0tLS1CRUdJTi'] as $what => $needle) {
        if (($n = substr_count($text, $needle)) > 0) {
            $found[] = $what . ' ' . $n . '×';
        }
    }
    return $found;
};
World::start(['Debug' => true]);
World::quiet();
LGTQ_GetDevices(World::$bridge);
check($leaks() === [] && substr_count(World::debugText(World::$bridge), 'GET https://') === 1, 'Debug an, GetDevices: Anfrage im Debug, aber kein PAT in Debug oder Meldungsprotokoll');
$sock = IPS_CreateInstance(CLIENT_SOCKET_GUID);
IPS_SetProperty($sock, 'PrivateKey', base64_encode("-----BEGIN PRIVATE KEY-----\nPRUEFSTANDGEHEIM\n-----END PRIVATE KEY-----\n"));
IPS_ApplyChanges($sock);
IPS_ConnectInstance(World::$mqtt, $sock);
IPS_ApplyChanges(World::$bridge);
check($leaks() === [] && str_contains(World::debugText(World::$bridge), 'PrivateKey'), 'MQTT-Diagnose nennt PrivateKey nur als Schlüsselnamen');
World::start(['Debug' => true]);
ob_start();
LGTQ_UISetupMqttConnection(World::$bridge);
ob_end_clean();
check(($l = $leaks()) === [], 'Debug an, MQTT-Einrichtung: kein Schlüssel, Zertifikat oder PAT im Klartext' . ($l === [] ? '' : ' (' . implode(', ', $l) . ')'));
LGTQ_UIGenerateMQTTClientCerts(World::$bridge);
check(($l = $leaks()) === [], 'Debug an, Zertifikats-ZIP: kein Schlüssel oder PAT im Klartext' . ($l === [] ? '' : ' (' . implode(', ', $l) . ')'));

section('F2 Topicfilter (behoben)');
World::start([], false);
[$dev, $did] = World::example('water_heater');
World::quiet();
World::$cloud->deviceReports($did, ['temperature' => ['currentTemperature' => 47]]);
World::flushMqtt();
check(World::value($dev, 'TEMPERATURE_CURRENT_TEMPERATURE') === 47.0, 'Standardfilter app/clients/{ClientID}/push: {ClientID} wird eingesetzt, der Push kommt an');
IPS_SetProperty(World::$bridge, 'MQTTTopicFilter', 'app/clients/+/push');
IPS_ApplyChanges(World::$bridge);
World::quiet();
World::$cloud->deviceReports($did, ['temperature' => ['currentTemperature' => 48]]);
World::flushMqtt();
check(World::value($dev, 'TEMPERATURE_CURRENT_TEMPERATURE') === 48.0 && World::warningsLike('/preg_match/') === [], 'Filter mit +: der Push kommt an, ohne Warnung');
$match = [['app/clients/+/push', 'app/clients/Symcon1/push', true], ['app/clients/#', 'app/clients', true], ['app/clients/#', 'app/clients/a/b', true],
    ['app/clients/+/push', 'app/clients/a/b/push', false], ['app/clients/*/#', 'app/clients/Symcon1/push', true],
    ['app/clients/Symcon1/push', 'app/clients/Symcon2/push', false], ['app/clients/symcon1/push', 'app/clients/Symcon1/push', true]];
$wrong = array_filter($match, static fn(array $m): bool => ThinQMqttRouter::topicMatches($m[0], $m[1]) !== $m[2]);
check($wrong === [], 'MQTT-Platzhalter + und # ebenenweise, * wie +' . ($wrong === [] ? '' : ': ' . json_encode(array_values($wrong))));

section('F4 Erneuerung der Event-Abos (behoben)');
World::start();
Kernel::advance(10); // the device subscribes ten seconds after the Bridge armed its timer
[$dev, $did] = World::example('water_heater');
World::quiet();
$gap = 0;
$first = null;
for ($t = 60; $t <= 48 * 3600; $t += 60) {
    Kernel::advance(60);
    if (!isset(World::$cloud->activeEventSubs()[$did])) {
        $gap += 60;
        $first ??= $t;
    }
}
check($gap === 0, sprintf('Event-Abo bleibt 48 h ohne Lücke bestehen (%d min ohne Abo, erste Lücke nach %.1f h)', $gap / 60, ($first ?? 0) / 3600));
$renewals = count(World::$cloud->calls('POST event/{id}/subscribe'));
check($renewals >= 1 && $renewals <= 3, 'erneuert wird kurz vor Ablauf, nicht bei jeder Prüfung (' . $renewals . '× in 48 h)');
IPS_ApplyChanges(World::$bridge); // re-arms the timer (Symcon 9.1 restarts the countdown)
for ($t = 0; $t < 30 * 3600; $t += 60) {
    Kernel::advance(60);
    if (!isset(World::$cloud->activeEventSubs()[$did])) {
        $gap += 60;
    }
}
check($gap === 0, 'auch nach erneutem Stellen des Timers keine Lücke');

section('F6 DEVICE_PUSH (behoben)');
World::start();
[$w, $wid] = World::example('washer');
$before = (string)World::attr($w, 'LastStatus');
World::quiet();
World::$cloud->devicePush($wid, 'WASHING_IS_COMPLETE');
World::flushMqtt();
check(World::value($w, 'PUSH_LAST') === 'WASHING_IS_COMPLETE', 'DEVICE_PUSH: pushCode landet in PUSH_LAST');
check((string)World::attr($w, 'LastStatus') === $before && World::$cloud->calls('GET devices/{id}/profile') === [], 'der Gerätestatus bleibt unberührt, kein Profilabruf wegen fremder Hüllenfelder');
World::mqtt(World::topic(), ['push' => ['pushType' => 'DEVICE_PUSH', 'deviceId' => $wid, 'pushCode' => 'WASHING_IS_COMPLETE',
    'data' => ['runState' => ['currentState' => 'END']]]]);
check(World::value($w, 'RUN_STATE_CURRENT_STATE') === 'END', 'Statusdaten, die ein Push verschachtelt mitbringt (data), kommen weiterhin an');

section('F14 MQTT-Einrichtung (behoben)');
World::start();
ob_start();
LGTQ_UISetupMqttConnection(World::$bridge);
$out = trim((string)ob_get_clean());
check(preg_match('/Error|Fehler|Call to/', $out) === 0 && str_starts_with($out, 'Fertig.') && !str_starts_with($out, 'Fertig..'), 'Einrichtung endet mit "Fertig." und ohne Fehlermeldung');
check(World::logLines('/MQTT-Verbindung eingerichtet \(ClientID=/') !== [], 'Abschlussmeldung steht im Meldungsprotokoll');

section('W1 eingestellter MQTT-Client (behoben)');
World::start();
[$w, $wid] = World::example('washer');
$other = World::mqttClient('SymconAnders');
IPS_SetProperty(World::$bridge, 'MQTTClientID', $other);
IPS_ApplyChanges(World::$bridge);
ob_start();
LGTQ_UISetupMqttConnection(World::$bridge);
ob_end_clean();
check(Kernel::$instances[World::$bridge]['connection'] === $other, 'Assistent nimmt den MQTT-Client aus MQTTClientID (ModuleInfo.ModuleID)');
$ids = array_map(static fn(int $id): string => (string)IPS_GetProperty($id, 'ClientID'), IPS_GetInstanceListByModuleID(FakeMqttClient::MODULE_ID));
check(IPS_GetProperty($other, 'ClientID') === 'SymconAnders' && count($ids) === count(array_unique($ids)), 'der gewählte Client behält seine ClientID, keine zwei MQTT-Clients mit derselben (' . implode(', ', $ids) . ')');
check(IPS_GetProperty(World::$bridge, 'MQTTTopicFilter') === ThinQBridgeConfig::DEFAULT_TOPIC && World::attr(World::$bridge, 'ClientID') === 'SymconAnders',
    'Topicfilter als {ClientID}-Vorlage, die Bridge spricht LG mit der neuen ClientID an');
check((World::$cloud->eventSubs[$wid]['client'] ?? '') === 'SymconAnders' && (World::$cloud->pushSubs[$wid]['client'] ?? '') === 'SymconAnders',
    'Event- und Push-Abo des Geräts laufen danach unter der neuen ClientID');
World::quiet();
World::$cloud->deviceReports($wid, [['location' => ['locationName' => 'MAIN'], 'runState' => ['currentState' => 'RUNNING']]]);
World::flushMqtt();
check(World::value($w, 'RUN_STATE_CURRENT_STATE') === 'RUNNING', 'ein Status-Push über den neuen Client erreicht das Gerät');
World::start();
$first = World::$mqtt;
Kernel::$instances[World::$bridge]['connection'] = 0; // Bridge without MQTT parent; the old client keeps Symcon12345
IPS_SetProperty(World::$bridge, 'MQTTClientID', 0);
IPS_ApplyChanges(World::$bridge);
ob_start();
LGTQ_UISetupMqttConnection(World::$bridge);
ob_end_clean();
$created = Kernel::$instances[World::$bridge]['connection'];
check($created !== $first && $created > 0 && IPS_GetProperty($created, 'ClientID') !== IPS_GetProperty($first, 'ClientID'),
    'neu angelegter Client bekommt eine eigene ClientID, nicht die eines anderen MQTT-Clients (' . IPS_GetProperty($created, 'ClientID') . ')');

section('N1 Ländertabelle (behoben)');
$sdk = json_decode((string)file_get_contents(__DIR__ . '/fixtures/sdk_regions.json'), true)['regions'];
$wrong = [];
$total = 0;
foreach ($sdk as $region => $countries) {
    foreach ($countries as $c) {
        $total++;
        if (ThinQBridgeConfig::resolveRegion($c) !== $region) {
            $wrong[] = $c . '→' . ThinQBridgeConfig::resolveRegion($c) . ' (SDK ' . $region . ')';
        }
    }
}
check($wrong === [], sprintf('Region je Land wie LGs SDK (%d Länder)', $total) . ($wrong === [] ? '' : ': ' . implode(', ', array_slice($wrong, 0, 5))));
World::start(['CountryCode' => 'XX']);
check(ThinQBridgeConfig::resolveRegion('XX') === '' && Kernel::$instances[World::$bridge]['status'] === IS_INACTIVE, 'unbekanntes Land: keine Region, die Bridge meldet Status 104 statt still KIC zu nehmen');

section('N4 Exportierte Funktionen (behoben)');
// The module API: form buttons, script functions and the timer targets (RenewEvents, InitialSetup, UpdateEnergy).
$api = ['lgtq_controldevice', 'lgtq_getdeviceprofile', 'lgtq_getdevicestatus', 'lgtq_getdevices', 'lgtq_renewall', 'lgtq_renewevents',
    'lgtq_subscribeall', 'lgtq_subscribedevice', 'lgtq_syncdevices', 'lgtq_testconnection', 'lgtq_uigeneratemqttclientcerts',
    'lgtq_uisetupmqttconnection', 'lgtq_unsubscribeall', 'lgtq_unsubscribedevice', 'lgtq_update',
    'lgtqd_autosubscribe', 'lgtqd_cleanupvariables', 'lgtqd_controldevice', 'lgtqd_initialsetup', 'lgtqd_reapplypresentations',
    'lgtqd_uicleanuppreview', 'lgtqd_uiexportsupportbundle', 'lgtqd_updateenergy', 'lgtqd_updatestatus'];
$exported = array_map('strtolower', Kernel::exportedFunctions());
check(array_diff($api, $exported) === [], 'Modul-API bleibt als LGTQ_-/LGTQD_-Funktionen erhalten');
$internal = array_values(array_diff($exported, $api));
check($internal === [], 'nur die Modul-API erscheint als LGTQ_-/LGTQD_-Funktion' . ($internal === [] ? '' : sprintf(' (%d weitere, u. a. %s)', count($internal), implode(', ', array_slice($internal, 0, 4)))));
World::start();
World::example('washer');
World::liveAc();
$dangling = [];
foreach (Kernel::$instances as $id => $inst) {
    foreach ($inst['timers'] as $name => $t) {
        if (preg_match('/^\s*(\w+)\s*\(/', (string)$t['script'], $m) !== 1 || !function_exists($m[1])) {
            $dangling[] = "#$id $name: " . $t['script'];
        }
    }
}
check($dangling === [], 'jedes Timer-Skript ruft eine exportierte Funktion' . ($dangling === [] ? '' : ': ' . implode(', ', $dangling)));

done();
