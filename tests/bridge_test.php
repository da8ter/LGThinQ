<?php

declare(strict_types=1);

/*
 * Bridge: configuration, HTTP client against the fake LG cloud, device list, event/push
 * subscriptions, MQTT routing to the devices, ForwardData contract, MQTT setup wizard.
 * Known defects of the review from 25.09.2026 live in review_test.php, not here.
 */

require __DIR__ . '/bootstrap.php';

$bridge = static fn(): LGThinQBridge => Kernel::$instances[World::$bridge]['object'];

section('Konfiguration');
World::start();
check(Kernel::$instances[World::$bridge]['status'] === IS_ACTIVE, 'Bridge mit PAT und Land ist aktiv (102)');
check(World::timer(World::$bridge, 'EventRenewTimer')['interval'] === (24 * 3600 - 5 * 60) * 1000, 'Erneuerungs-Timer: TTL 24 h minus 5 min Vorlauf');
check(World::attr(World::$bridge, 'ClientID') === World::$clientId, 'ClientID folgt der ClientID des MQTT-Clients');
check(World::attr(World::$bridge, 'AccessTokenBackup') === '', 'keine Kopie des PAT im Attribut AccessTokenBackup');
Kernel::$instances[World::$bridge]['attributes']['AccessTokenBackup'] = World::$cloud->pat; // left behind by an earlier version
IPS_ApplyChanges(World::$bridge);
check(World::attr(World::$bridge, 'AccessTokenBackup') === '', 'eine alte PAT-Kopie im Attribut wird beim Übernehmen gelöscht');
IPS_SetProperty(World::$bridge, 'AccessToken', '');
IPS_ApplyChanges(World::$bridge);
check(Kernel::$instances[World::$bridge]['status'] === IS_INACTIVE, 'ohne PAT: Status 104');
check(World::timer(World::$bridge, 'EventRenewTimer')['interval'] === 0, 'ohne PAT: kein Erneuerungs-Timer');
IPS_SetProperty(World::$bridge, 'AccessToken', World::$cloud->pat);
IPS_SetProperty(World::$bridge, 'EventTTLHrs', 25);
IPS_ApplyChanges(World::$bridge);
check(Kernel::$instances[World::$bridge]['status'] === IS_INACTIVE, 'TTL 25 h ist ungültig (1..24): Status 104');
IPS_SetProperty(World::$bridge, 'EventTTLHrs', 24);
IPS_ApplyChanges(World::$bridge);
$form = json_decode($bridge()->GetConfigurationForm(), true);
$fields = array_column(array_filter($form['elements'], 'is_array'), 'value', 'name');
check(($fields['ClientID'] ?? '') === World::$clientId && ($fields['CountryCode'] ?? '') === 'DE', 'Formular zeigt ClientID und Land');
$pat = array_values(array_filter($form['elements'], static fn($e): bool => is_array($e) && ($e['name'] ?? '') === 'AccessToken'))[0] ?? [];
check(($pat['type'] ?? '') === 'PasswordTextBox' && !array_key_exists('value', $pat), 'PAT-Feld ist ein Passwortfeld und das Formular setzt den PAT nicht ein');

section('HTTP-Client');
World::quiet();
$devices = json_decode(LGTQ_GetDevices(World::$bridge), true);
$r = World::$cloud->requests[0];
check(count(World::$cloud->requests) === 1 && $r['method'] === 'GET' && $r['path'] === 'devices', 'LGTQ_GetDevices: genau ein GET devices');
check($r['host'] === 'api-eic.lgthinq.com', 'DE geht an den europäischen Server (EIC)');
check($r['headers']['authorization'] === 'Bearer ' . World::$cloud->pat, 'Authorization: Bearer <PAT>');
check($r['headers']['x-country'] === 'DE' && $r['headers']['x-client-id'] === World::$clientId && $r['headers']['x-service-phase'] === 'OP', 'x-country, x-client-id, x-service-phase');
check($r['headers']['x-api-key'] === LGThinQBridge::API_KEY && strlen($r['headers']['x-message-id']) >= 16, 'x-api-key und x-message-id');
check($devices === [], 'leeres Konto: leere Geräteliste');
LGTQ_GetDevices(World::$bridge);
check(World::$cloud->requests[0]['headers']['x-message-id'] !== World::$cloud->requests[1]['headers']['x-message-id'], 'x-message-id ist je Anfrage neu');

