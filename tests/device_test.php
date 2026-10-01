<?php

declare(strict_types=1);

/*
 * Device: setup from profile and status, values from MQTT, control payloads the fake cloud
 * accepts, kernel start, deferred setup, self-heal, cleanup, presentations, support bundle.
 * Known defects of the review from 25.09.2026 live in review_test.php.
 */

require __DIR__ . '/bootstrap.php';

$pres = static fn(int $inst, string $ident): array => World::variable($inst, $ident)['presentation'] ?? [];

section('Einrichtung (Waschmaschine, LG-Beispiel)');
World::start();
[$w, $wid] = World::example('washer');
check(Kernel::$instances[$w]['connection'] === World::$bridge, 'Create() verbindet das Gerät mit der vorhandenen Bridge');
check(Kernel::$instances[$w]['status'] === IS_ACTIVE, 'Gerät mit DeviceID ist aktiv');
check(World::attr($w, 'DeviceType') === 'DEVICE_WASHER', 'Gerätetyp aus der Geräteliste');
$stored = json_decode((string)World::attr($w, 'LastProfile'), true);
check(isset($stored['property'], $stored['error'], $stored['notification']), 'gespeichertes Profil behält property, error und notification');
check(json_decode((string)World::value($w, 'INFO'), true) === ['deviceId' => $wid, 'alias' => 'Washer'], 'INFO mit deviceId und Alias');
check(IPS_GetName($w) === 'Washer', 'Instanzname folgt dem Alias');
$expected = ['CYCLE_CYCLE_COUNT', 'ERROR_LAST', 'INFO', 'LASTUPDATE', 'OPERATION_WASHER_OPERATION_MODE', 'PUSH_LAST',
    'REMOTE_CONTROL_ENABLE_REMOTE_CONTROL_ENABLED', 'RUN_STATE_CURRENT_STATE', 'STATUS', 'TIMER_RELATIVE_HOUR_TO_START',
    'TIMER_RELATIVE_MINUTE_TO_START', 'TIMER_REMAIN_HOUR', 'TIMER_REMAIN_MINUTE', 'TIMER_TOTAL_HOUR', 'TIMER_TOTAL_MINUTE'];
