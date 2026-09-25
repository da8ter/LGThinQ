<?php

declare(strict_types=1);

class LGThinQConfigurator extends IPSModule
{
    private const GATEWAY_MODULE_GUID = '{FCD02091-9189-0B0A-0C70-D607F1941C05}';
    private const DEVICE_MODULE_GUID  = '{B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}';
    private const DATA_FLOW_TX_GUID   = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::GATEWAY_MODULE_GUID);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
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
        $gatewayID = $this->getGatewayID();
        $values = [];

        if ($gatewayID > 0) {
            try {
                $json = @LGTQ_GetDevices($gatewayID);
                $devices = json_decode((string)$json, true);
                if (is_array($devices)) {
                    foreach ($devices as $dev) {
                        $deviceId = $dev['deviceId'] ?? ($dev['id'] ?? null);
                        if (!$deviceId) {
                            continue;
                        }
                        $info = $dev['deviceInfo'] ?? null;
                        $info = is_array($info) ? $info : [];

                        $alias = $dev['alias']
                            ?? $dev['deviceName']
                            ?? $dev['name']
                            ?? ($info['alias'] ?? ($info['deviceName'] ?? ($info['name'] ?? ($this->Translate('Device') . ' ' . substr($deviceId, -6)))));

                        $type = $dev['deviceType']
                            ?? ($dev['type'] ?? ($info['deviceType'] ?? ($info['type'] ?? '')));

                        $existingID = $this->findExistingDeviceInstance((string)$deviceId, $gatewayID);

                        $values[] = [
                            'name'       => $alias,
                            'deviceId'   => (string)$deviceId,
                            'type'       => (string)$type,
                            'instanceID' => $existingID,
                            'create'     => [
                                // Single-instance create: the Device binds itself to the
                                // existing Bridge via ConnectParent(GATEWAY_MODULE_GUID) in
                                // its own Create(). This is the proven pattern used by the
                                // sibling configurators (HaConfigurator, x-sense-mqtt); a
                                // create-chain is unnecessary for a direct Device->Bridge
                                // parent relationship and risks creating a duplicate Bridge.
                                'moduleID'      => self::DEVICE_MODULE_GUID,
                                'configuration' => (object) [
                                    'DeviceID'  => (string)$deviceId,
                                    'Alias'     => (string)$alias
                                ],
                                'name'          => (string)$alias
                            ]
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->SendDebug('GetConfigurationForm', 'Error: ' . $e->getMessage(), 0);
            }
        }

        $form = [
            'elements' => [],
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

    private function getGatewayID(): int
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        if (is_array($inst) && isset($inst['ConnectionID']) && (int)$inst['ConnectionID'] > 0) {
            return (int)$inst['ConnectionID'];
        }
        return 0;
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
