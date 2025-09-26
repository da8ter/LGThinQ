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
        $this->RegisterAttributeString('LastPlan', '[]');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Best Practice: Avoid heavy work before KR_READY. Re-run on IPS_KERNELSTARTED
        if ($this->isKernelReady() === false) {
            if (method_exists($this, 'RegisterMessage')) {
                $this->RegisterMessage(0, IPS_KERNELSTARTED);
            }
            return;
        }

        $alias = trim((string)$this->ReadPropertyString('Alias'));
        if ($alias !== '' && IPS_GetName($this->InstanceID) !== $alias) {
            IPS_SetName($this->InstanceID, $alias);
        }

        $this->MaintainVariable('INFO', $this->t('Info'), VARIABLETYPE_STRING, '', 10, true);
        $this->MaintainVariable('STATUS', $this->t('Status'), VARIABLETYPE_STRING, '', 20, true);
        $this->MaintainVariable('LASTUPDATE', $this->t('Last Update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 30, true);
        $this->hideDefaultVariables();

        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            $this->SetStatus(104);
            return;
        }
        // Configuration is complete: mark instance as Ready
        $this->SetStatus(102);

        $info = ['deviceId' => $deviceId, 'alias' => $alias];

        @SetValueString($this->getVarId('INFO'), json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));


        $this->EnsureConnected();

        try {


            $this->SetupDeviceVariables();

        } catch (\Throwable $e) {
            $this->logThrowable('SetupDeviceVariables', $e);
        }

        // Immediately subscribe device to LG push/events if possible; also schedule a one-time retry
        try {
            $deviceID = trim((string)$this->ReadPropertyString('DeviceID'));
            if ($deviceID !== '') {
                $hasParent = !method_exists($this, 'HasActiveParent') || $this->HasActiveParent();
                if ($hasParent) {
                    $this->doAutoSubscribe($deviceID);
                }
                if (method_exists($this, 'RegisterOnceTimer')) {
                    @$this->RegisterOnceTimer('AutoSubscribe', 'LGTQD_AutoSubscribe($_IPS["TARGET"]);');
                }
            }
        } catch (\Throwable $e) {
            $this->logThrowable('AutoSubscribe', $e);
        }

        // One-time initial status fetch (HTTP) to seed STATUS and capability variables
        try {
            $hasParent = !method_exists($this, 'HasActiveParent') || $this->HasActiveParent();
            if ($hasParent) {
                $this->UpdateStatus();
            } elseif (method_exists($this, 'RegisterOnceTimer')) {
                // Defer initial fetch until the parent becomes active
                @$this->RegisterOnceTimer('InitialUpdateStatus', 'LGTQD_UpdateStatus($_IPS["TARGET"]);');
            }
        } catch (\Throwable $e) {
            $this->logThrowable('InitialUpdateStatus', $e);
        }

        // Schedule a finalize setup pass to ensure variables and initial status after the parent becomes active
        try {
            if (method_exists($this, 'RegisterOnceTimer')) {
                @$this->RegisterOnceTimer('FinalizeSetup', 'LGTQD_FinalizeSetup($_IPS["TARGET"]);');
            }
        } catch (\Throwable $e) {
            $this->logThrowable('FinalizeSetup schedule', $e);
        }

        $this->updateReferences();
    }

    private function isKernelReady(): bool
    {
        return function_exists('IPS_GetKernelRunlevel') ? (IPS_GetKernelRunlevel() === KR_READY) : true;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
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

    public function FinalizeSetup(): void
    {
        // Safe property read: during kernel reconfigure or early timer fires,
        // InstanceInterface may be temporarily unavailable, which would emit warnings.
        $deviceID = (string)@($this->ReadPropertyString('DeviceID'));
        if ($deviceID === '') {
            return;
        }
        if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
            if (method_exists($this, 'RegisterOnceTimer')) {
                // Retry later until the gateway becomes active
                @$this->RegisterOnceTimer('FinalizeSetup', 'LGTQD_FinalizeSetup($_IPS["TARGET"]);');
            }
            return;
        }
        try {
            $this->SetupDeviceVariables();
        } catch (\Throwable $e) {
            $this->logThrowable('FinalizeSetup SetupDeviceVariables', $e);
        }
        try {
            $this->UpdateStatus();
        } catch (\Throwable $e) {
            $this->logThrowable('FinalizeSetup UpdateStatus', $e);
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
                $error = '';
                if (isset($decoded['error'])) {
                    $error = (string)$decoded['error'];
                } elseif (isset($decoded['errors']) && is_array($decoded['errors'])) {
                    $error = implode('; ', array_map('strval', $decoded['errors']));
                } elseif (isset($decoded['message'])) {
                    $error = (string)$decoded['message'];
                }
                if ($error === '') {
                    $snippet = substr(preg_replace('/\s+/', ' ', (string)$result), 0, 200);
                    $error = 'unknown error (payload: ' . $snippet . ')';
                }
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
        // Hide default meta variables
        $toHide = ['INFO', 'STATUS', 'LASTUPDATE'];
        foreach ($toHide as $ident) {
            $vid = $this->getVarId($ident);
            if ($vid > 0) {
                @IPS_SetHidden($vid, true);
            }
        }
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

    private function SetupDeviceVariables(): void
    {
        $this->setupDevice();
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
        } elseif ($kind === 'enumeration') {
            // New: Enumeration presentation
            if (!defined('VARIABLE_PRESENTATION_ENUMERATION')) {
                $this->SendDebug('Presentation', 'Enumeration presentation not available in this IP-Symcon version; ident=' . $ident, 0);
                return;
            }
            $payload['PRESENTATION'] = VARIABLE_PRESENTATION_ENUMERATION;
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
                        'Color' => isset($op['color']) ? (int)$op['color'] : -1
                    ];
                }
            }
            if (!empty($options)) {
                $payload['OPTIONS'] = $options;
            }
        } elseif ($kind === 'buttons') {
            $payload['PRESENTATION'] = self::PRES_BUTTONS;
            $payload['ICON'] = '';
            $payload['LAYOUT'] = isset($presentation['layout']) ? (int)$presentation['layout'] : 1;
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
                        'Color' => isset($op['color']) ? (int)$op['color'] : -1
                    ];
                }
            }
            if (!empty($options)) {
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
            // Optional pass-throughs to align with desired schema
            if (array_key_exists('min', $presentation)) {
                $payload['MIN'] = is_numeric($presentation['min']) ? (float)$presentation['min'] : $presentation['min'];
            }
            if (array_key_exists('max', $presentation)) {
                $payload['MAX'] = is_numeric($presentation['max']) ? (float)$presentation['max'] : $presentation['max'];
            }
            if (array_key_exists('prefix', $presentation)) {
                $payload['PREFIX'] = $this->t((string)$presentation['prefix']);
            }
            if (array_key_exists('percentage', $presentation)) {
                $payload['PERCENTAGE'] = (bool)$presentation['percentage'];
            }
            if (array_key_exists('usageType', $presentation) || array_key_exists('usage_type', $presentation)) {
                $payload['USAGE_TYPE'] = (int)($presentation['usageType'] ?? $presentation['usage_type']);
            }
            if (array_key_exists('decimalSeparator', $presentation)) {
                $payload['DECIMAL_SEPARATOR'] = (string)$presentation['decimalSeparator'];
            }
            if (array_key_exists('thousandsSeparator', $presentation)) {
                $payload['THOUSANDS_SEPARATOR'] = (string)$presentation['thousandsSeparator'];
            }
            if (array_key_exists('multiline', $presentation)) {
                $payload['MULTILINE'] = (bool)$presentation['multiline'];
            }
            if (array_key_exists('icon', $presentation)) {
                $payload['ICON'] = (string)$presentation['icon'];
            }
            if (array_key_exists('color', $presentation)) {
                $payload['COLOR'] = (int)$presentation['color'];
            }
            if (array_key_exists('intervalsActive', $presentation)) {
                $payload['INTERVALS_ACTIVE'] = (bool)$presentation['intervalsActive'];
            }
            if (array_key_exists('intervals', $presentation) && is_array($presentation['intervals'])) {
                $payload['INTERVALS'] = $presentation['intervals'];
            }
            // Optional: support OPTIONS for string-valued enumerations on value presentation
            if (isset($presentation['options']) && is_array($presentation['options'])) {
                $options = [];
                foreach ($presentation['options'] as $op) {
                    if (!is_array($op)) {
                        continue;
                    }
                    if (!array_key_exists('value', $op) || !array_key_exists('caption', $op)) {
                        continue;
                    }
                    $opt = [
                        // For value-presentation we keep string values verbatim
                        'Value' => (string)$op['value'],
                        'Caption' => $this->t((string)$op['caption']),
                        'IconActive' => isset($op['iconActive']) ? (bool)$op['iconActive'] : false,
                        'IconValue' => isset($op['iconValue']) ? (string)$op['iconValue'] : '',
                        'Color' => isset($op['color']) ? (int)$op['color'] : -1
                    ];
                    if (array_key_exists('colorActive', $op)) {
                        $opt['ColorActive'] = (bool)$op['colorActive'];
                    }
                    if (array_key_exists('colorValue', $op)) {
                        $opt['ColorValue'] = (int)$op['colorValue'];
                    }
                    if (array_key_exists('colorDisplay', $op)) {
                        $opt['ColorDisplay'] = (int)$op['colorDisplay'];
                    }
                    $options[] = $opt;
                }
                if (!empty($options)) {
                    $payload['OPTIONS'] = $options;
                }
            }
        }

        if (empty($payload)) {
            return;
        }

        $payload = $this->translatePresentationPayload($payload);

        // Debug: show outgoing presentation payload
        $this->SendDebug('Presentation', 'Preparing ident=' . $ident . ' kind=' . $kind . ' payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);

        // Clear any previously assigned custom profile so presentation can take effect
        @IPS_SetVariableCustomProfile($vid, '');

        if (function_exists('IPS_SetVariableCustomPresentation')) {
            $payloadEncoded = $payload;
            $hadOptionsArray = isset($payload['OPTIONS']) && is_array($payload['OPTIONS']);
            if ($hadOptionsArray) {
                $payloadEncoded['OPTIONS'] = json_encode($payload['OPTIONS'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            @IPS_SetVariableCustomPresentation($vid, $payloadEncoded);
            // Debug: verify if custom presentation has been applied
            $varInfo = @IPS_GetVariable($vid);
            $post = is_array($varInfo) && array_key_exists('VariableCustomPresentation', $varInfo) ? $varInfo['VariableCustomPresentation'] : null;
            $this->SendDebug('Presentation', 'Applied ident=' . $ident . ' post=' . (is_string($post) ? $post : json_encode($post)), 0);
            // If not applied and we encoded OPTIONS, retry once with OPTIONS as array (compat mode)
            $notApplied = ($post === null || $post === '' || $post === false);
            if ($notApplied && $hadOptionsArray) {
                $this->SendDebug('Presentation', 'Retry ident=' . $ident . ' with OPTIONS as array (compatibility attempt)', 0);
                @IPS_SetVariableCustomPresentation($vid, $payload);
            }
        } else {
            // Explicitly no fallback to old variable profiles per requirement
            $this->SendDebug('Presentation', 'IPS_SetVariableCustomPresentation not available; skipping ident=' . $ident, 0);
            return;
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

    private function doAutoSubscribe(string $deviceId): void
    {
        if ($deviceId === '') {
            return;
        }
        $this->sendAction('SubscribeDevice', ['DeviceID' => $deviceId, 'Push' => true, 'Event' => true]);
    }

    public function AutoSubscribe(): void
    {
        // Safe property read: avoid noisy warnings if instance interface is not yet available
        $deviceID = (string)@($this->ReadPropertyString('DeviceID'));
        if ($deviceID === '') {
            return;
        }
        if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
            $this->EnsureConnected();
            if (method_exists($this, 'RegisterOnceTimer')) {
                // Retry later if the gateway is not yet active
                @$this->RegisterOnceTimer('AutoSubscribe', 'LGTQD_AutoSubscribe($_IPS["TARGET"]);');
            }
            return;
        }
        try {
            $this->doAutoSubscribe($deviceID);
        } catch (\Throwable $e) {
            // Suppress noisy log while the gateway is not yet connected; just retry later
            if (stripos($e->getMessage(), 'No active LG ThinQ bridge connected') === false) {
                $this->logThrowable('AutoSubscribe', $e);
            }
            if (method_exists($this, 'RegisterOnceTimer')) {
                @$this->RegisterOnceTimer('AutoSubscribe', 'LGTQD_AutoSubscribe($_IPS["TARGET"]);');
            }
        }
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

    private function updateFromStatus(array $status): void
    {
        // Placeholder for mapping selected status fields to dedicated variables
        // Keep empty to avoid fatal errors; CapabilityEngine->applyStatus handles most updates
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

    private function updateReferences(): void
    {
        $references = [];
        $inst = @IPS_GetInstance($this->InstanceID);
        $parentId = is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
        if ($parentId > 0) {
            $references[] = $parentId;
        }
        if (method_exists($this, 'MaintainReferences')) {
            $this->MaintainReferences($references);
        }
    }


    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }

    private function maskText(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (strlen($s) <= 4) return substr($s, 0, 1) . '***' . substr($s, -1);
        return substr($s, 0, 2) . '***' . substr($s, -2);
    }

    /**
     * Recursively anonymize sensitive strings in an array structure.
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function anonymizeArray(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            // Preserve specific fields verbatim (requested: deviceType, modelName)
            $keyNorm = strtolower((string)$k);
            $preserve = ($keyNorm === 'devicetype' || $keyNorm === 'modelname' || $keyNorm === 'device_type' || $keyNorm === 'model_name');
            if ($preserve && is_string($v)) {
                $out[$k] = $v;
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $this->anonymizeArray($v);
            } elseif (is_string($v)) {
                $out[$k] = $this->anonymizeText($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function anonymizeText(string $s): string
    {
        $r = $s;
        // Emails -> ***@***
        $r = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+/i', '***@***', $r);
        // IPv4 -> ***.***.***.***
        $r = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '***.***.***.***', $r);
        // MAC -> **:**:**:**:**:**
        $r = preg_replace('/\b([0-9A-Fa-f]{2}[:\-]){5}([0-9A-Fa-f]{2})\b/', '**:**:**:**:**:**', $r);
        // UUID -> ****-****-****-****-XXXXXXXX
        $r = preg_replace_callback('/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/', function ($m) {
            $t = (string)$m[0];
            return '****-****-****-****-' . substr($t, -12);
        }, $r);
        // Long tokens/IDs (12+ alnum/_-) -> keep first 2 and last 2
        $r = preg_replace_callback('/[A-Za-z0-9_\-]{12,}/', function ($m) {
            $t = (string)$m[0];
            return substr($t, 0, 2) . '***' . substr($t, -2);
        }, $r);
        return $r;
    }

    private function logThrowable(string $context, \Throwable $e): void
    {
        $this->SendDebug($context, $e->getMessage(), 0);
    }

    public function UIExportSupportBundle(): string
    {
        try {
            $zipData = $this->buildSupportBundleZip();
            return 'data:application/zip;base64,' . base64_encode($zipData);
        } catch (\Throwable $e) {
            $this->SendDebug('UIExportSupportBundle', $e->getMessage(), 0);
            return 'data:text/plain,' . rawurlencode($this->t('Error creating support package') . ': ' . $e->getMessage());
        }
    }

    private function buildSupportBundleZip(): string
    {
        $zip = new \ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'lgtqd_');
        if ($tmp === false) {
            throw new \RuntimeException('Konnte temporäre Datei nicht erstellen');
        }
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Konnte ZIP nicht öffnen');
        }
        // 00_meta.json
        $meta = [
            'module' => 'LG ThinQ Device',
            'instanceId' => $this->InstanceID,
            'alias' => @IPS_GetName($this->InstanceID),
            'timestamp' => date('c'),
            'phpVersion' => PHP_VERSION,
            'kernelVersion' => function_exists('IPS_GetKernelVersion') ? @IPS_GetKernelVersion() : ''
        ];
        $zip->addFromString('00_meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // Determine DeviceID
        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));

        // 10_device_info.json (from GetDevices, anonymized)
        $devices = [];
        try {
            $devRaw = $this->sendAction('GetDevices');
            $devDec = json_decode((string)$devRaw, true);
            $devices = is_array($devDec) ? $this->anonymizeArray($devDec) : [];
        } catch (\Throwable $e) {
            $devices = [];
        }
        $zip->addFromString('10_device_info.json', json_encode($devices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // 20_profile_response.json (raw API profile payload) and 21_profile_extracted.json
        $profileRaw = [];
        $profileExtracted = [];
        try {
            if ($deviceId !== '') {
                $pRaw = $this->sendAction('GetProfile', ['DeviceID' => $deviceId]);
                $pDec = json_decode((string)$pRaw, true);
                $profileRaw = is_array($pDec) ? $this->anonymizeArray($pDec) : [];
                $profileExtracted = $this->fetchDeviceProfile($deviceId);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $zip->addFromString('20_profile_response.json', json_encode($profileRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $zip->addFromString('21_profile_extracted.json', json_encode($this->anonymizeArray($profileExtracted), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // 22_profile_keys.txt
        $flatKeys = array_keys($this->flatten(is_array($profileExtracted) ? $profileExtracted : []));
        sort($flatKeys);
        $zip->addFromString('22_profile_keys.txt', implode("\n", $flatKeys));

        // 30_status.json (live if possible, otherwise last)
        $status = [];
        try {
            if ($deviceId !== '') {
                $sRaw = $this->sendAction('GetStatus', ['DeviceID' => $deviceId]);
                $sDec = json_decode((string)$sRaw, true);
                if (is_array($sDec)) {
                    $status = $sDec;
                }
            }
        } catch (\Throwable $e) {
            // ignore, fallback to LastStatus
        }
        if (empty($status)) {
            $ls = (string)$this->ReadAttributeString('LastStatus');
            $ld = json_decode($ls, true);
            $status = is_array($ld) ? $ld : [];
        }
        $zip->addFromString('30_status.json', json_encode($this->anonymizeArray($status), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        // 40_variables.json snapshot (with custom presentation/action info)
        $vars = [];
        foreach ((array)@IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $obj = @IPS_GetObject($childId);
            $var = @IPS_GetVariable($childId);
            if (!is_array($obj) || !is_array($var)) {
                continue;
            }
            $entry = [
                'ID' => (int)$childId,
                'Ident' => (string)($obj['ObjectIdent'] ?? ''),
                'Name' => (string)($obj['ObjectName'] ?? ''),
                'Type' => (int)($var['VariableType'] ?? -1),
                'Profile' => (string)($var['VariableProfile'] ?? ''),
                'CustomProfile' => (string)($var['VariableCustomProfile'] ?? ''),
                'CustomAction' => (int)($var['VariableCustomAction'] ?? 0)
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
            $type = trim((string)$this->ReadAttributeString('DeviceType'));
            if ($type === '' && is_array($profileExtracted)) {
                $type = (string)($profileExtracted['deviceType'] ?? '');
            }
            $engine = $this->getCapabilityEngine();
            $engine->loadCapabilities($type !== '' ? $type : 'ac', is_array($profileExtracted) ? $profileExtracted : []);
            $descs = $engine->getDescriptors();
            $brief = [];
            foreach ($descs as $cap) {
                if (!is_array($cap)) continue;
                $brief[] = [
                    'ident' => (string)($cap['ident'] ?? ''),
                    'type' => (string)($cap['type'] ?? ''),
                    'name' => (string)($cap['name'] ?? ''),
                    'presentation' => isset($cap['presentation']) && is_array($cap['presentation']) ? ($cap['presentation']['kind'] ?? '') : '',
                    'enableWhen' => (string)($cap['action']['enableWhen'] ?? '')
                ];
            }
            $summary = [
                'deviceType' => $type,
                'descriptorCount' => count($descs),
                'identsToEnable' => $engine->listIdentsToEnable(),
                'identsToEnableOnSetup' => $engine->listIdentsToEnableOnSetup(),
                'descriptors' => $brief
            ];
        } catch (\Throwable $e) {
            $summary = ['error' => $e->getMessage()];
        }
        $zip->addFromString('50_capabilities_summary.json', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $zip->close();
        $data = (string)@file_get_contents($tmp);
        @unlink($tmp);
        if ($data === '') {
            throw new \RuntimeException('ZIP leer');
        }
        return $data;
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

    
}