check(World::idents($w) === $expected, 'Variablen: ' . implode(', ', World::idents($w)));
check(World::value($w, 'RUN_STATE_CURRENT_STATE') === 'INITIAL' && World::value($w, 'REMOTE_CONTROL_ENABLE_REMOTE_CONTROL_ENABLED') === true, 'Startwerte aus GET state');
check(World::variable($w, 'RUN_STATE_CURRENT_STATE')['name'] === 'Betriebszustand', 'Variablenname übersetzt: ' . World::variable($w, 'RUN_STATE_CURRENT_STATE')['name']);
check(World::variable($w, 'OPERATION_WASHER_OPERATION_MODE')['action'] === $w, 'schreibbare Eigenschaft hat eine Aktion');
check(World::variable($w, 'RUN_STATE_CURRENT_STATE')['action'] === 0, 'nur lesbare Eigenschaft hat keine Aktion');
$p = $pres($w, 'OPERATION_WASHER_OPERATION_MODE');
check(($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_ENUMERATION && array_column(json_decode($p['OPTIONS'], true), 'Value') === ['START', 'STOP', 'POWER_OFF'], 'Befehls-Enum als Aufzählung mit den Werten aus value.w');
$p = $pres($w, 'TIMER_RELATIVE_HOUR_TO_START');
$ganzzahl = World::variable($w, 'TIMER_RELATIVE_HOUR_TO_START')['type'] === VARIABLETYPE_INTEGER;
check(($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SLIDER && $p['MIN'] === ($ganzzahl ? 0 : 0.0) && $p['MAX'] === ($ganzzahl ? 19 : 19.0) && $p['STEP_SIZE'] === ($ganzzahl ? 1 : 1.0),
    'Bereich als Schieberegler 0..19' . ($ganzzahl ? ' (Integer-Variable: ganzzahlige Grenzen, wie Symcon sie verlangt)' : ''));
check(($pres($w, 'REMOTE_CONTROL_ENABLE_REMOTE_CONTROL_ENABLED')['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SWITCH, 'Wahrheitswert als Schalter');
check(isset(World::$cloud->eventSubs[$wid], World::$cloud->pushSubs[$wid]), 'Gerät ist für Events und Pushes angemeldet');

section('Steuern (vor dem ersten Push, siehe F5 in review_test.php)');
World::quiet();
check(RequestAction(World::varId($w, 'OPERATION_WASHER_OPERATION_MODE'), 'START') === true, 'RequestAction START ist erfolgreich');
$ctl = World::$cloud->calls('POST devices/{id}/control');
check(count($ctl) === 1 && sameJson($ctl[0]['body'], FakeThinQCloud::examples()['devices']['washer']['commands'][0]['value']), 'Befehl entspricht LGs Beispiel: ' . json_encode($ctl[0]['body'] ?? null));
check(World::value($w, 'OPERATION_WASHER_OPERATION_MODE') === 'START', 'Variable übernimmt den gesendeten Wert');
World::quiet();
check(RequestAction(World::varId($w, 'TIMER_RELATIVE_HOUR_TO_START'), 3) === true, 'Startverzögerung 3 h senden');
$body = World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? [];
check(sameJson($body, ['timer' => ['relativeHourToStart' => 3], 'location' => ['locationName' => 'MAIN']]), 'Timerbefehl mit Zonen-Hülle: ' . json_encode($body));
check(RequestAction(World::varId($w, 'RUN_STATE_CURRENT_STATE'), 'END') === false, 'nur lesbare Variable: RequestAction liefert false');
check(World::warningsLike('/No valid action available/') !== [], 'und Symcon warnt (keine Aktion)');

section('Werte per MQTT');
World::quiet();
World::$cloud->deviceReports($wid, [['location' => ['locationName' => 'MAIN'], 'runState' => ['currentState' => 'RUNNING']]]);
Kernel::advance(30);
World::flushMqtt();
check(World::value($w, 'RUN_STATE_CURRENT_STATE') === 'RUNNING', 'report als Zonenliste: Wert übernommen');
check(World::value($w, 'LASTUPDATE') === Kernel::now(), 'LASTUPDATE bekommt die Push-Zeit');
check(json_decode((string)World::attr($w, 'LastStatus'), true)['runState']['currentState'] === 'RUNNING', 'LastStatus fortgeschrieben');

section('Leere Antwort und Ablehnung');
World::$cloud->fail('GET devices/{id}/state', 200, '{"messageId":"m","timestamp":"t","response":{}}');
LGTQD_UpdateStatus($w);
check(json_decode((string)World::attr($w, 'LastStatus'), true)['runState']['currentState'] === 'RUNNING' && World::value($w, 'RUN_STATE_CURRENT_STATE') === 'RUNNING',
    'ein leerer Status (HTTP 200 ohne Inhalt) behält den letzten bekannten');
World::$cloud->fail('POST devices/{id}/control', 400, '{"messageId":"m","timestamp":"t","error":{"code":"2201","message":"Not supported command"}}');
$caught = '';
try {
    LGTQD_ControlDevice($w, '{"operation":{"washerOperationMode":"STOP"}}');
} catch (\Throwable $e) {
    $caught = $e->getMessage();
}
check(str_contains($caught, 'Not supported command'), 'ControlDevice: LGs Ablehnung kommt als Ausnahme mit LGs Meldung an (' . $caught . ')');

section('Befehl ohne Rückmeldung');
// LG accepts a command, but its confirmation (DEVICE_STATUS) gets lost; the next partial report must
// not bring back the old value from the stored status (live on 25.09.2026: POWER_ON fell back to POWER_OFF)
World::start();
[$ac, $acid] = World::liveAc();
World::quiet();
$mode = World::value($ac, 'OPERATION_AIR_CON_OPERATION_MODE') === 'POWER_ON' ? 'POWER_OFF' : 'POWER_ON';
check(RequestAction(World::varId($ac, 'OPERATION_AIR_CON_OPERATION_MODE'), $mode) === true, 'Klimaanlage ' . $mode);
World::$cloud->drainMqtt();
World::$cloud->deviceReports($acid, ['temperature' => ['currentTemperature' => 21]]);
World::flushMqtt();
check(World::value($ac, 'OPERATION_AIR_CON_OPERATION_MODE') === $mode && World::value($ac, 'TEMPERATURE_CURRENT_TEMPERATURE') === 21.0,
    'die Teilmeldung danach kommt an und setzt den Befehl nicht zurück (' . json_encode(World::value($ac, 'OPERATION_AIR_CON_OPERATION_MODE')) . ')');
$st = json_decode((string)World::attr($ac, 'LastStatus'), true);
check(($st['operation']['airConOperationMode'] ?? null) === $mode && isset($st['runState'], $st['airConJobMode'], $st['temperature']['targetTemperature']),
    'der gespeicherte Status behält alles andere und hat den Befehl');
LGTQD_ControlDevice($ac, (string)json_encode(['temperature' => ['unit' => 'C', 'targetTemperature' => 25]]));
World::$cloud->drainMqtt();
check((json_decode((string)World::attr($ac, 'LastStatus'), true)['temperature']['targetTemperature'] ?? null) === 25, 'auch LGTQD_ControlDevice aus einem Skript schreibt den gespeicherten Stand fort');
[$wz, $wzid] = World::example('washer');
World::quiet();
check(RequestAction(World::varId($wz, 'TIMER_RELATIVE_HOUR_TO_START'), 5) === true, 'Waschmaschine: Startverzögerung 5 h');
World::$cloud->drainMqtt();
World::$cloud->deviceReports($wzid, [['location' => ['locationName' => 'MAIN'], 'runState' => ['currentState' => 'RUNNING']]]);
World::flushMqtt();
$st = json_decode((string)World::attr($wz, 'LastStatus'), true);
check(World::value($wz, 'TIMER_RELATIVE_HOUR_TO_START') === 5 && World::value($wz, 'RUN_STATE_CURRENT_STATE') === 'RUNNING'
    && ($st['timer']['relativeHourToStart'] ?? null) === 5 && ($st['location']['locationName'] ?? null) === 'MAIN', 'mit Zonen-Hülle ebenso: der Befehl steht in der Zone');
[$ck] = World::example('cooktop');
World::quiet();
$zones = json_decode((string)World::attr($ck, 'LastStatus'), true);
check(RequestAction(World::varId($ck, 'OPERATION_OPERATION_MODE'), 'POWER_OFF') === true, 'Kochfeld: geräteweiter Befehl (extensionProperty)');
$after = json_decode((string)World::attr($ck, 'LastStatus'), true);
check(ThinQShape::isZoneList($after) && $after === $zones, 'die Zonenliste bleibt unverändert, der Befehl hat dort keine Zone');

section('Kühlschrank: Temperatur je Fach');
World::start();
[$f, $fid] = World::example('refrigerator');
check(in_array('FRIDGE_TEMPERATURE_TARGET_TEMPERATURE', World::idents($f), true) && in_array('FREEZER_TEMPERATURE_TARGET_TEMPERATURE', World::idents($f), true), 'Solltemperatur je Fach (FRIDGE, FREEZER)');
check(World::value($f, 'FRIDGE_TEMPERATURE_TARGET_TEMPERATURE') === 8 && World::value($f, 'FREEZER_TEMPERATURE_TARGET_TEMPERATURE') === -13, 'Werte aus der Elementliste per locationName');
World::quiet();
check(RequestAction(World::varId($f, 'FRIDGE_TEMPERATURE_TARGET_TEMPERATURE'), 4) === true, 'Kühlfach auf 4 °C');
$body = World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? [];
check(sameJson($body, ['temperatureInUnits' => ['unit' => 'C', 'locationName' => 'FRIDGE', 'targetTemperatureC' => 4]]), 'Befehl über temperatureInUnits mit unit und locationName: ' . json_encode($body));

section('Klimaanlage (echtes Gerät aus dem Docker-System)');
World::start();
[$ac, $acid] = World::liveAc();
check(World::attr($ac, 'DeviceType') === 'DEVICE_AIR_CONDITIONER' && count(World::idents($ac)) > 20, count(World::idents($ac)) . ' Variablen angelegt');
check(World::value($ac, 'TEMPERATURE_CURRENT_TEMPERATURE') === 21.0 && World::value($ac, 'AIR_CON_JOB_MODE_CURRENT_JOB_MODE') === 'COOL', 'Ist-Temperatur und Betriebsart aus dem Status');
World::quiet();
check(RequestAction(World::varId($ac, 'TEMPERATURE_TARGET_TEMPERATURE'), 24) === true, 'Solltemperatur 24 °C');
check(sameJson(World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? [], ['temperature' => ['unit' => 'C', 'targetTemperature' => 24]]), 'Befehl temperature.targetTemperature mit unit C');
World::quiet();
check(RequestAction(World::varId($ac, 'OPERATION_AIR_CON_OPERATION_MODE'), 'POWER_ON') === true, 'Einschalten');
check((World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? []) === ['operation' => ['airConOperationMode' => 'POWER_ON']], 'Befehl operation.airConOperationMode');
Kernel::advance(5);
World::flushMqtt();
check(World::value($ac, 'OPERATION_AIR_CON_OPERATION_MODE') === 'POWER_ON' && World::value($ac, 'TEMPERATURE_TARGET_TEMPERATURE') === 24.0, 'DEVICE_STATUS der Cloud bestätigt beide Werte (Solltemperatur als Float, 0,5er-Schritte)');

section('Kernelstart');
World::start();
[$w, $wid] = World::example('washer');
World::quiet();
World::$cloud->devices[$wid]['state'][0]['runState']['currentState'] = 'PAUSE';
Kernel::restart();
check(World::value($w, 'RUN_STATE_CURRENT_STATE') === 'PAUSE', 'nach IPS_KERNELSTARTED holt das Gerät den Status neu');
check(Kernel::$instances[World::$bridge]['status'] === IS_ACTIVE && Kernel::$instances[$w]['status'] === IS_ACTIVE, 'Bridge und Gerät wieder aktiv');
check(count(World::$cloud->calls('GET devices/{id}/state')) === 1, 'genau ein Statusabruf beim Start');

section('Bridge beim Anlegen noch nicht aktiv');
World::start();
IPS_SetProperty(World::$bridge, 'AccessToken', '');
IPS_ApplyChanges(World::$bridge);
[$w, $wid] = World::example('washer');
check(World::idents($w) === ['INFO', 'LASTUPDATE', 'STATUS'] && World::timer($w, 'InitialUpdateStatus')['interval'] === 5000, 'nur Grundvariablen, Nachholen in 5 s geplant');
IPS_SetProperty(World::$bridge, 'AccessToken', World::$cloud->pat);
IPS_ApplyChanges(World::$bridge);
Kernel::advance(6);
check(in_array('RUN_STATE_CURRENT_STATE', World::idents($w), true) && World::value($w, 'RUN_STATE_CURRENT_STATE') === 'INITIAL', 'InitialSetup legt die Variablen an und setzt Werte');
check(World::timer($w, 'InitialUpdateStatus')['interval'] === 0, 'Nachhol-Timer läuft nur einmal');

section('Selbstheilung: nur Grundvariablen');
World::start();
[$w, $wid] = World::example('washer');
foreach (World::idents($w) as $ident) {
    if (!in_array($ident, ['INFO', 'STATUS', 'LASTUPDATE'], true)) {
        Kernel::deleteObject(World::varId($w, $ident));
    }
}
World::quiet();
World::$cloud->fail('GET devices/{id}/profile', 503, '', -1); // the heal must not need the API
World::$cloud->deviceReports($wid, [['location' => ['locationName' => 'MAIN'], 'runState' => ['currentState' => 'RINSING']]]);
World::flushMqtt();
World::$cloud->faults = [];
check(count(World::idents($w)) === 15 && World::value($w, 'RUN_STATE_CURRENT_STATE') === 'RINSING', 'Push stellt die Variablen aus dem gespeicherten Profil wieder her, auch wenn GET profile scheitert');

section('Aufräumen');
World::start();
[$w, $wid] = World::example('washer');
$stray = IPS_CreateVariable(VARIABLETYPE_STRING);
IPS_SetParent($stray, $w);
IPS_SetIdent($stray, 'ALTE_VARIABLE');
IPS_SetName($stray, 'Alt');
$preview = LGTQD_UICleanupPreview($w);
check(str_contains($preview, 'ALTE_VARIABLE') && str_contains($preview, 'Summe: 1'), 'Vorschau nennt nur die unbekannte Variable');
check(str_contains(LGTQD_CleanupVariables($w, false), 'deleted=0') && IPS_VariableExists($stray), 'CleanupVariables(false) löscht nichts');
check(str_contains(LGTQD_CleanupVariables($w, true), 'deleted=1') && !IPS_VariableExists($stray) && count(World::idents($w)) === 15, 'CleanupVariables(true) löscht nur sie');

section('Darstellungen neu anwenden');
Kernel::$variables[World::varId($w, 'TIMER_RELATIVE_HOUR_TO_START')]['presentation'] = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
LGTQD_ReapplyPresentations($w);
check(($pres($w, 'TIMER_RELATIVE_HOUR_TO_START')['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SLIDER, 'ReapplyPresentations stellt den Schieberegler wieder her');

section('Energie-Darstellung und Namen neu setzen');
World::start();
Kernel::$language = 'de';
[$ac, $acid] = World::liveAc();
$energyPres = $pres($ac, 'ENERGY_YESTERDAY');
check(($energyPres['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION && ($energyPres['SUFFIX'] ?? '') === ' Wh' && ($energyPres['DIGITS'] ?? null) === 0
    && !array_key_exists('MULTILINE', $energyPres) && !array_key_exists('OPTIONS', $energyPres),
    'Energievariablen bekommen die Wh-Darstellung nur mit den Parametern, die Symcon 9.1 für Float kennt');
Kernel::$variables[World::varId($ac, 'ENERGY_YESTERDAY')]['presentation'] = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
LGTQD_ReapplyPresentations($ac);
check(($pres($ac, 'ENERGY_YESTERDAY')['SUFFIX'] ?? '') === ' Wh', 'ReapplyPresentations setzt auch die Energie-Darstellung neu');
$stringPres = $pres($ac, 'ERROR_LAST');
check(($stringPres['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION && array_key_exists('MULTILINE', $stringPres) && !array_key_exists('DIGITS', $stringPres),
    'String-Variablen bekommen die Wertdarstellung mit MULTILINE, ohne Zahlenparameter');
IPS_SetName(World::varId($ac, 'ENERGY_YESTERDAY'), 'Energy Yesterday');
IPS_SetName(World::varId($ac, 'TEMPERATURE_CURRENT_TEMPERATURE'), 'Current Temperature');
IPS_SetName(World::varId($ac, 'INFO'), 'Info (alt)');
check(LGTQD_ReapplyNames($ac) === 3, 'ReapplyNames benennt genau die drei abweichenden Variablen um');
check(World::variable($ac, 'ENERGY_YESTERDAY')['name'] === 'Energie Gestern' && World::variable($ac, 'INFO')['name'] === 'Info'
    && World::variable($ac, 'TEMPERATURE_CURRENT_TEMPERATURE')['name'] === World::variable($ac, 'TEMPERATURE_CURRENT_TEMPERATURE')['name'],
    'Danach tragen sie die aktuellen deutschen Namen');
check(LGTQD_ReapplyNames($ac) === 0, 'Ein zweiter Aufruf ändert nichts mehr');

check(($pres($ac, 'RUN_STATE_CURRENT_STATE')['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION
    && str_contains((string)($pres($ac, 'RUN_STATE_CURRENT_STATE')['OPTIONS'] ?? ''), '"ColorActive"') && !str_contains((string)($pres($ac, 'RUN_STATE_CURRENT_STATE')['OPTIONS'] ?? ''), '"ColorDisplay"'),
    'Zustandstexte bekommen die Wertdarstellung mit Optionen, deren Unterparameter Symcon kennt');

section('Gemeldete Zustände außerhalb der LG-Listen');
$opts = static fn(int $inst, string $ident): array => array_map('strval', array_column(json_decode((string)($pres($inst, $ident)['OPTIONS'] ?? '[]'), true) ?: [], 'Value'));
check(in_array('LOW_MID', $opts($ac, 'AIR_FLOW_WIND_STRENGTH_DETAIL'), true) && World::value($ac, 'AIR_FLOW_WIND_STRENGTH_DETAIL') === 'LOW_MID',
    'Die Klimaanlage meldet windStrengthDetail LOW_MID, das LGs Profil nicht listet: der Wert steht in den Optionen');
World::$cloud->deviceReports($acid, ['airFlow' => ['windStrengthDetail' => 'MID_HIGH']]);
World::flushMqtt();
check(in_array('MID_HIGH', $opts($ac, 'AIR_FLOW_WIND_STRENGTH_DETAIL'), true) && in_array('LOW_MID', $opts($ac, 'AIR_FLOW_WIND_STRENGTH_DETAIL'), true),
    'Ein per Push gemeldeter neuer Zustand erweitert die Optionen, die bisherigen bleiben');

section('Darstellungsebene');
$vidEnergy = World::varId($ac, 'ENERGY_YESTERDAY');
check(World::variable($ac, 'ENERGY_YESTERDAY')['customPresentation'] === [] && ($pres($ac, 'ENERGY_YESTERDAY')['SUFFIX'] ?? '') === ' Wh',
    'Das Modul schreibt die Modul-Darstellung, nicht die benutzerdefinierte');
// Eine Vorversion hatte die Wertdarstellung als benutzerdefinierte Darstellung geschrieben
IPS_SetVariableCustomPresentation($vidEnergy, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' Wh', 'DIGITS' => 0]);
LGTQD_ReapplyPresentations($ac);
check(World::variable($ac, 'ENERGY_YESTERDAY')['customPresentation'] === [], 'Die Darstellung der Vorversion auf der Nutzerebene wird einmalig entfernt');
// Eine eigene Anpassung des Nutzers (andere Darstellungsart) bleibt
IPS_SetVariableCustomPresentation($vidEnergy, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 0, 'MAX' => 5000, 'STEP_SIZE' => 1]);
LGTQD_ReapplyPresentations($ac);
check((World::variable($ac, 'ENERGY_YESTERDAY')['customPresentation']['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SLIDER,
    'Eine eigene Darstellung des Nutzers bleibt erhalten');

section('Support-Paket');
World::quiet();
$zip = LGTQD_UIExportSupportBundle($w);
check(str_starts_with($zip, 'data:application/zip;base64,'), 'UIExportSupportBundle liefert ein ZIP');
$tmp = tempnam(sys_get_temp_dir(), 'lgtqd');
file_put_contents($tmp, base64_decode(substr($zip, strlen('data:application/zip;base64,'))));
$za = new ZipArchive();
$za->open($tmp);
$names = [];
for ($i = 0; $i < $za->numFiles; $i++) {
    $names[] = $za->getNameIndex($i);
}
$all = '';
foreach ($names as $n) {
    $all .= (string)$za->getFromName($n);
}
$za->close();
unlink($tmp);
check(in_array('20_profile_response.json', $names, true) && in_array('40_variables.json', $names, true), 'Inhalt: ' . implode(', ', $names));
check(!str_contains($all, $wid) && !str_contains($all, World::$cloud->pat), 'deviceId und PAT sind im Paket anonymisiert');

section('Timer-Schalter und abgelehnte Befehle (Sleeptimer der Klimaanlage, gemessen 01.10.2026)');
World::start();
[$ac, $acid] = World::liveAc();
$sw = World::variable($ac, 'SLEEP_TIMER_RELATIVE_STOP_TIMER');
check($sw !== null && $sw['action'] === $ac && ($sw['presentation']['PRESENTATION'] ?? '') === ThinQPresentationBuilder::PRES_SWITCH,
    'Sleeptimer ist ein Schalter mit Aktion (Profil: w = [UNSET])');
check(World::variable($ac, 'TIMER_RELATIVE_START_TIMER')['action'] === $ac && World::variable($ac, 'TIMER_RELATIVE_STOP_TIMER')['action'] === $ac,
    'die anderen Timer-Schalter der Klimaanlage ebenso');
World::quiet();
check(RequestAction(World::varId($ac, 'SLEEP_TIMER_RELATIVE_STOP_TIMER'), false) === true, 'Ausschalten per Schalter');
check(sameJson(World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? [], ['sleepTimer' => ['relativeStopTimer' => 'UNSET']]),
    'Befehl sleepTimer.relativeStopTimer = UNSET');
World::quiet();
check(RequestAction(World::varId($ac, 'SLEEP_TIMER_RELATIVE_STOP_TIMER'), true) === true && World::$cloud->calls('POST devices/{id}/control') === [],
    'Einschalten: kein Befehl, keine Ausnahme');
check(str_contains((string)World::value($ac, 'ERROR_LAST'), 'nur das Ausschalten') && World::value($ac, 'SLEEP_TIMER_RELATIVE_STOP_TIMER') === false,
    'Letzter Fehler erklärt es, der Schalter bleibt aus: ' . World::value($ac, 'ERROR_LAST'));
// LG refuses hours+minutes with 2201 NOT_PROVIDED_FEATURE although the profile marks them writable
World::quiet();
$hourId = World::varId($ac, 'SLEEP_TIMER_RELATIVE_HOUR_TO_STOP');
$before = World::value($ac, 'SLEEP_TIMER_RELATIVE_HOUR_TO_STOP');
World::$cloud->fail('POST devices/{id}/control', 400, '{"messageId":"m","timestamp":"t","error":{"code":"2201","message":"Not provided feature"}}');
check(RequestAction($hourId, 3) === true && World::value($ac, 'SLEEP_TIMER_RELATIVE_HOUR_TO_STOP') === $before,
    'Stunden setzen: LGs 2201 wirft keine Ausnahme, der Wert bleibt');
check(str_contains((string)World::value($ac, 'ERROR_LAST'), '2201') && !str_contains((string)World::value($ac, 'ERROR_LAST'), $acid),
    'Letzter Fehler trägt LGs Code ohne die Geräte-ID: ' . World::value($ac, 'ERROR_LAST'));
check(World::variable($ac, 'SLEEP_TIMER_RELATIVE_HOUR_TO_STOP')['action'] === 0 && World::variable($ac, 'SLEEP_TIMER_RELATIVE_MINUTE_TO_STOP')['action'] === 0,
    'Stunden und Minuten verlieren ihre Aktion');
check(sameJson(json_decode((string)World::attr($ac, 'BlockedIdents'), true), ['SLEEP_TIMER_RELATIVE_HOUR_TO_STOP', 'SLEEP_TIMER_RELATIVE_MINUTE_TO_STOP']),
    'gemerkt im Attribut BlockedIdents');
check(World::variable($ac, 'SLEEP_TIMER_RELATIVE_STOP_TIMER')['action'] === $ac && World::variable($ac, 'TEMPERATURE_TARGET_TEMPERATURE')['action'] === $ac,
    'der Schalter und die anderen Variablen behalten ihre Aktion');
IPS_ApplyChanges($ac);
check(World::variable($ac, 'SLEEP_TIMER_RELATIVE_HOUR_TO_STOP')['action'] === 0, 'ApplyChanges mit unverändertem Profil lässt sie ohne Aktion');
World::$cloud->devices[$acid]['profile']['property']['sleepTimer']['relativeStopTimer']['value']['w'] = ['SET', 'UNSET'];
IPS_ApplyChanges($ac);
check(World::variable($ac, 'SLEEP_TIMER_RELATIVE_HOUR_TO_STOP')['action'] === $ac && World::attr($ac, 'BlockedIdents') === '[]',
    'ein geändertes Profil gibt die Aktion zurück');
World::quiet();
check(RequestAction(World::varId($ac, 'SLEEP_TIMER_RELATIVE_STOP_TIMER'), true) === true
    && sameJson(World::$cloud->calls('POST devices/{id}/control')[0]['body'] ?? [], ['sleepTimer' => ['relativeStopTimer' => 'SET']]),
    'erlaubt das Profil SET, schaltet der Schalter auch ein');

check(Kernel::$warnings === [], 'keine Warnungen' . (Kernel::$warnings === [] ? '' : ': ' . implode(' | ', Kernel::$warnings)));

done();
