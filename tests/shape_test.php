<?php

declare(strict_types=1);

/*
 * ThinQShape against LG's example profiles and states (tests/fixtures/lg_examples.json) and the
 * live air conditioner: stored profile form and its repair, status form, merge by zone and
 * selector, ranges per element, property keys over zones, element lists and sub-devices.
 */

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/LG ThinQ Device/libs/ThinQShape.php';

$examples = FakeThinQCloud::examples()['devices'];
$live = json_decode((string)file_get_contents(__DIR__ . '/fixtures/live_ac.json'), true);
$profiles = array_map(static fn(array $d): array => $d['profile'], $examples) + ['live_ac' => $live['profile']];

section('Profilform');
$unusable = [];
$notIdempotent = [];
foreach ($profiles as $type => $profile) {
    $wrapped = ThinQShape::wrapProfile($profile);
    if ($wrapped === null) {
        $unusable[] = $type;
    } elseif (ThinQShape::wrapProfile($wrapped) !== $wrapped) {
        $notIdempotent[] = $type;
    }
}
check($unusable === [], 'jedes LG-Profil ist verwendbar (' . count($profiles) . ' Typen)' . ($unusable === [] ? '' : ': ' . implode(', ', $unusable)));
check($notIdempotent === [], 'die Form ist stabil: zweimal normalisiert gleich einmal');
$ac = $live['profile'];
check(ThinQShape::wrapProfile($ac['property']) === ['property' => $ac['property']], 'eine ohne property gespeicherte Kopie wird wieder eingehüllt (Stand der echten Klimaanlage)');
$washer = $examples['washer']['profile'];
check(ThinQShape::wrapProfile($washer['property']) === ['property' => $washer['property']], 'auch eine Zonenliste ohne Hülle (Waschmaschine)');
$tower = $examples['washtower']['profile'];
check(ThinQShape::wrapProfile(['property' => $tower]) === ThinQShape::wrapProfile($tower) && ThinQShape::isPartProfile((array)ThinQShape::wrapProfile($tower)),
    'WashTower: {property: {washer, dryer}} wird wieder zu den Teilgeräten');
check(ThinQShape::wrapProfile(['response' => $ac]) === $ac && ThinQShape::wrapProfile(['profile' => $ac]) === $ac, 'Umschläge response/profile werden entfernt');
foreach ([[], ['property' => []], ['property' => ['x' => 1]], 'kaputt', ['error' => ['E1'], 'notification' => ['push' => ['P1']]]] as $bad) {
    check(ThinQShape::wrapProfile($bad) === null, 'unbrauchbar: ' . json_encode($bad));
}

section('Status und Zusammenführen');
check(ThinQShape::status(['state' => ['runState' => ['currentState' => 'RUNNING']]]) === ['runState' => ['currentState' => 'RUNNING']], '{state: …} wird ausgepackt');
check(ThinQShape::status([['location' => ['locationName' => 'MAIN'], 'runState' => ['currentState' => 'END']]])['runState']['currentState'] === 'END', 'eine Zone wird ausgepackt');
check(count(ThinQShape::status($examples['cooktop']['status'])) === 3, 'mehrere Zonen bleiben eine Liste');
$fridge = $examples['refrigerator']['status'];
$fridge = ThinQShape::merge($fridge, ['temperature' => [['locationName' => 'FREEZER', 'targetTemperature' => -20, 'unit' => 'C']]]);
$fridge = ThinQShape::merge($fridge, ['temperature' => [['locationName' => 'FRIDGE', 'targetTemperature' => 3, 'unit' => 'C']]]);
$byLoc = array_column($fridge['temperature'], 'targetTemperature', 'locationName');
check($byLoc === ['FRIDGE' => 3, 'FREEZER' => -20], 'Teilberichte je Fach nach locationName (N8): ' . json_encode($byLoc));
$cooktop = ThinQShape::merge($examples['cooktop']['status'], ['location' => ['locationName' => 'RIGHT_FRONT'], 'power' => ['powerLevel' => 7]]);
$levels = array_map(static fn(array $z): string => $z['location']['locationName'] . '=' . $z['power']['powerLevel'], $cooktop);
check(count($cooktop) === 3 && in_array('RIGHT_FRONT=7', $levels, true) && !in_array('LEFT_FRONT=7', $levels, true), 'Bericht einer Zone ändert nur diese Zone: ' . implode(' ', $levels));
$switches = ThinQShape::merge($examples['light_switch']['status'], ['switchState' => ['switchName' => 'SWITCH_2', 'currentSwitch' => 'ON']]);
check(array_column($switches['switchState'], 'currentSwitch', 'switchName') === ['SWITCH_1' => 'ON', 'SWITCH_2' => 'ON', 'SWITCH_3' => 'ON'], 'Schalter nach switchName');