$washer = World::$cloud->addExampleDevice('washer');
$list = json_decode(LGTQ_GetDevices(World::$bridge), true);
check(count($list) === 1 && $list[0]['deviceId'] === $washer && $list[0]['deviceInfo']['deviceType'] === 'DEVICE_WASHER', 'Geräteliste wie die API sie liefert (response ausgepackt)');
$status = json_decode(LGTQ_GetDeviceStatus(World::$bridge, $washer), true);
check(($status[0]['runState']['currentState'] ?? '') === 'INITIAL', 'LGTQ_GetDeviceStatus liefert den Status');
$profile = json_decode(LGTQ_GetDeviceProfile(World::$bridge, $washer), true);
check(isset($profile['property'], $profile['error'], $profile['notification']), 'LGTQ_GetDeviceProfile liefert property, error, notification');

World::$cloud->fail('GET devices/{id}/state', 404, (string)json_encode(['messageId' => 'x', 'error' => ['message' => 'Not existing device', 'code' => '1222']]));
$caught = '';
try {
    LGTQ_GetDeviceStatus(World::$bridge, $washer);
} catch (\Throwable $e) {
    $caught = $e->getMessage();
}
check(str_contains($caught, 'HTTP 404 API error 1222: Not existing device'), 'API-Fehler wird mit Code und Text geworfen: ' . $caught);
World::$cloud->down = true;
$caught = '';
try {
    LGTQ_GetDevices(World::$bridge);
} catch (\Throwable $e) {
    $caught = $e->getMessage();
}
World::$cloud->down = false;
check(str_starts_with($caught, 'HTTP error calling https://api-eic.lgthinq.com/devices: ') && str_contains($caught, 'getaddrinfo'), 'Netzfehler: "HTTP error calling …" mit Ursache');
check(Kernel::$warnings === [], 'keine Warnungen bei Fehlern (alles abgefangen)');

LGTQ_SyncDevices(World::$bridge);
check(count(json_decode((string)World::attr(World::$bridge, 'Devices'), true)) === 1, 'SyncDevices schreibt die Geräteliste ins Attribut');
check(World::logLines('/Geräteliste aktualisiert: 1 Geräte\./') !== [], 'SyncDevices meldet die Anzahl (deutsch, wie im Symcon des Nutzers)');
ob_start();
LGTQ_TestConnection(World::$bridge);
$out = (string)ob_get_clean();
check($out === 'Verbindung OK. Geräte: 1' && World::logLines('/Verbindung OK\. Geräte: 1/') !== [], 'TestConnection meldet Verbindung und Geräteanzahl');

section('Event- und Push-Abos');
World::quiet();
check(LGTQ_SubscribeDevice(World::$bridge, $washer, true, true) === true, 'SubscribeDevice meldet Erfolg');
$paths = array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], World::$cloud->requests);
check($paths === ['POST event/' . $washer . '/subscribe', 'POST push/devices', 'POST push/' . $washer . '/subscribe'], 'Reihenfolge: Event-Abo, Push-Client, Push-Abo');
check(World::$cloud->requests[0]['body'] === ['expire' => ['unit' => 'HOUR', 'timer' => 24]], 'Event-Abo mit expire HOUR 24');
check((World::$cloud->eventSubs[$washer]['expiresAt'] ?? 0) === Kernel::now() + 86400, 'Cloud: Event-Abo läuft in 24 h ab');
check((int)(json_decode((string)World::attr(World::$bridge, 'EventSubscriptions'), true)[$washer]['expiresAt'] ?? 0) === Kernel::now() + 86400, 'Bridge merkt sich den Ablauf');
World::quiet();
check(LGTQ_SubscribeDevice(World::$bridge, $washer, true, true) === true && World::$cloud->requests === [], 'zweites SubscribeDevice gleich danach: Erfolg ohne Anfrage (Abo gültig, Push in der Abkühlzeit)');
Kernel::advance(31 * 60);
World::quiet();
check(LGTQ_SubscribeDevice(World::$bridge, $washer, true, true) === true, 'nach der Abkühlzeit (30 min): weiterhin Erfolg');
$paths = array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'] . ' ' . $r['status'], World::$cloud->requests);
check($paths === ['POST push/devices 400', 'POST push/' . $washer . '/subscribe 404'], 'kein neues Event-Abo; Push antwortet "already subscribed" (4001/1207) und gilt als erledigt');
World::quiet();
LGTQ_RenewAll(World::$bridge);
check(array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], World::$cloud->requests) === ['POST event/' . $washer . '/subscribe'], 'RenewAll erneuert das Event-Abo sofort');
check(LGTQ_UnsubscribeDevice(World::$bridge, $washer, true, true) === true && World::$cloud->eventSubs === [] && World::$cloud->pushSubs === [], 'UnsubscribeDevice räumt beide Abos in der Cloud');
check(json_decode((string)World::attr(World::$bridge, 'EventSubscriptions'), true) === [], 'und in der Bridge');

