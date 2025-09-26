<?php

declare(strict_types=1);

class LGThinQConfigurator extends IPSModule
{
    private const GATEWAY_MODULE_GUID = '{FCD02091-9189-0B0A-0C70-D607F1941C05}';
    private const DEVICE_MODULE_GUID  = '{B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('GatewayID', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Best Practice: Avoid heavy work before KR_READY. Re-run on IPS_KERNELSTARTED
        if (function_exists('IPS_GetKernelRunlevel') && IPS_GetKernelRunlevel() !== KR_READY) {
            if (method_exists($this, 'RegisterMessage')) {
                $this->RegisterMessage(0, IPS_KERNELSTARTED);
            }
            return;
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function GetConfigurationForm()
    {
        $gatewayID = (int)$this->ReadPropertyInteger('GatewayID');
        $values = [];

        if ($gatewayID > 0 && @IPS_InstanceExists($gatewayID)) {
            try {
                $json = @LGTQ_GetDevices($gatewayID);
                $devices = json_decode((string)$json, true);
                if (is_array($devices)) {
                    foreach ($devices as $dev) {
                        $deviceId = $dev['deviceId'] ?? ($dev['id'] ?? null);
                        if (!$deviceId) {
                            continue;
                        }
                        // Zusätzliche Fallbacks aus deviceInfo.* verwenden (Alias/Name/Typ)
                        $info = $dev['deviceInfo'] ?? null;
                        $info = is_array($info) ? $info : [];

                        // Alias/Anzeigename priorisieren: alias -> deviceName -> name (zuerst Top-Level, dann deviceInfo)
                        $alias = $dev['alias']
                            ?? $dev['deviceName']
                            ?? $dev['name']
                            ?? ($info['alias'] ?? ($info['deviceName'] ?? ($info['name'] ?? ($this->Translate('Device') . ' ' . substr($deviceId, -6)))));

                        // Typ priorisieren: deviceType -> type (zuerst Top-Level, dann deviceInfo)
                        $type = $dev['deviceType']
                            ?? ($dev['type'] ?? ($info['deviceType'] ?? ($info['type'] ?? '')));

                        $existingID = $this->findExistingDeviceInstance((string)$deviceId, $gatewayID);

                        // Aktuelle Gateway-Konfiguration als Objekt extrahieren
                        $gwCfgArr = [];
                        $gwCfgJson = @IPS_GetConfiguration($gatewayID);
                        if (is_string($gwCfgJson) && $gwCfgJson !== '') {
                            $tmp = json_decode($gwCfgJson, true);
                            if (is_array($tmp)) {
                                $gwCfgArr = $tmp;
                            }
                        }
                        $gwCfgObj = (object)$gwCfgArr; // leeres Objekt falls keine Properties

                        $values[] = [
                            'name'       => $alias,
                            'deviceId'   => (string)$deviceId,
                            'type'       => (string)$type,
                            'instanceID' => $existingID,
                            'create'     => [
                                'moduleID'      => self::DEVICE_MODULE_GUID,
                                'configuration' => (object) [
                                    'DeviceID'  => (string)$deviceId,
                                    'Alias'     => $alias
                                ],
                                // Weise den gewählten Gateway explizit als Parent zu
                                'parent'       => (object) [
                                    'moduleID'   => self::GATEWAY_MODULE_GUID,
                                    'instanceID' => $gatewayID
                                ]
                            ]
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->SendDebug('GetConfigurationForm', 'Error: ' . $e->getMessage(), 0);
            }
        }

        $form = [
            'elements' => [
                [
                    'type'     => 'SelectInstance',
                    'name'     => 'GatewayID',
                    'caption'  => $this->Translate('LG ThinQ Gateway'),
                    'moduleID' => self::GATEWAY_MODULE_GUID,
                    'width'    => '600px'
                ]
            ],
            'actions'  => [
                [
                    'name'               => 'configurator',
                    'type'               => 'Configurator',
                    'discoveryInterval'  => 120,
                    'columns'            => [
                        ['caption' => $this->Translate('Name'),      'name' => 'name',     'width' => '300px'],
                        ['caption' => $this->Translate('Device ID'), 'name' => 'deviceId', 'width' => 'auto'],
                        ['caption' => $this->Translate('Type'),      'name' => 'type',     'width' => '300px']
                    ],
                    'values'             => $values
                ]
            ]
        ];

        return json_encode($form);
    }

    private function findExistingDeviceInstance(string $deviceId, int $gatewayID): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID);
        foreach ($ids as $id) {
            $configJson = @IPS_GetConfiguration($id);
            if ($configJson === false || $configJson === null) {
                continue;
            }
            $cfg = @json_decode($configJson, true);
            if (!is_array($cfg)) {
                continue;
            }
            $pDevice  = (string)($cfg['DeviceID'] ?? '');
            if ($pDevice !== $deviceId) {
                continue;
            }
            // Prüfe, ob die Instanz am gewünschten Gateway hängt
            $inst = @IPS_GetInstance($id);
            if (is_array($inst)) {
                $parentID = (int)($inst['ConnectionID'] ?? 0);
                if ($parentID === $gatewayID) {
                    return (int)$id;
                }
            }
        }
        return 0;
    }
}