section('Bereiche je Element');
$fridgeP = (array)ThinQShape::wrapProfile($examples['refrigerator']['profile']);
check(ThinQShape::range($fridgeP, 'temperatureInUnits', 'targetTemperatureC', ['locationName' => 'FREEZER']) === ['min' => -21.0, 'max' => -13.0, 'step' => 1.0], 'Gefrierfach −21..−13 (F7)');
check(ThinQShape::range($fridgeP, 'temperatureInUnits', 'targetTemperatureC', ['locationName' => 'FRIDGE']) === ['min' => 1.0, 'max' => 8.0, 'step' => 1.0], 'Kühlfach 1..8');
$wineP = (array)ThinQShape::wrapProfile($examples['wine_cellar']['profile']);
$lower = ThinQShape::range($wineP, 'temperatureInUnits', 'targetTemperatureC', ['locationName' => 'WINE_LOWER']);
check(is_array($lower) && $lower['min'] <= 7.0 && $lower['max'] >= 7.0, 'Weinfach unten erlaubt 7 °C: ' . json_encode($lower));
check(ThinQShape::range((array)ThinQShape::wrapProfile($ac), 'temperature', 'targetTemperature') === ['min' => 18.0, 'max' => 30.0, 'step' => 0.5], 'Klimaanlage: Schreibbereich 18..30 in 0,5');
check(ThinQShape::selectorOf($fridgeP['property']['temperature']) === 'locationName' && ThinQShape::selectorOf($examples['light_switch']['profile']['property']['switchState']) === 'switchName'
    && ThinQShape::selectorOf($ac['property']['temperatureInUnits']) === 'unit', 'Selektor je Elementliste: locationName, switchName, unit');

section('Zonen, Teilgeräte, Schlüssel');
check(ThinQShape::zones((array)ThinQShape::wrapProfile($examples['cooktop']['profile'])) === ['LEFT_FRONT', 'RIGHT_FRONT', 'LEFT_REAR'], 'Zonen des Kochfelds');
check(ThinQShape::zones($fridgeP) === [], 'der Kühlschrank hat keine Zonenliste');
check(array_keys(ThinQShape::resources((array)ThinQShape::wrapProfile($examples['cooktop']['profile']), 'RIGHT_FRONT')) === array_keys(ThinQShape::resources((array)ThinQShape::wrapProfile($examples['cooktop']['profile']))),
    'Ressourcen je Zone ohne location-Hülle');
$towerKeys = ThinQShape::profileKeys((array)ThinQShape::wrapProfile($tower));
check(isset($towerKeys['washer.runState.currentState'], $towerKeys['dryer.runState.currentState']), 'Schlüssel der Teilgeräte mit Präfix');
$acKeys = ThinQShape::profileKeys((array)ThinQShape::wrapProfile($ac));
$unknown = array_keys(array_diff_key(ThinQShape::statusKeys($live['status']), $acKeys));
check(in_array('airQualitySensor.monitoringEnabled', $unknown, true), 'Status der echten Klimaanlage hat Schlüssel, die im Profil fehlen (Anlass von F10): ' . implode(', ', $unknown));
$cooktopKeys = ThinQShape::statusKeys($examples['cooktop']['status']);
check(isset($cooktopKeys['power.powerLevel']) && !isset($cooktopKeys['location.locationName']), 'Statusschlüssel über Zonen, ohne die Zonenhülle');

done();