section('MQTT-Routing');
[$dev, $did] = World::example('water_heater');
World::quiet();
World::$cloud->deviceReports($did, ['temperature' => ['currentTemperature' => 47]]);
check(World::flushMqtt() === 1, 'DEVICE_STATUS erreicht den MQTT-Client (abonniertes Topic)');
check(World::value($dev, 'TEMPERATURE_CURRENT_TEMPERATURE') === 47.0, 'Gerät übernimmt den Wert aus report');
check(World::$cloud->calls('GET *') === [], 'ein Push kostet keine API-Anfrage');
$lg = FakeThinQCloud::examples()['mqtt']['DEVICE_STATUS'];
$lg['event']['deviceId'] = $did;
$lg['event']['report'] = ['temperature' => ['currentTemperature' => 49]];
World::mqtt(World::topic(), $lg);
check(World::value($dev, 'TEMPERATURE_CURRENT_TEMPERATURE') === 49.0, 'LGs Beispielnachricht (event_message_example) wird verarbeitet');
World::mqtt(World::topic(), ['event' => ['pushType' => 'DEVICE_STATUS', 'deviceId' => $did, 'report' => ['temperature' => ['currentTemperature' => 11]]]], true);
check(World::value($dev, 'TEMPERATURE_CURRENT_TEMPERATURE') === 49.0, 'retained Nachricht wird ignoriert (IgnoreRetained)');
World::mqtt(World::topic(), ['event' => ['pushType' => 'DEVICE_STATUS', 'deviceId' => 'fremdes-geraet', 'report' => ['temperature' => ['currentTemperature' => 12]]]]);
check(World::value($dev, 'TEMPERATURE_CURRENT_TEMPERATURE') === 49.0, 'fremde deviceId erreicht das Gerät nicht');
$h = Kernel::$instances[World::$mqtt]['handler'];
check($h->deliver('app/clients/andere/push', '{}') === false, 'nicht abonniertes Topic kommt gar nicht erst an');
check(Kernel::$warnings === [], 'Routing ohne Warnungen');

World::quiet();
$new = World::$cloud->addExampleDevice('dryer');
World::$cloud->accountEvent('DEVICE_REGISTERED', $new);
World::flushMqtt();
check(isset(World::$cloud->eventSubs[$new], World::$cloud->pushSubs[$new]), 'DEVICE_REGISTERED: Bridge abonniert das neue Gerät');
World::$cloud->accountEvent('DEVICE_UNREGISTERED', $new);
World::flushMqtt();
check(!isset(World::$cloud->eventSubs[$new]) && !isset(World::$cloud->pushSubs[$new]), 'DEVICE_UNREGISTERED: Bridge kündigt beide Abos');

