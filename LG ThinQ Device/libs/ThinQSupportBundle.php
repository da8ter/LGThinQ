<?php

declare(strict_types=1);

/**
 * ThinQSupportBundle
 *
 * Extracted from LG ThinQ Device/module.php.
 * Builds a ZIP support bundle with device info, profile, status, variables and capability summary.
 */
class ThinQSupportBundle
{
    /** @var callable */
    private $anonymizeCallback;
    /** @var callable */
    private $flattenCallback;
    /** @var callable */
    private $sendActionCallback;
    /** @var callable */
    private $fetchProfileCallback;
    /** @var callable */
    private $getEngineCallback;

    public function __construct(
        private IPSModule $module,
        callable $anonymizeCallback,
        callable $flattenCallback,
        callable $sendActionCallback,
        callable $fetchProfileCallback,
        callable $getEngineCallback
    ) {
        $this->anonymizeCallback   = $anonymizeCallback;
        $this->flattenCallback     = $flattenCallback;
        $this->sendActionCallback  = $sendActionCallback;
        $this->fetchProfileCallback = $fetchProfileCallback;
        $this->getEngineCallback   = $getEngineCallback;
    }

    public function export(): string
    {
        try {
            $zipData = $this->buildZip();
            return 'data:application/zip;base64,' . base64_encode($zipData);
        } catch (\Throwable $e) {
            $this->module->SendDebug('UIExportSupportBundle', $e->getMessage(), 0);
            return 'data:text/plain,' . rawurlencode($this->module->Translate('Error creating support package') . ': ' . $e->getMessage());
        }
    }

    private function buildZip(): string
    {
        $zip = new \ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'lgtqd_');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to open ZIP');
        }

        // 00_meta.json
        $instanceId = $this->module->InstanceID;
        $meta = [
            'module'        => 'LG ThinQ Device',
            'instanceId'    => $instanceId,
            'alias'         => @IPS_GetName($instanceId),
            'timestamp'     => date('c'),
            'phpVersion'    => PHP_VERSION,
            'kernelVersion' => function_exists('IPS_GetKernelVersion') ? @IPS_GetKernelVersion() : ''
        ];
        $zip->addFromString('00_meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // Determine DeviceID
        $deviceId = trim((string)$this->module->ReadPropertyString('DeviceID'));

        // 10_device_info.json (from GetDevices, anonymized)
        $devices = [];
        try {
            $devRaw = ($this->sendActionCallback)('GetDevices');
            $devDec = json_decode((string)$devRaw, true);
            $devices = is_array($devDec) ? ($this->anonymizeCallback)($devDec) : [];
        } catch (\Throwable $e) {
            $devices = [];
        }
        $zip->addFromString('10_device_info.json', json_encode($devices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // 20_profile_response.json (raw API profile payload) and 21_profile_extracted.json
        $profileRaw = [];
        $profileExtracted = [];
        try {
            if ($deviceId !== '') {
                $pRaw = ($this->sendActionCallback)('GetProfile', ['DeviceID' => $deviceId]);
                $pDec = json_decode((string)$pRaw, true);
                $profileRaw = is_array($pDec) ? ($this->anonymizeCallback)($pDec) : [];
                $profileExtracted = ($this->fetchProfileCallback)($deviceId);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $zip->addFromString('20_profile_response.json', json_encode($profileRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $zip->addFromString('21_profile_extracted.json', json_encode(($this->anonymizeCallback)($profileExtracted), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // 22_profile_keys.txt
        $flatKeys = array_keys(($this->flattenCallback)(is_array($profileExtracted) ? $profileExtracted : []));
        sort($flatKeys);
        $zip->addFromString('22_profile_keys.txt', implode("\n", $flatKeys));

        // 30_status.json (live if possible, otherwise last)
        $status = [];
        try {
            if ($deviceId !== '') {
                $sRaw = ($this->sendActionCallback)('GetStatus', ['DeviceID' => $deviceId]);
                $sDec = json_decode((string)$sRaw, true);
                if (is_array($sDec)) {
                    $status = $sDec;
                }
            }
        } catch (\Throwable $e) {
            // ignore, fallback to LastStatus
        }
        if (empty($status)) {
            $ls = (string)$this->module->ReadAttributeString('LastStatus');
            $ld = json_decode($ls, true);
            $status = is_array($ld) ? $ld : [];
        }
        $zip->addFromString('30_status.json', json_encode(($this->anonymizeCallback)($status), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // 40_variables.json snapshot (with custom presentation/action info)
        $vars = [];
        foreach ((array)@IPS_GetChildrenIDs($instanceId) as $childId) {
            $obj = @IPS_GetObject($childId);
            $var = @IPS_GetVariable($childId);
            if (!is_array($obj) || !is_array($var)) {
                continue;
            }
            $entry = [
                'ID'            => (int)$childId,
                'Ident'         => (string)($obj['ObjectIdent'] ?? ''),
                'Name'          => (string)($obj['ObjectName'] ?? ''),
                'Type'          => (int)($var['VariableType'] ?? -1),
                'Profile'       => (string)($var['VariableProfile'] ?? ''),
                'CustomProfile' => (string)($var['VariableCustomProfile'] ?? ''),
                'CustomAction'  => (int)($var['VariableCustomAction'] ?? 0)
            ];
            if (function_exists('IPS_GetVariableCustomPresentation')) {
                $pres = @IPS_GetVariableCustomPresentation($childId);
                if (is_array($pres)) {
                    $entry['CustomPresentation'] = $pres;
                }
            }
            $vars[] = $entry;
        }
        $zip->addFromString('40_variables.json', json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // 50_capabilities_summary.json
        $summary = [];
        try {
            $type = trim((string)$this->module->ReadAttributeString('DeviceType'));
            if ($type === '' && is_array($profileExtracted)) {
                $type = (string)($profileExtracted['deviceType'] ?? '');
            }
            $engine = ($this->getEngineCallback)();
            $engine->loadCapabilities($type !== '' ? $type : 'ac', is_array($profileExtracted) ? $profileExtracted : []);
            $descs = $engine->getDescriptors();
            $brief = [];
            foreach ($descs as $cap) {
                if (!is_array($cap)) continue;
                $brief[] = [
                    'ident'        => (string)($cap['ident'] ?? ''),
                    'type'         => (string)($cap['type'] ?? ''),
                    'name'         => (string)($cap['name'] ?? ''),
                    'presentation' => isset($cap['presentation']) && is_array($cap['presentation']) ? ($cap['presentation']['kind'] ?? '') : '',
                    'enableWhen'   => (string)($cap['action']['enableWhen'] ?? '')
                ];
            }
            $summary = [
                'deviceType'             => $type,
                'descriptorCount'        => count($descs),
                'identsToEnable'         => $engine->listIdentsToEnable(),
                'identsToEnableOnSetup'  => $engine->listIdentsToEnableOnSetup(),
                'descriptors'            => $brief
            ];
        } catch (\Throwable $e) {
            $summary = ['error' => $e->getMessage()];
        }
        $zip->addFromString('50_capabilities_summary.json', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $zip->close();
        $data = (string)@file_get_contents($tmp);
        @unlink($tmp);
        if ($data === '') {
            throw new \RuntimeException('ZIP is empty');
        }
        return $data;
    }
}
