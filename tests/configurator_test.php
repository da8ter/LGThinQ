<?php

declare(strict_types=1);

/* Configurator: device list from the Bridge, existing instances, create entries. */

require __DIR__ . '/bootstrap.php';

section('Konfigurator');
World::start();
$washer = World::$cloud->addExampleDevice('washer', 'Waschmaschine Keller');
$ac = World::$cloud->addLiveAc();
$conf = IPS_CreateInstance(CONFIGURATOR_GUID);
check(Kernel::$instances[$conf]['connection'] === World::$bridge, 'Konfigurator verbindet sich mit der Bridge');
$existing = World::addDevice($ac);

$form = json_decode((string)Kernel::$instances[$conf]['object']->GetConfigurationForm(), true);
$values = $form['actions'][0]['values'] ?? [];
$byId = array_column($values, null, 'deviceId');
check(count($values) === 2, 'zwei Geräte in der Liste');
check($byId[$washer]['name'] === 'Waschmaschine Keller' && $byId[$washer]['type'] === 'DEVICE_WASHER', 'Name und Typ aus deviceInfo');
check($byId[$washer]['instanceID'] === 0, 'neues Gerät: noch keine Instanz');
check($byId[$ac]['instanceID'] === $existing, 'vorhandene Instanz wird erkannt');
$create = $byId[$washer]['create'];
check($create['moduleID'] === DEVICE_GUID && (array)$create['configuration'] === ['DeviceID' => $washer, 'Alias' => 'Waschmaschine Keller'], 'Anlegen: Geräte-Modul mit DeviceID und Alias');
check(!isset($create[0]) && !isset($create['moduleID'][0]['moduleID']), 'Anlegen als einzelnes Objekt, keine Kette (siehe Juni 2026)');
check(($form['actions'][0]['type'] ?? '') === 'Configurator', 'Formular vom Typ Configurator');

World::quiet();
IPS_DisconnectInstance($conf);
$form = json_decode((string)Kernel::$instances[$conf]['object']->GetConfigurationForm(), true);
check(($form['actions'][0]['values'] ?? null) === [], 'ohne Bridge: leere Liste, keine Anfrage');
check(World::$cloud->requests === [] && Kernel::$warnings === [], 'und keine Warnung');

done();
