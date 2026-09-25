<?php

declare(strict_types=1);

/*
 * Findings of the review from 25.09.2026, Bridge side, as open checks: befund() reports
 * OFFEN while the defect is there and BEHOBEN once it is gone (then turn it into check()).
 * IDs: F = the 15 reported findings, N = the ones cut by the 15-item cap, W = found while
 * building this bench. Device side: review_device_test.php.
 */

require __DIR__ . '/bootstrap.php';

section('F1 Geheimnisse im Protokoll');
World::start(['Debug' => true]);
World::quiet();
LGTQ_GetDevices(World::$bridge);
$inLog = substr_count(implode("\n", array_column(Kernel::$log, 'text')), World::$cloud->pat);
$inDebug = substr_count(World::debugText(World::$bridge), World::$cloud->pat);
$key = base64_encode("-----BEGIN PRIVATE KEY-----\nPRUEFSTANDGEHEIM\n-----END PRIVATE KEY-----\n");
$sock = IPS_CreateInstance(CLIENT_SOCKET_GUID);
IPS_SetProperty($sock, 'PrivateKey', $key);
IPS_ApplyChanges($sock);
IPS_ConnectInstance(World::$mqtt, $sock);
IPS_ApplyChanges(World::$bridge);
$keyInDebug = str_contains(World::debugText(World::$bridge), $key);
befund('F1', $inLog === 0 && $inDebug === 0 && !$keyInDebug, 'Debug schreibt weder den PAT noch den privaten Schlüssel',
    sprintf('ein GetDevices: PAT %d× im Meldungsprotokoll (Datei), %d× im Debug; PrivateKey des Client Sockets %s', $inLog, $inDebug, $keyInDebug ? 'im Debug' : 'nicht im Debug'));

section('F2 Topicfilter');
World::start([], false);
[$dev, $did] = World::example('water_heater');
World::quiet();
World::$cloud->deviceReports($did, ['temperature' => ['currentTemperature' => 47]]);
World::flushMqtt();
$template = World::value($dev, 'TEMPERATURE_CURRENT_TEMPERATURE') === 47.0;
IPS_SetProperty(World::$bridge, 'MQTTTopicFilter', 'app/clients/+/push');
IPS_ApplyChanges(World::$bridge);
World::quiet();
World::$cloud->deviceReports($did, ['temperature' => ['currentTemperature' => 48]]);
World::flushMqtt();
$plus = World::value($dev, 'TEMPERATURE_CURRENT_TEMPERATURE') === 48.0;
$warn = World::warningsLike('/preg_match/');
befund('F2', $template && $plus && $warn === [], 'Pushes kommen mit dem Standardfilter {ClientID} und mit + an',
    sprintf('Standard "app/clients/{ClientID}/push": %s; "app/clients/+/push": %s%s', $template ? 'kommt an' : 'verworfen',
        $plus ? 'kommt an' : 'verworfen', $warn !== [] ? ' — ' . $warn[0] : ''));

section('F4 Erneuerung der Event-Abos');
World::start();
Kernel::advance(10); // the device subscribes ten seconds after the Bridge armed its timer
[$dev, $did] = World::example('water_heater');
$gap = 0;
$first = null;
for ($t = 60; $t <= 48 * 3600; $t += 60) {
    Kernel::advance(60);
    if (!isset(World::$cloud->activeEventSubs()[$did])) {
        $gap += 60;
        $first ??= $t;
    }
}
befund('F4', $gap === 0, 'Event-Abo bleibt 48 h ohne Lücke bestehen',
    sprintf('%d min ohne Abo in 48 h (erste Lücke nach %.1f h) — Timer alle 23 h 55 min erneuert nur, was in den nächsten 5 min abläuft', $gap / 60, ($first ?? 0) / 3600));

section('F6 DEVICE_PUSH');
World::start();
[$w, $wid] = World::example('washer');
World::quiet();
World::$cloud->devicePush($wid, 'WASHING_IS_COMPLETE');
World::flushMqtt();
$push = World::value($w, 'PUSH_LAST');
$polluted = array_values(array_intersect(array_keys(json_decode((string)World::attr($w, 'LastStatus'), true) ?: []), ['serviceId', 'deviceType', 'userList', 'pushType', 'pushCode']));
befund('F6', $push === 'WASHING_IS_COMPLETE' && $polluted === [], 'pushCode landet in PUSH_LAST, nicht im Gerätestatus',
    sprintf('PUSH_LAST "%s"; LastStatus bekam %s', $push, $polluted === [] ? 'nichts' : implode(', ', $polluted)));

section('F14 MQTT-Einrichtung');
World::start();
ob_start();
LGTQ_UISetupMqttConnection(World::$bridge);
$out = trim((string)ob_get_clean());
befund('F14', preg_match('/Error|Fehler|Call to/', $out) === 0, 'Einrichtung endet ohne Fehlermeldung', substr($out, (int)strrpos($out, "\n") + 1));

section('W1 eingestellter MQTT-Client');
World::start();
$other = World::mqttClient('SymconAnders');
IPS_SetProperty(World::$bridge, 'MQTTClientID', $other);
IPS_ApplyChanges(World::$bridge);
ob_start();
LGTQ_UISetupMqttConnection(World::$bridge);
ob_end_clean();
$used = Kernel::$instances[World::$bridge]['connection'];
befund('W1', $used === $other, 'Assistent nimmt den MQTT-Client aus MQTTClientID',
    sprintf('eingestellt #%d, verbunden #%d — der Assistent liest IPS_GetInstance()[\'ModuleID\'], Symcon liefert die GUID unter ModuleInfo', $other, $used));

section('N1 Ländertabelle');
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
$unknown = ThinQBridgeConfig::resolveRegion('XX');
befund('N1', $wrong === [] && $unknown !== 'KIC', 'Region je Land wie LGs SDK (pythinqconnect country.py)',
    sprintf('%d von %d Ländern falsch, u. a. %s; unbekanntes Land wird still %s', count($wrong), $total, implode(', ', array_slice($wrong, 0, 5)), $unknown));

section('N4 Exportierte Funktionen');
// The module API: form buttons, script functions and the timer targets (RenewEvents, InitialSetup, UpdateEnergy).
$api = ['lgtq_controldevice', 'lgtq_getdeviceprofile', 'lgtq_getdevicestatus', 'lgtq_getdevices', 'lgtq_renewall', 'lgtq_renewevents',
    'lgtq_subscribeall', 'lgtq_subscribedevice', 'lgtq_syncdevices', 'lgtq_testconnection', 'lgtq_uigeneratemqttclientcerts',
    'lgtq_uisetupmqttconnection', 'lgtq_unsubscribeall', 'lgtq_unsubscribedevice', 'lgtq_update',
    'lgtqd_autosubscribe', 'lgtqd_cleanupvariables', 'lgtqd_controldevice', 'lgtqd_initialsetup', 'lgtqd_reapplypresentations',
    'lgtqd_uicleanuppreview', 'lgtqd_uiexportsupportbundle', 'lgtqd_updateenergy', 'lgtqd_updatestatus'];
$exported = array_map('strtolower', Kernel::exportedFunctions());
check(array_diff($api, $exported) === [], 'Modul-API bleibt als LGTQ_-/LGTQD_-Funktionen erhalten');
$internal = array_values(array_diff($exported, $api));
befund('N4', $internal === [], 'Nur die Modul-API erscheint als LGTQ_-/LGTQD_-Funktion',
    sprintf('%d weitere Funktionen, u. a. %s', count($internal), implode(', ', array_slice($internal, 0, 4))));
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
