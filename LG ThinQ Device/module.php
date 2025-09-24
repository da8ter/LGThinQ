<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/CapabilityEngine.php';

class LGThinQDevice extends IPSModule
{
    private const GATEWAY_MODULE_GUID = '{FCD02091-9189-0B0A-0C70-D607F1941C05}';
    private const DATA_FLOW_GUID      = '{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}';

    private const PRES_VALUE    = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const PRES_SWITCH   = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    private const PRES_SLIDER   = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const PRES_DATETIME = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
    private const PRES_BUTTONS  = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

    private const PROFILE_PREFIX = 'LGTQD.';

    private ?CapabilityEngine $engine = null;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Alias', '');

        $this->RegisterAttributeString('LastStatus', '');
        $this->RegisterAttributeString('DeviceType', '');
        $this->RegisterAttributeString('LastProfile', '');
        $this->RegisterAttributeString('LastPlan', '[]');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $alias = trim((string)$this->ReadPropertyString('Alias'));
        if ($alias !== '' && IPS_GetName($this->InstanceID) !== $alias) {
            IPS_SetName($this->InstanceID, $alias);
        }

        $this->MaintainVariable('INFO', $this->t('Info'), VARIABLETYPE_STRING, '', 10);
        $this->MaintainVariable('STATUS', $this->t('Status'), VARIABLETYPE_STRING, '', 20);
        $this->MaintainVariable('LASTUPDATE', $this->t('Last Update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 30);
        $this->hideDefaultVariables();

        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            $this->SetStatus(104);
            return;
        }

        $info = ['deviceId' => $deviceId, 'alias' => $alias];
        $this->setValueByVarType('INFO', json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->EnsureConnected();

        try {
            $this->setupDevice();
            $this->SetStatus(102);
        } catch (Throwable $e) {
            $this->logThrowable('ApplyChanges', $e);
            $this->SetStatus(202);
            return;
        }

        try {
            $this->autoSubscribe($deviceId);
        } catch (Throwable $e) {
            $this->logThrowable('AutoSubscribe', $e);
        }

        $this->updateReferences();
    }

    public function UpdateStatus(): void
    {
        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            $this->LogMessage($this->t('UpdateStatus: DeviceID is missing'), KL_WARNING);
            return;
        }

        try {
            $payload = $this->sendAction('GetStatus', ['DeviceID' => $deviceId]);
            $status = json_decode((string)$payload, true);
            if (!is_array($status)) {
                throw new Exception($this->t('Invalid status response'));
            }

            $encoded = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->WriteAttributeString('LastStatus', $encoded);
            $this->setValueByVarType('STATUS', $encoded);
            $this->setValueByVarType('LASTUPDATE', time());

            $engine = $this->prepareEngine();
            if ($engine !== null) {
                $engine->applyStatus($status);
            }
        } catch (Throwable $e) {
            $this->logThrowable('UpdateStatus', $e);
        }
    }

