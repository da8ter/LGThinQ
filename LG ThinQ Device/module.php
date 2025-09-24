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

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Alias', '');

        $this->RegisterAttributeString('LastStatus', '');
        $this->RegisterAttributeString('DeviceType', '');
        $this->RegisterAttributeString('LastProfile', '');
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
            @IPS_SetName($this->InstanceID, $alias);
        }

        $this->ensureVariable($this->InstanceID, 'INFO', 'Info', VARIABLETYPE_STRING);
        $this->ensureVariable($this->InstanceID, 'STATUS', 'Status', VARIABLETYPE_STRING);
        $this->ensureVariable($this->InstanceID, 'LASTUPDATE', 'Last Update', VARIABLETYPE_INTEGER, '~UnixTimestamp');

        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            $this->SetStatus(104);
            return;
        }

        $info = ['deviceId' => $deviceId, 'alias' => $alias];
        @SetValueString($this->getVarId('INFO'), json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->EnsureConnected();

        try {

            $this->SetupDeviceVariables();

        } catch (\Throwable $e) {
            $this->logThrowable('SetupDeviceVariables', $e);
        }

        try {

            $deviceID = (string)$this->ReadPropertyString('DeviceID');
            if ($deviceID !== '') {
                $this->sendAction('SubscribeDevice', ['DeviceID' => $deviceID, 'Push' => true, 'Event' => true]);
            }
        } catch (\Throwable $e) {

            $this->logThrowable('AutoSubscribe', $e);
        }
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
            @SetValueString($this->getVarId('STATUS'), $encoded);
            @SetValueInteger($this->getVarId('LASTUPDATE'), time());

            $this->WriteAttributeString('LastStatus', json_encode($status));

            // Dedizierte Variablen aktualisieren
            $this->updateFromStatus($status);
            // CapabilityEngine: Werte anwenden
            try {
                $this->getCapabilityEngine()->applyStatus($status);
            } catch (\Throwable $e) {
                $this->logThrowable('UpdateStatus applyStatus', $e);
            }
        } catch (\Throwable $e) {
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
                @$this->RegisterOnceTimer('RefreshAfterControl', 'LGTQD_UpdateStatus($_IPS["TARGET"]);');
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
        @SetValueString($this->getVarId('STATUS'), $encoded);
        @SetValueInteger($this->getVarId('LASTUPDATE'), time());

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

        foreach ($plan as $ident => $entry) {
            if (!($entry['shouldCreate'] ?? false)) {
                continue;
            }

            $ipsType = match (strtoupper((string)($entry['type'] ?? 'STRING'))) {
                'BOOLEAN' => VARIABLETYPE_BOOLEAN,
                'INTEGER' => VARIABLETYPE_INTEGER,
                'FLOAT' => VARIABLETYPE_FLOAT,
                default => VARIABLETYPE_STRING
            };

            $vid = $this->ensureVariable(
                $this->InstanceID,
                (string)$ident,
                (string)($entry['name'] ?? $ident),
                $ipsType
            );

            if (($entry['hidden'] ?? false) && $vid > 0) {
                @IPS_SetHidden($vid, true);
            }

            if (isset($entry['presentation']) && is_array($entry['presentation'])) {
                $this->applyPresentation($vid, (string)$ident, $entry['presentation'], $flatProfile, (string)($entry['type'] ?? 'STRING'));
            }

            if (($entry['enableAction'] ?? false) && method_exists($this, 'EnableAction')) {
                try {
                    $this->EnableAction((string)$ident);
                } catch (Throwable $e) {
                    $this->logThrowable('EnableAction ' . $ident, $e);
                }
            }

            if (array_key_exists('initialValue', $entry)) {
                $this->setValueByVarType((string)$ident, $entry['initialValue']);
            }
        }

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
            @IPS_SetVariableCustomPresentation($vid, $payload);
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
        $varInfo = @IPS_GetVariable($vid);
        if (!is_array($varInfo)) {
            return;
        }

        $profileName = self::PROFILE_PREFIX . $this->InstanceID . '.' . $ident;
        $vt = match (strtoupper($type)) {
            'BOOLEAN' => VARIABLETYPE_BOOLEAN,
            'INTEGER' => VARIABLETYPE_INTEGER,
            'FLOAT' => VARIABLETYPE_FLOAT,
            default => VARIABLETYPE_STRING
        };

        if (@IPS_VariableProfileExists($profileName)) {
            @IPS_SetVariableCustomProfile($vid, '');
            @IPS_DeleteVariableProfile($profileName);
        }
        @IPS_CreateVariableProfile($profileName, $vt);
        @IPS_SetVariableProfileIcon($profileName, '');
        @IPS_SetVariableProfileText($profileName, '', '');

        if ($vt === VARIABLETYPE_BOOLEAN) {
            @IPS_SetVariableProfileAssociation($profileName, 0, $presentation['CAPTION_OFF'] ?? $this->t('Off'), '', -1);
            @IPS_SetVariableProfileAssociation($profileName, 1, $presentation['CAPTION_ON'] ?? $this->t('On'), '', -1);
        } elseif ($vt === VARIABLETYPE_INTEGER || $vt === VARIABLETYPE_FLOAT) {
            $min = isset($presentation['MIN']) ? (float)$presentation['MIN'] : 0.0;
            $max = isset($presentation['MAX']) ? (float)$presentation['MAX'] : 0.0;
            $step = isset($presentation['STEP_SIZE']) ? (float)$presentation['STEP_SIZE'] : 1.0;
            @IPS_SetVariableProfileValues($profileName, $min, $max, $step);
            if (isset($presentation['DIGITS'])) {
                @IPS_SetVariableProfileDigits($profileName, (int)$presentation['DIGITS']);
            }
            if (isset($presentation['SUFFIX'])) {
                @IPS_SetVariableProfileText($profileName, '', (string)$presentation['SUFFIX']);
            }
            if (isset($presentation['OPTIONS']) && is_array($presentation['OPTIONS'])) {
                foreach ($presentation['OPTIONS'] as $option) {
                    if (!isset($option['Value'], $option['Caption'])) {
                        continue;
                    }
                    @IPS_SetVariableProfileAssociation($profileName, (int)$option['Value'], (string)$option['Caption'], '', (int)($option['Color'] ?? -1));
                }
            }
        }

        @IPS_SetVariableCustomProfile($vid, $profileName);
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

    private function ensureVariable(int $parentId, string $ident, string $name, int $type, string $profile = ''): int
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($vid && IPS_ObjectExists($vid)) {
            $translatedName = $this->t($name);
            if (IPS_GetName($vid) !== $translatedName) {
                IPS_SetName($vid, $translatedName);
            }
            if ($profile !== '') {
                @IPS_SetVariableCustomProfile($vid, $profile);
            }
            $this->applyDefaultHiddenFlags($vid, $ident);
            return $vid;
        }

        $vid = IPS_CreateVariable($type);
        IPS_SetParent($vid, $parentId);
        IPS_SetIdent($vid, $ident);
        IPS_SetName($vid, $this->t($name));
        if ($profile !== '') {
            @IPS_SetVariableCustomProfile($vid, $profile);
        }
        $this->applyDefaultHiddenFlags($vid, $ident);
        return $vid;
    }

    private function applyDefaultHiddenFlags(int $vid, string $ident): void
    {
        $hide = [
            'INFO', 'STATUS', 'LASTUPDATE',
            'TIMER_START_REL_HOUR', 'TIMER_START_REL_MIN',
            'TIMER_STOP_REL_HOUR', 'TIMER_STOP_REL_MIN',
            'SLEEP_STOP_REL_HOUR', 'SLEEP_STOP_REL_MIN',
            'TIMER_START_ABS_HOUR', 'TIMER_START_ABS_MIN',
            'TIMER_STOP_ABS_HOUR', 'TIMER_STOP_ABS_MIN'
        ];
        if (in_array($ident, $hide, true)) {
            @IPS_SetHidden($vid, true);
        }
    }

    private function getVarId(string $ident): int
    {
        return (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    }

    private function getCapabilityEngine(): CapabilityEngine
    {
        return new CapabilityEngine($this->InstanceID, __DIR__);
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

    private function setValueByVarType(string $ident, $value): void
    {
        $vid = $this->getVarId($ident);
        if ($vid <= 0) {
            return;
        }
        $var = @IPS_GetVariable($vid);
        if (!is_array($var)) {
            return;
        }
        switch ((int)$var['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                @SetValueBoolean($vid, (bool)$value);
                break;
            case VARIABLETYPE_INTEGER:
                @SetValueInteger($vid, (int)$value);
                break;
            case VARIABLETYPE_FLOAT:
                @SetValueFloat($vid, (float)$value);
                break;
            case VARIABLETYPE_STRING:
                @SetValueString($vid, (string)$value);
                break;
        }
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
        $id = trim($id);
        if ($id === '') return '';
        $len = strlen($id);
        if ($len <= 4) return str_repeat('*', max(0, $len - 1)) . substr($id, -1);
        return str_repeat('*', $len - 4) . substr($id, -4);
    }

    private function maskText(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (strlen($s) <= 4) return substr($s, 0, 1) . '***' . substr($s, -1);
        return substr($s, 0, 2) . '***' . substr($s, -2);
    }

    private function logThrowable(string $context, \Throwable $e): void
    {
        $this->SendDebug($context, $e->getMessage(), 0);
    }


    public function UIExportSupportBundle(): string
    {
        // Generate a support bundle on-the-fly and return a Data-URL for direct download
        try {
            $deviceID = (string)$this->ReadPropertyString('DeviceID');
            $alias    = (string)$this->ReadPropertyString('Alias');
            $type     = (string)$this->ReadAttributeString('DeviceType');

            $ts = date('Ymd_His');
            $devShort = $this->maskId($deviceID);

            // Collect metadata
            $meta = [
                'instanceId'    => $this->InstanceID,
                'module'        => 'LG ThinQ Device',
                'moduleGUID'    => '{B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}',
                'timestamp'     => date('c'),
                'phpVersion'    => PHP_VERSION,
                'deviceIdMasked'=> $devShort,
                'deviceIdHash'  => $deviceID !== '' ? sha1($deviceID) : '',
                'aliasMasked'   => $alias !== '' ? $this->maskText($alias) : '',
                'deviceType'    => $type,
            ];

            // GetDevices and filter current device
            $deviceInfo = null;
            try {
                $listJson = $this->sendAction('GetDevices');
                $devs = @json_decode((string)$listJson, true);
                if (is_array($devs)) {
                    foreach ($devs as $d) {
                        if (is_array($d) && ($d['deviceId'] ?? '') === $deviceID) { $deviceInfo = $d; break; }
                    }
                }
            } catch (\Throwable $e) {
                $deviceInfo = ['error' => $e->getMessage()];
            }
            $deviceInfoSan = is_array($deviceInfo) ? $this->redactDeep($deviceInfo) : null;

            // Profile (raw response + extracted profile/property)
            $profileResponse = null; $extractedProfile = [];
            try {
                $profileRaw = ($deviceID !== '') ? $this->sendAction('GetProfile', ['DeviceID' => $deviceID]) : '{}';
            } catch (\Throwable $e) {
                $profileRaw = '{}';
            }
            $profileResponse = @json_decode((string)$profileRaw, true);
            if (is_array($profileResponse)) {
                if (isset($profileResponse['property']) && is_array($profileResponse['property'])) {
                    $extractedProfile = $profileResponse['property'];
                } elseif (isset($profileResponse['profile']) && is_array($profileResponse['profile'])) {
                    $extractedProfile = $profileResponse['profile'];
                } else {
                    $extractedProfile = $profileResponse; // may already be the profile
                }
            }
            $profileSan = $this->redactDeep($extractedProfile);

            // Status (live or last attribute)
            $statusArr = null;
            try {
                if ($deviceID !== '') {
                    $statusRaw = $this->sendAction('GetStatus', ['DeviceID' => $deviceID]);
                    $tmp = @json_decode((string)$statusRaw, true);
                    if (is_array($tmp)) { $statusArr = $tmp; }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            if (!is_array($statusArr)) {
                $last = (string)$this->ReadAttributeString('LastStatus');
                $tmp = @json_decode($last, true);
                $statusArr = is_array($tmp) ? $tmp : [];
            }
            $statusSan = $this->redactDeep($statusArr);

            // Capability descriptors summary
            $capsSummary = [];
            try {
                $profRaw = (string)$this->ReadAttributeString('LastProfile');
                $prof = @json_decode($profRaw, true);
                if (!is_array($prof)) { $prof = []; }
                $eng = $this->getCapabilityEngine();
                $eng->loadCapabilities((string)$type, $prof);
                $descs = $eng->getDescriptors();
                foreach ($descs as $cap) {
                    if (!is_array($cap)) continue;
                    $capsSummary[] = [
                        'ident' => (string)($cap['ident'] ?? ''),
                        'type'  => (string)($cap['type'] ?? ''),
                        'presentation' => $cap['presentation']['kind'] ?? null,
                        'create' => $cap['create']['when'] ?? null,
                        'actionEnableWhen' => $cap['action']['enableWhen'] ?? null
                    ];
                }
            } catch (\Throwable $e) {
                $capsSummary = [['error' => $e->getMessage()]];
            }

            // Variables snapshot
            $vars = [];
            $children = @IPS_GetChildrenIDs($this->InstanceID);
            if (is_array($children)) {
                foreach ($children as $cid) {
                    $obj = @IPS_GetObject($cid);
                    if (!is_array($obj) || (int)($obj['ObjectType'] ?? -1) !== 2) continue; // only variables
                    $var = @IPS_GetVariable($cid);
                    if (!is_array($var)) continue;
                    $vt = (int)($var['VariableType'] ?? -1);
                    $val = null;
                    if ($vt === VARIABLETYPE_BOOLEAN) { $val = @GetValueBoolean($cid); }
                    elseif ($vt === VARIABLETYPE_INTEGER) { $val = @GetValueInteger($cid); }
                    elseif ($vt === VARIABLETYPE_FLOAT) { $val = @GetValueFloat($cid); }
                    elseif ($vt === VARIABLETYPE_STRING) { $val = @GetValueString($cid); }
                    $vars[] = [
                        'vid' => (int)$cid,
                        'ident' => (string)($obj['ObjectIdent'] ?? ''),
                        'name' => (string)($obj['ObjectName'] ?? ''),
                        'type' => $vt,
                        'profile' => (string)($var['VariableCustomProfile'] ?? ''),
                        'presentation' => isset($var['VariableCustomPresentation']) ? $var['VariableCustomPresentation'] : null,
                        'customAction' => $var['VariableCustomAction'] ?? null,
                        'value' => $val
                    ];
                }
            }

            // Try to create ZIP on-the-fly (preferred)
            if (class_exists('ZipArchive')) {
                $tmpZip = @tempnam(sys_get_temp_dir(), 'lgtqd_zip_');
                if (is_string($tmpZip) && $tmpZip !== '') {
                    // Ensure .zip extension (some environments require it)
                    $zipPath = $tmpZip . '.zip';
                    @rename($tmpZip, $zipPath);
                    $zip = new \ZipArchive();
                    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                        @$zip->addFromString('00_meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('10_device_info.json', json_encode($deviceInfoSan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('20_profile_response.json', json_encode($this->redactDeep($profileResponse), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('21_profile_extracted.json', json_encode($profileSan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('22_profile_keys.txt', implode("\n", array_keys($this->flatten($extractedProfile))));
                        @$zip->addFromString('30_status.json', json_encode($statusSan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('40_variables.json', json_encode($vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('50_capabilities_summary.json', json_encode($capsSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $zip->close();

                        $bin = @file_get_contents($zipPath);
                        @unlink($zipPath);
                        if (is_string($bin) && $bin !== '') {
                            return 'data:application/zip;base64,' . base64_encode($bin);
                        }
                    } else {
                        // Cleanup renamed tmp if open failed
                        @unlink($zipPath);
                    }
                }
            }

            // Fallback: return a single JSON with aggregated data
            $bundle = [
                'meta' => $meta,
                'device' => $deviceInfoSan,
                'profile_raw' => $this->redactDeep($profileResponse),
                'profile_extracted' => $profileSan,
                'profile_keys' => array_keys($this->flatten($extractedProfile)),
                'status' => $statusSan,
                'variables' => $vars,
                'capabilities_summary' => $capsSummary
            ];
            return 'data:application/json;base64,' . base64_encode(json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // Last resort: return error as JSON
            $err = [ 'error' => $e->getMessage() ];
            return 'data:application/json;base64,' . base64_encode(json_encode($err, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    public function GetConfigurationForm()
    {
        // Load base form.json and append a dynamic label with the last bundle URL
        $formPath = __DIR__ . '/form.json';
        $raw = @file_get_contents($formPath);
        $form = is_string($raw) ? @json_decode($raw, true) : null;
        if (!is_array($form)) {
            $form = ['elements' => [], 'actions' => [], 'status' => []];
        }
        if (!isset($form['actions']) || !is_array($form['actions'])) {
            $form['actions'] = [];
        }


        // Provide a direct download button (Data-URL) with a suggested filename
        $dlExt = class_exists('ZipArchive') ? 'zip' : 'json';
        $dlName = sprintf('lgtqd_support_%d.%s', $this->InstanceID, $dlExt);
        $form['actions'][] = [
            'type' => 'Button',
            'label' => $this->t('Support-Paket herunterladen (ZIP)'),
            'download' => $dlName,
            'onClick' => 'echo LGTQD_UIExportSupportBundle($id);'
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