section('ForwardData-Vertrag (Gerät → Bridge)');
$fwd = static fn(array $buffer): array => json_decode((string)$bridge()->ForwardData(json_encode(['DataID' => '{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}', 'Buffer' => json_encode($buffer)])), true);
check($fwd(['Action' => 'GetStatus', 'DeviceID' => $did])['success'] === true, 'GetStatus');
check(isset($fwd(['Action' => 'GetProfile', 'DeviceID' => $did])['profile']['property']), 'GetProfile liefert das ganze Profil');
World::quiet();
$res = $fwd(['Action' => 'Control', 'DeviceID' => $did, 'Payload' => ['waterHeaterJobMode' => ['currentJobMode' => 'AUTO']]]);
$ctl = World::$cloud->calls('POST devices/{id}/control');
check($res['success'] === true && $ctl[0]['headers']['x-conditional-control'] === 'false', 'Control mit x-conditional-control: false');
check(World::$cloud->devices[$did]['state']['waterHeaterJobMode']['currentJobMode'] === 'AUTO', 'Cloud übernimmt den Befehl');
$res = $fwd(['Action' => 'Control', 'DeviceID' => $did, 'Payload' => ['waterHeaterJobMode' => ['currentJobMode' => 'SAUNA']]]);
check($res['success'] === false && str_contains((string)$res['error'], 'Befehl abgelehnt'), 'abgelehnter Befehl: success=false mit Grund');
check($fwd(['Action' => 'GetStatus'])['error'] === 'DeviceID missing', 'fehlende DeviceID');
check($fwd(['Action' => 'Unbekannt'])['error'] === 'unknown action', 'unbekannte Aktion');
check(json_decode((string)$bridge()->ForwardData('kein json'), true)['error'] === 'invalid payload', 'kaputtes JSON');

section('MQTT-Einrichtung');
World::start();
World::quiet();
ob_start();
LGTQ_UISetupMqttConnection(World::$bridge);
$out = (string)ob_get_clean();
$mqttIds = IPS_GetInstanceListByModuleID(FakeMqttClient::MODULE_ID);
$sockets = IPS_GetInstanceListByModuleID(CLIENT_SOCKET_GUID);
check(count($sockets) === 1, 'Assistent legt einen Client Socket an');
$sock = json_decode((string)IPS_GetConfiguration($sockets[0]), true);
check($sock['Host'] === 'localhost' && $sock['Port'] === 8883 && $sock['UseSSL'] === true && $sock['Open'] === true, 'Socket: Host und Port aus GET route, TLS, geöffnet');
$chain = (string)base64_decode($sock['Certificate']);
check(openssl_x509_read(explode("-----END CERTIFICATE-----", $chain)[0] . "-----END CERTIFICATE-----\n") !== false, 'Socket: Client-Zertifikat (von LG signiert) als Base64-Kette');
check(openssl_pkey_get_private((string)base64_decode($sock['PrivateKey'])) !== false, 'Socket: privater Schlüssel passt ins Format');
check(openssl_x509_check_private_key(explode("-----END CERTIFICATE-----", $chain)[0] . "-----END CERTIFICATE-----\n", (string)base64_decode($sock['PrivateKey'])), 'Zertifikat und Schlüssel gehören zusammen');
check((string)base64_decode($sock['CertificateAuthority']) === World::$cloud->caPem, 'CA: Amazon-Root-CA-Download (hier die Prüfstand-CA)');
$mqttNow = (int)Kernel::$instances[World::$bridge]['connection'];
$mqttCfg = json_decode((string)IPS_GetConfiguration($mqttNow), true);
check(json_decode($mqttCfg['Subscriptions'], true) === [['Topic' => 'app/clients/' . World::$clientId . '/push', 'QoS' => 0]], 'MQTT-Client abonniert app/clients/<ClientID>/push');
check(Kernel::$instances[$mqttNow]['connection'] === $sockets[0], 'MQTT-Client hängt am Socket, Bridge am MQTT-Client');
check(World::prop(World::$bridge, 'MQTTClientID') === $mqttNow && World::prop(World::$bridge, 'UseMQTT') === true, 'Bridge merkt sich den MQTT-Client');
check(count(World::$cloud->calls('POST client/certificate')) === 1, 'genau ein Zertifikatsantrag');
check(str_starts_with(ltrim($out), 'Done') || str_starts_with(ltrim($out), 'Fertig'), 'Ausgabe beginnt mit "Done"');

section('Zertifikats-Paket');
$zip = LGTQ_UIGenerateMQTTClientCerts(World::$bridge);
check(str_starts_with($zip, 'data:application/zip;base64,'), 'UIGenerateMQTTClientCerts liefert ein ZIP');
$tmp = tempnam(sys_get_temp_dir(), 'lgtq');
file_put_contents($tmp, base64_decode(substr($zip, strlen('data:application/zip;base64,'))));
$za = new ZipArchive();
check($za->open($tmp) === true && $za->numFiles >= 2, 'ZIP lässt sich öffnen (' . $za->numFiles . ' Dateien)');
$za->close();
unlink($tmp);

done();