    public function ControlDevice(string $JSONPayload): bool
    {
        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            throw new Exception($this->t('ControlDevice: Missing DeviceID'));
        }
        $payload = json_decode($JSONPayload, true);
        if (!is_array($payload)) {
            throw new Exception($this->t('ControlDevice: Invalid JSON payload'));
        }
        $resp = $this->sendAction('Control', ['DeviceID' => $deviceId, 'Payload' => $payload]);
        $dec = json_decode((string)$resp, true);
        return is_array($dec) ? (($dec['success'] ?? false) === true) : true;
    }

    public function RequestAction($ident, $value)
    {
        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            throw new Exception($this->t('ControlDevice: Missing DeviceID'));
        }

        $engine = $this->prepareEngine();
        if ($engine === null) {
            throw new Exception($this->t('Unknown action'));
        }

        $payload = $engine->buildControlPayload((string)$ident, $value);
        if (!is_array($payload)) {
            throw new Exception($this->t('Unknown action') . ': ' . $ident);
        }

        $ok = $this->ControlDevice(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($ok) {
            $this->setValueByVarType((string)$ident, $value);
            if (method_exists($this, 'RegisterOnceTimer')) {
                $this->RegisterOnceTimer('RefreshAfterControl', 'LGTQD_UpdateStatus($_IPS["TARGET"]);');
            }
        }
    }

    public function ReceiveData($JSONString)
    {
        $outer = json_decode((string)$JSONString, true);
        if (!is_array($outer)) {
            return '';
        }
        $buf = $outer['Buffer'] ?? null;
        if (is_string($buf)) {
            $buf = json_decode((string)$buf, true);
        }
        if (!is_array($buf)) {
            return '';
        }
        if ((string)($buf['Action'] ?? '') !== 'Event') {
            return '';
        }

        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '' || strcasecmp($deviceId, (string)($buf['DeviceID'] ?? '')) !== 0) {
            return '';
        }

        $event = $buf['Event'] ?? null;
        if (!is_array($event)) {
            return '';
        }

        $current = $this->readLastStatus();
        $merged = $this->deepMerge($current, $event);
        $encoded = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->WriteAttributeString('LastStatus', $encoded);
        $this->setValueByVarType('STATUS', $encoded);
        $this->setValueByVarType('LASTUPDATE', time());

        $engine = $this->prepareEngine();
        if ($engine !== null) {
            $engine->applyStatus($merged);
        }

        return '';
    }

    public function EnsureConnected(): void
    {
        if (method_exists($this, 'HasActiveParent') && $this->HasActiveParent()) {
            return;
        }
        $this->ConnectParent(self::GATEWAY_MODULE_GUID);
    }

    private function setupDevice(): void
    {
        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            throw new Exception('DeviceID missing');
        }

        $profile = $this->fetchDeviceProfile($deviceId);
        $status = $this->readLastStatus();
        $type = $this->resolveDeviceType($deviceId, $profile);

        $this->WriteAttributeString('LastProfile', json_encode($profile));
        $this->WriteAttributeString('DeviceType', $type);

        $engine = $this->getCapabilityEngine();
        $plan = $engine->buildPlan($type, $profile, $status);
        $flatProfile = $this->flatten($profile);
        $previousPlan = $this->readStoredPlanIdents();
        $currentPlan = [];
        $position = 100;

        foreach ($plan as $ident => $entry) {
            $ident = (string)$ident;
            $shouldCreate = (bool)($entry['shouldCreate'] ?? false);
            $enableAction = (bool)($entry['enableAction'] ?? false);

            if ($shouldCreate) {
                $ipsType = match (strtoupper((string)($entry['type'] ?? 'STRING'))) {
                    'BOOLEAN' => VARIABLETYPE_BOOLEAN,
                    'INTEGER' => VARIABLETYPE_INTEGER,
                    'FLOAT' => VARIABLETYPE_FLOAT,
                    default => VARIABLETYPE_STRING
                };

                $name = (string)($entry['name'] ?? $ident);
                $this->MaintainVariable($ident, $this->t($name), $ipsType, '', $position, true);
                $position += 10;

                $vid = $this->findVariableId($ident);
                if ($vid > 0) {
                    if (($entry['hidden'] ?? false)) {
                        IPS_SetHidden($vid, true);
                    }
                    if (isset($entry['presentation']) && is_array($entry['presentation'])) {
                        $this->applyPresentation($vid, $ident, $entry['presentation'], $flatProfile, (string)($entry['type'] ?? 'STRING'));
                    }
                }

                if (array_key_exists('initialValue', $entry)) {
                    $this->setValueByVarType($ident, $entry['initialValue']);
                }

                $currentPlan[] = $ident;
            } else {
                $this->MaintainVariable($ident, '', 0, '', 0, false);
                $this->removeFallbackProfile($ident);
            }

            $this->MaintainAction($ident, $enableAction);
        }

        $this->cleanupRemovedPlanEntries($previousPlan, $currentPlan);
        $this->WriteAttributeString('LastPlan', json_encode($currentPlan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (!empty($status)) {
            $engine->applyStatus($status);
        }
    }

    private function prepareEngine(): ?CapabilityEngine
    {
        $profile = $this->readStoredProfile();
        $type = trim((string)$this->ReadAttributeString('DeviceType'));
        if ($type === '') {
            return null;
        }
        $engine = $this->getCapabilityEngine();
        $engine->loadCapabilities($type, $profile);
        return $engine;
    }

    private function fetchDeviceProfile(string $deviceId): array
    {
        try {
            $raw = $this->sendAction('GetProfile', ['DeviceID' => $deviceId]);
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                return [];
            }
            if (isset($data['profile']) && is_array($data['profile'])) {
                return $data['profile'];
            }
            if (isset($data['property']) && is_array($data['property'])) {
                return $data['property'];
            }
            return $data;
        } catch (Throwable $e) {
            $this->logThrowable('FetchProfile', $e);
            return [];
        }
    }

    private function resolveDeviceType(string $deviceId, array $profile): string
    {
        $cached = trim((string)$this->ReadAttributeString('DeviceType'));
        if ($cached !== '') {
            return $cached;
        }

        try {
            $listRaw = $this->sendAction('GetDevices');
            $list = json_decode((string)$listRaw, true);
            if (is_array($list)) {
                foreach ($list as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $id = (string)($entry['deviceId'] ?? ($entry['id'] ?? ''));
                    if ($id !== '' && strcasecmp($id, $deviceId) === 0) {
                        $type = (string)($entry['deviceType'] ?? '');
                        if ($type !== '') {
                            return $type;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logThrowable('ResolveDeviceType', $e);
        }

        if (isset($profile['deviceType']) && is_string($profile['deviceType'])) {
            return (string)$profile['deviceType'];
        }

        return 'ac';
    }

    private function applyPresentation(int $vid, string $ident, array $presentation, array $flatProfile, string $type): void
    {
        $kind = strtolower((string)($presentation['kind'] ?? ''));
        if ($kind === '') {
            return;
        }

        $payload = [];
        if ($kind === 'switch') {
            $payload['PRESENTATION'] = self::PRES_SWITCH;
            $payload['CAPTION_ON'] = $this->t((string)($presentation['captionOn'] ?? $this->t('On')));
            $payload['CAPTION_OFF'] = $this->t((string)($presentation['captionOff'] ?? $this->t('Off')));
        } elseif ($kind === 'slider') {
            $payload['PRESENTATION'] = self::PRES_SLIDER;
            $range = $presentation['range'] ?? [];
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;
            $step = $range['step'] ?? null;
            if ((!is_numeric($min) || !is_numeric($max) || !is_numeric($step)) && isset($presentation['rangeFromProfile'])) {
                $rfp = $presentation['rangeFromProfile'];
                $min = $min ?? $this->firstNumericByPaths($flatProfile, (array)($rfp['min'] ?? []));
                $max = $max ?? $this->firstNumericByPaths($flatProfile, (array)($rfp['max'] ?? []));
                $step = $step ?? $this->firstNumericByPaths($flatProfile, (array)($rfp['step'] ?? []));
            }
            if (is_numeric($min)) {
                $payload['MIN'] = (float)$min;
            }
            if (is_numeric($max)) {
                $payload['MAX'] = (float)$max;
            }
            if (!is_numeric($step) || (float)$step === 0.0) {
                $step = 1.0;
            }
            $payload['STEP_SIZE'] = (float)$step;
            if (isset($presentation['suffix'])) {
                $payload['SUFFIX'] = $this->t((string)$presentation['suffix']);
            } elseif (isset($range['suffix'])) {
                $payload['SUFFIX'] = $this->t((string)$range['suffix']);
            }
            if (isset($presentation['digits'])) {
                $payload['DIGITS'] = (int)$presentation['digits'];
            } elseif (isset($range['digits'])) {
                $payload['DIGITS'] = (int)$range['digits'];
            } else {
                $stepVal = (float)$payload['STEP_SIZE'];
                $payload['DIGITS'] = ($stepVal >= 1.0) ? 0 : (($stepVal >= 0.5) ? 1 : 2);
            }
        } elseif ($kind === 'buttons') {
            $payload['PRESENTATION'] = self::PRES_BUTTONS;
            $options = [];
            if (isset($presentation['options']) && is_array($presentation['options'])) {
                foreach ($presentation['options'] as $op) {
                    if (!is_array($op)) {
                        continue;
                    }
                    if (!array_key_exists('value', $op) || !array_key_exists('caption', $op)) {
                        continue;
                    }
                    $options[] = [
                        'Value' => (int)$op['value'],
                        'Caption' => $this->t((string)$op['caption']),
                        'IconActive' => false,
                        'IconValue' => '',
                        'Color' => (int)($op['color'] ?? -1)
                    ];
                }
            }
            if (!empty($options)) {
                $payload['LAYOUT'] = 1;
                $payload['OPTIONS'] = $options;
            }
        } elseif ($kind === 'value') {
            $payload['PRESENTATION'] = self::PRES_VALUE;
            if (isset($presentation['suffix'])) {
                $payload['SUFFIX'] = $this->t((string)$presentation['suffix']);
            }
            if (isset($presentation['digits'])) {
                $payload['DIGITS'] = (int)$presentation['digits'];
            }
        }

        if (empty($payload)) {
            return;
        }

        $payload = $this->translatePresentationPayload($payload);

        if (function_exists('IPS_SetVariableCustomPresentation')) {
            if (isset($payload['OPTIONS']) && is_array($payload['OPTIONS'])) {
                $payload['OPTIONS'] = json_encode($payload['OPTIONS'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            IPS_SetVariableCustomPresentation($vid, $payload);
        } else {
            $this->applyProfileFallback($vid, $ident, $payload, $type);
        }
    }

    private function translatePresentationPayload(array $payload): array
    {
        if (isset($payload['CAPTION_ON'])) {
            $payload['CAPTION_ON'] = $this->t((string)$payload['CAPTION_ON']);
        }
        if (isset($payload['CAPTION_OFF'])) {
            $payload['CAPTION_OFF'] = $this->t((string)$payload['CAPTION_OFF']);
        }
        if (isset($payload['SUFFIX'])) {
            $payload['SUFFIX'] = $this->t((string)$payload['SUFFIX']);
        }
        if (isset($payload['OPTIONS']) && is_array($payload['OPTIONS'])) {
            foreach ($payload['OPTIONS'] as &$option) {
                if (isset($option['Caption'])) {
                    $option['Caption'] = $this->t((string)$option['Caption']);
                }
            }
            unset($option);
        }
        return $payload;
    }

    private function applyProfileFallback(int $vid, string $ident, array $presentation, string $type): void
    {
        if (!IPS_VariableExists($vid)) {
            return;
        }

        $profileName = self::PROFILE_PREFIX . $this->InstanceID . '.' . $ident;
        $vt = match (strtoupper($type)) {
            'BOOLEAN' => VARIABLETYPE_BOOLEAN,
            'INTEGER' => VARIABLETYPE_INTEGER,
            'FLOAT' => VARIABLETYPE_FLOAT,
            default => VARIABLETYPE_STRING
        };

        if (IPS_VariableProfileExists($profileName)) {
            IPS_SetVariableCustomProfile($vid, '');
            IPS_DeleteVariableProfile($profileName);
        }
        IPS_CreateVariableProfile($profileName, $vt);
        IPS_SetVariableProfileIcon($profileName, '');
        IPS_SetVariableProfileText($profileName, '', '');

        if ($vt === VARIABLETYPE_BOOLEAN) {
            IPS_SetVariableProfileAssociation($profileName, 0, $presentation['CAPTION_OFF'] ?? $this->t('Off'), '', -1);
            IPS_SetVariableProfileAssociation($profileName, 1, $presentation['CAPTION_ON'] ?? $this->t('On'), '', -1);
        } elseif ($vt === VARIABLETYPE_INTEGER || $vt === VARIABLETYPE_FLOAT) {
            $min = isset($presentation['MIN']) ? (float)$presentation['MIN'] : 0.0;
            $max = isset($presentation['MAX']) ? (float)$presentation['MAX'] : 0.0;
            $step = isset($presentation['STEP_SIZE']) ? (float)$presentation['STEP_SIZE'] : 1.0;
            IPS_SetVariableProfileValues($profileName, $min, $max, $step);
            if (isset($presentation['DIGITS'])) {
                IPS_SetVariableProfileDigits($profileName, (int)$presentation['DIGITS']);
            }
            if (isset($presentation['SUFFIX'])) {
                IPS_SetVariableProfileText($profileName, '', (string)$presentation['SUFFIX']);
            }
            if (isset($presentation['OPTIONS']) && is_array($presentation['OPTIONS'])) {
                foreach ($presentation['OPTIONS'] as $option) {
                    if (!isset($option['Value'], $option['Caption'])) {
                        continue;
                    }
                    IPS_SetVariableProfileAssociation($profileName, (int)$option['Value'], (string)$option['Caption'], '', (int)($option['Color'] ?? -1));
                }
            }
        }

        IPS_SetVariableCustomProfile($vid, $profileName);
    }

    private function autoSubscribe(string $deviceId): void
    {
        if ($deviceId === '') {
            return;
        }
        $this->sendAction('SubscribeDevice', ['DeviceID' => $deviceId, 'Push' => true, 'Event' => true]);
    }

    private function sendAction(string $action, array $params = []): string
    {
        if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
            $this->EnsureConnected();
        }
        if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
            throw new Exception('No active LG ThinQ bridge connected.');
        }

        $buffer = array_merge(['Action' => $action], $params);
        $packet = [
            'DataID' => self::DATA_FLOW_GUID,
            'Buffer' => json_encode($buffer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];
        $result = $this->SendDataToParent(json_encode($packet));
        if (!is_string($result)) {
            return '';
        }
        $decoded = json_decode($result, true);
        if (is_array($decoded)) {
            if (($decoded['success'] ?? true) === false) {
                $error = (string)($decoded['error'] ?? 'unknown error');
                throw new Exception($error);
            }
            if (isset($decoded['devices'])) {
                return json_encode($decoded['devices']);
            }
            if (isset($decoded['status'])) {
                return json_encode($decoded['status']);
            }
            if (isset($decoded['profile'])) {
                return json_encode($decoded['profile']);
            }
        }
        return $result;
    }

    private function hideDefaultVariables(): void
    {
        $hide = [
            'INFO', 'STATUS', 'LASTUPDATE',
            'TIMER_START_REL_HOUR', 'TIMER_START_REL_MIN',
            'TIMER_STOP_REL_HOUR', 'TIMER_STOP_REL_MIN',
            'SLEEP_STOP_REL_HOUR', 'SLEEP_STOP_REL_MIN',
            'TIMER_START_ABS_HOUR', 'TIMER_START_ABS_MIN',
            'TIMER_STOP_ABS_HOUR', 'TIMER_STOP_ABS_MIN'
        ];
        foreach ($hide as $ident) {
            $vid = $this->findVariableId($ident);
            if ($vid > 0 && IPS_ObjectExists($vid)) {
                IPS_SetHidden($vid, true);
            }
        }
    }

    private function getCapabilityEngine(): CapabilityEngine
    {
        if ($this->engine === null) {
            $this->engine = new CapabilityEngine($this->InstanceID, __DIR__);
        }
        return $this->engine;
    }

    private function readStoredProfile(): array
    {
        $raw = (string)$this->ReadAttributeString('LastProfile');
        $profile = json_decode($raw, true);
        return is_array($profile) ? $profile : [];
    }

    private function readLastStatus(): array
    {
        $raw = (string)$this->ReadAttributeString('LastStatus');
        $status = json_decode($raw, true);
        return is_array($status) ? $status : [];
    }

    /**
     * @return array<int, string>
     */
    private function readStoredPlanIdents(): array
    {
        $raw = (string)$this->ReadAttributeString('LastPlan');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $entry) {
            if (is_string($entry) && $entry !== '') {
                $result[] = $entry;
            }
        }
        return $result;
    }

    private function setValueByVarType(string $ident, $value): void
    {
        $vid = $this->findVariableId($ident);
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return;
        }
        $var = IPS_GetVariable($vid);
        switch ((int)$var['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                SetValueBoolean($vid, (bool)$value);
                break;
            case VARIABLETYPE_INTEGER:
                SetValueInteger($vid, (int)$value);
                break;
            case VARIABLETYPE_FLOAT:
                SetValueFloat($vid, (float)$value);
                break;
            case VARIABLETYPE_STRING:
                SetValueString($vid, (string)$value);
                break;
        }
    }

    private function findVariableId(string $ident): int
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            if (($object['ObjectIdent'] ?? '') === $ident && IPS_VariableExists($childId)) {
                return $childId;
            }
        }
        return 0;
    }

    private function cleanupRemovedPlanEntries(array $previous, array $current): void
    {
        $removed = array_diff($previous, $current);
        foreach ($removed as $ident) {
            $ident = (string)$ident;
            $this->MaintainVariable($ident, '', 0, '', 0, false);
            $this->MaintainAction($ident, false);
            $this->removeFallbackProfile($ident);
        }
    }

    private function removeFallbackProfile(string $ident): void
    {
        $profileName = self::PROFILE_PREFIX . $this->InstanceID . '.' . $ident;
        if (!IPS_VariableProfileExists($profileName)) {
            return;
        }

        $vid = $this->findVariableId($ident);
        if ($vid > 0 && IPS_VariableExists($vid)) {
            IPS_SetVariableCustomProfile($vid, '');
        }

        if (IPS_VariableProfileExists($profileName)) {
            IPS_DeleteVariableProfile($profileName);
        }
    }

    private function updateReferences(): void
    {
        $references = [];
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        if ($parentId > 0) {
            $references[] = $parentId;
        }
        $this->MaintainReferences($references);
    }

    private function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
            if (is_array($value)) {
                $result += $this->flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }
        return $result;
    }

    private function firstNumericByPaths(array $flat, array $paths): ?float
    {
        foreach ($paths as $path) {
            $path = (string)$path;
            if ($path === '') {
                continue;
            }
            $candidates = [$path, 'property.' . $path, 'value.' . $path, 'profile.' . $path];
            for ($i = 0; $i <= 4; $i++) {
                $candidates[] = 'property.' . $i . '.' . $path;
                $candidates[] = 'value.property.' . $i . '.' . $path;
                $candidates[] = 'profile.property.' . $i . '.' . $path;
                $candidates[] = 'profile.value.property.' . $i . '.' . $path;
            }
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $flat) && is_numeric($flat[$candidate])) {
                    return (float)$flat[$candidate];
                }
            }
        }
        return null;
    }

    private function deepMerge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }

    private function logThrowable(string $context, Throwable $e): void
    {
        $this->SendDebug($context, $e->getMessage(), 0);
    }
}
