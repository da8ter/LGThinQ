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
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterAttributeString('LastStatus', '');
        $this->RegisterAttributeString('DeviceType', '');
        $this->RegisterAttributeString('LastProfile', '');
        $this->RegisterAttributeString('LastPlan', '[]');
    }

    /**
     * Preview variables that would be deleted by CleanupVariables(true)
     * @return string Multiline list and summary
     */
    public function UICleanupPreview(): string
    {
        try {
            $profile = $this->readStoredProfile();
            $status = $this->readLastStatus();
            $type = trim((string)$this->ReadAttributeString('DeviceType'));
            if ($type === '') {
                return $this->t('No DeviceType set – aborting.');
            }
            $engine = $this->getCapabilityEngine();
            $plan = $engine->buildPlan($type, $profile, $status);
            $valid = array_fill_keys(array_keys($plan), true);
            $valid['INFO'] = true; $valid['STATUS'] = true; $valid['LASTUPDATE'] = true;

            $children = @IPS_GetChildrenIDs($this->InstanceID);
            if (!is_array($children)) {
                return $this->t('No children.');
            }
            $unknown = [];
            foreach ($children as $cid) {
                $var = @IPS_GetVariable($cid);
                if (!is_array($var)) { continue; }
                $obj = @IPS_GetObject($cid);
                if (!is_array($obj)) { continue; }
                $ident = (string)($obj['ObjectIdent'] ?? '');
                if ($ident === '' || isset($valid[$ident])) {
                    continue; // not targeted for deletion by CleanupVariables(true)
                }
                $unknown[] = sprintf('%s (ID %d, Name "%s")',
                    $ident !== '' ? $ident : '(no ident)',
                    (int)$cid,
                    (string)($obj['ObjectName'] ?? '')
                );
            }
            if (empty($unknown)) {
                return $this->t('Keine Variablen zum Löschen gefunden.');
            }
            $header = $this->t('Folgende Variablen würden gelöscht werden') . ":\n";
            return $header . implode("\n", $unknown) . "\n\n" .
                sprintf($this->t('Summe: %d'), count($unknown));
        } catch (\Throwable $e) {
            $this->logThrowable('UICleanupPreview', $e);
            return 'UICleanupPreview failed: ' . $e->getMessage();
        }
    }
    
    // Configuration form is provided via form.json

    /**
     * Reapply current capability presentations to existing variables.
     * Useful after changing capability JSON or presentation schema.
     */
    public function ReapplyPresentations(): void
    {
        try {
            $profile = $this->readStoredProfile();
            $type = trim((string)$this->ReadAttributeString('DeviceType'));
            if ($type === '') {
                return;
            }

            $engine = $this->getCapabilityEngine();
            // Use latest status to allow presentation derived from profile while keeping values
            $status = $this->readLastStatus();
            $plan = $engine->buildPlan($type, $profile, $status);
            $flatProfile = $this->flatten($profile);

            foreach ($plan as $ident => $entry) {
                $vid = (int)@IPS_GetObjectIDByIdent((string)$ident, $this->InstanceID);
                if ($vid <= 0) {
                    continue;
                }
                $presentation = $entry['presentation'] ?? null;
                if (is_array($presentation) && !empty($presentation)) {
                    $this->applyPresentation($vid, (string)$ident, $presentation, $flatProfile, (string)($entry['type'] ?? 'STRING'));
                }
            }
        } catch (\Throwable $e) {
            $this->logThrowable('ReapplyPresentations', $e);
        }
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

        // Subscribe device to LG push/events once if possible; no automatic retries
        try {
            $deviceID = trim((string)$this->ReadPropertyString('DeviceID'));
            if ($deviceID !== '') {
                $hasParent = !method_exists($this, 'HasActiveParent') || $this->HasActiveParent();
                if ($hasParent) {
                    $this->doAutoSubscribe($deviceID);
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
                if (method_exists($this, 'SetTimerInterval')) {
                    @$this->SetTimerInterval('InitialUpdateStatus', 60000);
                }
            }
        } catch (\Throwable $e) {
            $this->logThrowable('InitialUpdateStatus', $e);
        }

        // Schedule a finalize setup pass to ensure variables and initial status after the parent becomes active
        try {
            if (method_exists($this, 'RegisterOnceTimer')) {
                @$this->RegisterOnceTimer('FinalizeSetup', 'LGTQD_FinalizeSetup($_IPS["TARGET"]);');
                if (method_exists($this, 'SetTimerInterval')) {
                    @$this->SetTimerInterval('FinalizeSetup', 60000);
                }
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

        // Ensure one-shot timers do not turn into periodic ones
        if (method_exists($this, 'SetTimerInterval')) {
            // These timers should only fire once; disable after running UpdateStatus
            @$this->SetTimerInterval('InitialUpdateStatus', 0);
        }

        try {
            $payload = $this->sendAction('GetStatus', ['DeviceID' => $deviceId]);
            $status = json_decode((string)$payload, true);
            if (!is_array($status)) {
                throw new Exception($this->t('Invalid status response'));
            }

            // Normalize shapes: { state: {...} } and [ { ... } ] -> { ... }
            if (isset($status['state']) && is_array($status['state'])) {
                $status = $status['state'];
            } else {
                $isNumericList = array_keys($status) === range(0, count($status) - 1);
                if ($isNumericList && count($status) === 1 && is_array($status[0])) {
                    $status = $status[0];
                }
            }

            $encoded = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->WriteAttributeString('LastStatus', $encoded);

            @SetValueString($this->getVarId('STATUS'), $encoded);
            @SetValueInteger($this->getVarId('LASTUPDATE'), time());

            $this->WriteAttributeString('LastStatus', json_encode($status));

            // Dedizierte Variablen aktualisieren
            $this->updateFromStatus($status);
            // CapabilityEngine: Werte anwenden
            $engine = $this->prepareEngine();
            if ($engine !== null) {
                try {
                    $engine->applyStatus($status);
                } catch (\Throwable $e) {
                    $this->logThrowable('UpdateStatus applyStatus', $e);
                }
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
                if (method_exists($this, 'SetTimerInterval')) {
                    @$this->SetTimerInterval('FinalizeSetup', 60000);
                }
            }
            return;
        }
        // Parent is active: prevent periodic execution of this once-timer
        if (method_exists($this, 'SetTimerInterval')) {
            @$this->SetTimerInterval('FinalizeSetup', 0);
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
        // One-shot push/event subscribe once parent is active
        try {
            $this->doAutoSubscribe($deviceID);
        } catch (\Throwable $e) {
            $this->logThrowable('FinalizeSetup AutoSubscribe', $e);
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

        $this->SendDebug('RequestAction', sprintf('Calling buildControlPayload for ident=%s, value=%s', $ident, json_encode($value)), 0);
        $payload = $engine->buildControlPayload((string)$ident, $value);
        $this->SendDebug('RequestAction', sprintf('buildControlPayload returned: %s', $payload === null ? 'NULL' : 'array'), 0);
        if (!is_array($payload)) {
            throw new Exception($this->t('Unknown action') . ': ' . $ident);
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->SendDebug('RequestAction', sprintf('Ident=%s, Value=%s, Payload=%s', $ident, json_encode($value), $payloadJson), 0);
        
        $ok = $this->ControlDevice($payloadJson);
        if ($ok) {
            $this->setValueByVarType((string)$ident, $value);
        }
    }

    public function ReceiveData($JSONString)
    {
        $this->SendDebug('ReceiveData', 'Called', 0);
        
        $outer = json_decode((string)$JSONString, true);
        if (!is_array($outer)) {
            $this->SendDebug('ReceiveData', 'Outer not array', 0);
            return '';
        }
        $buf = $outer['Buffer'] ?? null;
        if (is_string($buf)) {
            $buf = json_decode((string)$buf, true);
        }
        if (!is_array($buf)) {
            $this->SendDebug('ReceiveData', 'Buffer not array', 0);
            return '';
        }
        
        $action = (string)($buf['Action'] ?? '');
        $this->SendDebug('ReceiveData', 'Action: ' . $action, 0);
        
        if ($action !== 'Event') {
            return '';
        }

        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        $incomingDeviceId = (string)($buf['DeviceID'] ?? '');
        $this->SendDebug('ReceiveData', sprintf('My DeviceID: %s, Incoming: %s', $deviceId, $incomingDeviceId), 0);
        
        if ($deviceId === '' || strcasecmp($deviceId, $incomingDeviceId) !== 0) {
            $this->SendDebug('ReceiveData', 'DeviceID mismatch - ignoring', 0);
            return '';
        }

        $event = $buf['Event'] ?? null;
        if (!is_array($event)) {
            return '';
        }

        // Normalize shapes: { state: {...} } and [ { ... } ]
        if (isset($event['state']) && is_array($event['state'])) {
            $event = $event['state'];
        } else {
            $isNumericList = array_keys($event) === range(0, count($event) - 1);
            if ($isNumericList && count($event) === 1 && is_array($event[0])) {
                $event = $event[0];
            }
        }

        $current = $this->readLastStatus();
        $merged = $this->deepMerge($current, $event);
        $encoded = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->WriteAttributeString('LastStatus', $encoded);
        @SetValueString($this->getVarId('STATUS'), $encoded);
        @SetValueInteger($this->getVarId('LASTUPDATE'), time());

        // Rebuild engine with updated status to include new properties (e.g. timer properties)
        $profile = $this->readStoredProfile();
        $type = trim((string)$this->ReadAttributeString('DeviceType'));
        
        // Check if status has new properties not in profile → refresh profile from API
        if (!empty($profile) && $this->statusHasNewProperties($merged, $profile)) {
            $this->SendDebug('ReceiveData', 'Status has new properties not in cached profile, refreshing from API...', 0);
            $freshProfile = $this->fetchProfileFromAPI();
            if (!empty($freshProfile)) {
                $profile = $freshProfile;
                $this->WriteAttributeString('LastProfile', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
        
        if ($type !== '' && !empty($profile)) {
            // Use central method for consistency
            $this->ensureDeviceVariablesWithPresentations($profile, $merged, $type);
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
            // Allow requests even if parent is inactive
            $this->SendDebug('sendAction', sprintf('Parent inactive but trying %s anyway', $action), 0);
        }

        $buffer = array_merge(['Action' => $action], $params);
        $packet = [
            'DataID' => self::DATA_FLOW_GUID,
            'Buffer' => json_encode($buffer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];
        
        // Suppress warning if parent has no active interface
        $result = @$this->SendDataToParent(json_encode($packet));
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

        // Use central method for consistency
        $this->ensureDeviceVariablesWithPresentations($profile, $status, $type);
    }

    /**
     * Remove or hide variables that are not part of the current plan.
     * This helps to clean up duplicates created by older discovery versions.
     *
     * @param bool $delete If true, delete unknown variables; if false, only hide them.
     * @return string Summary
     */
    public function CleanupVariables(bool $delete = false): string
    {
        try {
            $profile = $this->readStoredProfile();
            $status = $this->readLastStatus();
            $type = trim((string)$this->ReadAttributeString('DeviceType'));
            if ($type === '') {
                return 'No DeviceType set – aborting.';
            }
            $engine = $this->getCapabilityEngine();
            $plan = $engine->buildPlan($type, $profile, $status);
            $valid = array_fill_keys(array_keys($plan), true);
            // Always keep module meta variables
            $valid['INFO'] = true; $valid['STATUS'] = true; $valid['LASTUPDATE'] = true;

            $children = @IPS_GetChildrenIDs($this->InstanceID);
            if (!is_array($children)) {
                return 'No children.';
            }
            $hidden = 0; $deleted = 0; $kept = 0; $unknown = [];
            foreach ($children as $cid) {
                // Only variables
                $var = @IPS_GetVariable($cid);
                if (!is_array($var)) { continue; }
                $obj = @IPS_GetObject($cid);
                if (!is_array($obj)) { continue; }
                $ident = (string)($obj['ObjectIdent'] ?? '');
                if ($ident === '' || isset($valid[$ident])) {
                    $kept++;
                    continue;
                }
                $unknown[] = ['id' => $cid, 'ident' => $ident, 'name' => (string)($obj['ObjectName'] ?? '')];
                if ($delete) {
                    @IPS_DeleteVariable($cid);
                    $deleted++;
                } else {
                    @IPS_SetHidden($cid, true);
                    $hidden++;
                }
            }
            $summary = sprintf('CleanupVariables: kept=%d, hidden=%d, deleted=%d', $kept, $hidden, $deleted);
            if (!empty($unknown)) {
                $names = array_map(function($e){ return ($e['ident'] !== '' ? $e['ident'] : '(no ident)') . ' [' . $e['name'] . ']'; }, $unknown);
                $this->SendDebug('CleanupVariables', 'Unknown: ' . implode(', ', $names), 0);
            }
            return $summary;
        } catch (\Throwable $e) {
            $this->logThrowable('CleanupVariables', $e);
            return 'CleanupVariables failed: ' . $e->getMessage();
        }
    }

    private function SetupDeviceVariables(): void
    {
        $this->setupDevice();
    }
    
    /**
     * Central method to ensure variables with presentations and actions
     * Used by both setupDevice() and ReceiveData()
     * 
     * @param array $profile
     * @param array $status
     * @param string $type
     * @return void
     */
    private function ensureDeviceVariablesWithPresentations(array $profile, array $status, string $type): void
    {
        $engine = $this->getCapabilityEngine();
        $plan = $engine->buildPlan($type, $profile, $status);
        // Track which variables exist before creation to apply presentations/actions only once
        $preExisting = [];
        foreach ($plan as $ident => $_) {
            $preExisting[(string)$ident] = ((int)@IPS_GetObjectIDByIdent((string)$ident, $this->InstanceID)) > 0;
        }
        $engine->ensureVariables($profile, $status, $type);
        
        // Apply presentations and enable actions only for newly created variables
        $flatProfile = $this->flatten($profile);
        foreach ($plan as $ident => $entry) {
            $vid = $this->getVarId((string)$ident);
            if ($vid <= 0) {
                continue;
            }
            $justCreated = !($preExisting[(string)$ident] ?? false);

            // Apply presentation only on creation
            if ($justCreated && isset($entry['presentation']) && is_array($entry['presentation'])) {
                $this->applyPresentation($vid, (string)$ident, $entry['presentation'], $flatProfile, (string)($entry['type'] ?? 'STRING'));
            }

            // Enable action only on creation if defined
            if ($justCreated && ($entry['enableAction'] ?? false) && method_exists($this, 'EnableAction')) {
                try {
                    $this->EnableAction((string)$ident);
                } catch (\Throwable $e) {
                    // Silent fail
                }
            }
        }
        
        // Apply status values
        $engine->applyStatus($status);
    }

    private function prepareEngine(): ?CapabilityEngine
    {
        $profile = $this->readStoredProfile();
        $type = trim((string)$this->ReadAttributeString('DeviceType'));
        if ($type === '') {
            return null;
        }
        $engine = $this->getCapabilityEngine();
        // Build plan to load capabilities including auto-discovery
        $status = $this->readLastStatus();
        $engine->buildPlan($type, $profile, $status);
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
        // Prefer fresh information from the device list each time
        $did = trim($deviceId);
        $this->SendDebug('ResolveDeviceType', 'Begin: deviceId=' . $did, 0);
        try {
            $listRaw = $this->sendAction('GetDevices');
            $list = json_decode((string)$listRaw, true);
            if (!is_array($list)) {
                $this->SendDebug('ResolveDeviceType', 'GetDevices returned non-array payload', 0);
            } else {
                $this->SendDebug('ResolveDeviceType', 'Devices count=' . count($list), 0);
                foreach ($list as $idx => $entry) {
                    if (!is_array($entry)) {
                        $this->SendDebug('ResolveDeviceType', 'Entry #' . $idx . ' not an object', 0);
                        continue;
                    }
                    $keys = implode(',', array_keys($entry));
                    // Accept multiple id field variants
                    $candId = (string)($entry['deviceId'] ?? ($entry['device_id'] ?? ($entry['id'] ?? '')));
                    if ($candId === '') {
                        $this->SendDebug('ResolveDeviceType', 'Entry #' . $idx . ' missing id fields; keys=' . $keys, 0);
                        continue;
                    }
                    $match = (strcasecmp($candId, $did) === 0);
                    if (!$match) {
                        // Also allow substring match when IDs include prefixes/suffixes (rare)
                        $match = (strpos($candId, $did) !== false) || (strpos($did, $candId) !== false);
                    }
                    if (!$match) {
                        continue;
                    }

                    // Found matching device entry: try different type fields
                    $typeDirect = (string)($entry['deviceType'] ?? '');
                    $typeInfo   = '';
                    if (isset($entry['deviceInfo']) && is_array($entry['deviceInfo'])) {
                        $typeInfo = (string)($entry['deviceInfo']['deviceType'] ?? '');
                    }
                    $type = $typeDirect !== '' ? $typeDirect : $typeInfo;
                    $this->SendDebug('ResolveDeviceType', sprintf('Match at #%d: id=%s typeDirect=%s typeInfo=%s', $idx, substr($candId, 0, 8) . '…', $typeDirect, $typeInfo), 0);
                    if ($type !== '') {
                        return $type;
                    }
                    $this->SendDebug('ResolveDeviceType', 'Matching entry has no deviceType fields; keys=' . $keys, 0);
                }
            }
        } catch (Throwable $e) {
            $this->logThrowable('ResolveDeviceType', $e);
        }

        /*
        // Fallback: previously detected type (cached) — keep for stability but log it
        $cached = trim((string)$this->ReadAttributeString('DeviceType'));
        if ($cached !== '') {
            $this->SendDebug('ResolveDeviceType', 'Using cached DeviceType=' . $cached, 0);
            return $cached;
        }
        */

        // No fallback to profile or AC by request — return empty to surface the issue upstream
        $this->SendDebug('ResolveDeviceType', 'FAILED to resolve device type from device list; returning empty', 0);
        return '';
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
            // Optional pass-throughs for slider presentation
            if (array_key_exists('usageType', $presentation) || array_key_exists('usage_type', $presentation)) {
                $payload['USAGE_TYPE'] = (int)($presentation['usageType'] ?? $presentation['usage_type']);
            }
            if (array_key_exists('gradientType', $presentation) || array_key_exists('gradient_type', $presentation)) {
                $payload['GRADIENT_TYPE'] = (int)($presentation['gradientType'] ?? $presentation['gradient_type']);
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
                    // Keep value as-is (can be string or integer)
                    $value = $op['value'];
                    if ($type === 'STRING' && !is_string($value)) {
                        $value = (string)$value;
                    } elseif ($type !== 'STRING' && !is_int($value)) {
                        $value = (int)$value;
                    }
                    $options[] = [
                        'Value' => $value,
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
            // Add OPTIONS for enum values with captions
            if (isset($presentation['options']) && is_array($presentation['options']) && !isset($payload['OPTIONS'])) {
                $options = [];
                foreach ($presentation['options'] as $op) {
                    if (!is_array($op) || !array_key_exists('value', $op) || !array_key_exists('caption', $op)) {
                        continue;
                    }
                    // Keep value as-is (can be string or integer)
                    $value = $op['value'];
                    if ($type === 'STRING' && !is_string($value)) {
                        $value = (string)$value;
                    } elseif ($type !== 'STRING' && !is_int($value)) {
                        $value = (int)$value;
                    }
                    $options[] = [
                        'Value' => $value,
                        'Caption' => $this->t((string)$op['caption']),
                        'IconActive' => false,
                        'IconValue' => '',
                        'ColorActive' => false,
                        'ColorValue' => -1,
                        'Color' => -1,
                        'ColorDisplay' => -1
                    ];
                }
                if (!empty($options)) {
                    $payload['OPTIONS'] = $options;
                }
            }
            // If BOOLEAN with value-presentation and captionOn/captionOff provided, map them to OPTIONS
            if (strtoupper((string)$type) === 'BOOLEAN' && (isset($presentation['captionOn']) || isset($presentation['captionOff'])) && !isset($payload['OPTIONS'])) {
                $onCaption = $this->t((string)($presentation['captionOn'] ?? $this->t('On')));
                $offCaption = $this->t((string)($presentation['captionOff'] ?? $this->t('Off')));
                $payload['OPTIONS'] = [
                    [
                        'Value' => false,
                        'Caption' => $offCaption,
                        'IconActive' => false,
                        'IconValue' => '',
                        'Color' => -1
                    ],
                    [
                        'Value' => true,
                        'Caption' => $onCaption,
                        'IconActive' => false,
                        'IconValue' => '',
                        'Color' => -1
                    ]
                ];
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
                        // For value-presentation keep type as-is to support boolean/integer comparisons in UI
                        'Value' => is_numeric($op['value']) ? (float)$op['value'] + 0 : ( (is_bool($op['value'])) ? ((bool)$op['value'] ? 1 : 0) : (string)$op['value'] ),
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
            // Ensure all expected keys exist for value presentation (even if empty) to satisfy UI schema
            $defaults = [
                'DIGITS' => 2,
                'SUFFIX' => '',
                'INTERVALS_ACTIVE' => false,
                'INTERVALS' => [],
                'ICON' => '',
                'DECIMAL_SEPARATOR' => 'Client',
                'COLOR' => -1,
                'MULTILINE' => false,
                'MAX' => 100,
                'THOUSANDS_SEPARATOR' => '',
                'MIN' => 0,
                'PERCENTAGE' => false,
                'PREFIX' => '',
                'USAGE_TYPE' => 0
            ];
            foreach ($defaults as $k => $v) {
                if (!array_key_exists($k, $payload)) {
                    $payload[$k] = $v;
                }
            }
            if (!isset($payload['OPTIONS']) || !is_array($payload['OPTIONS'])) {
                $payload['OPTIONS'] = [];
            }
            // Ensure each option object has all presentation keys with defaults
            if (isset($payload['OPTIONS']) && is_array($payload['OPTIONS'])) {
                foreach ($payload['OPTIONS'] as &$op) {
                    if (!is_array($op)) { $op = []; }
                    if (!array_key_exists('Value', $op)) { $op['Value'] = ''; }
                    if (!array_key_exists('Caption', $op)) { $op['Caption'] = ''; }
                    if (!array_key_exists('IconActive', $op)) { $op['IconActive'] = false; }
                    if (!array_key_exists('IconValue', $op)) { $op['IconValue'] = ''; }
                    if (!array_key_exists('ColorActive', $op)) { $op['ColorActive'] = false; }
                    if (!array_key_exists('ColorValue', $op)) { $op['ColorValue'] = -1; }
                    if (!array_key_exists('Color', $op)) { $op['Color'] = -1; }
                    if (!array_key_exists('ColorDisplay', $op)) { $op['ColorDisplay'] = -1; }
                }
                unset($op);
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
            $hadIntervalsArray = isset($payload['INTERVALS']) && is_array($payload['INTERVALS']);
            if ($hadIntervalsArray) {
                $payloadEncoded['INTERVALS'] = json_encode($payload['INTERVALS'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            @IPS_SetVariableCustomPresentation($vid, $payloadEncoded);
            // Debug: verify if custom presentation has been applied
            $varInfo = @IPS_GetVariable($vid);
            $post = is_array($varInfo) && array_key_exists('VariableCustomPresentation', $varInfo) ? $varInfo['VariableCustomPresentation'] : null;
            $this->SendDebug('Presentation', 'Applied ident=' . $ident . ' post=' . (is_string($post) ? $post : json_encode($post)), 0);
            // If not applied and we encoded OPTIONS, retry once with OPTIONS as array (compat mode)
            $notApplied = ($post === null || $post === '' || $post === false);
            if ($notApplied && ($hadOptionsArray || $hadIntervalsArray)) {
                $this->SendDebug('Presentation', 'Retry ident=' . $ident . ' with array fields (OPTIONS/INTERVALS) unencoded (compatibility attempt)', 0);
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
        // Translate ConstantValue inside INTERVALS when present
        if (isset($payload['INTERVALS']) && is_array($payload['INTERVALS'])) {
            foreach ($payload['INTERVALS'] as &$interval) {
                if (isset($interval['ConstantValue'])) {
                    $interval['ConstantValue'] = $this->t((string)$interval['ConstantValue']);
                }
            }
            unset($interval);
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
        $deviceID = (string)@($this->ReadPropertyString('DeviceID'));
        if ($deviceID === '') {
            return;
        }
        if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
            // Parent not active; skip automatic retries
            return;
        }
        try {
            $this->doAutoSubscribe($deviceID);
        } catch (\Throwable $e) {
            $this->logThrowable('AutoSubscribe', $e);
        }
    }

    private function ensureVariable(int $parentId, string $ident, string $name, int $type, string $profile = ''): int
    {
        // Use MaintainVariable for automatic creation/update
        $vid = $this->MaintainVariable($ident, $this->t($name), $type, $profile, 0, true);
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
        $engine = new CapabilityEngine($this->InstanceID, __DIR__);
        
        // Set translation callback so CapabilityEngine can use Symcon's Translate()
        $engine->setTranslateCallback(function($text) {
            return $this->Translate($text);
        });
        
        return $engine;
    }

    private function readStoredProfile(): array
    {
        $raw = (string)$this->ReadAttributeString('LastProfile');
        $profile = json_decode($raw, true);
        return is_array($profile) ? $profile : [];
    }
    
    /**
     * Check if status contains properties that are not in the cached profile
     * 
     * @param array<string, mixed> $status
     * @param array<string, mixed> $profile
     * @return bool
     */
    private function statusHasNewProperties(array $status, array $profile): bool
    {
        // Flatten both to compare full paths
        $statusFlat = $this->flatten($status);
        $profileFlat = $this->flatten($profile['property'] ?? $profile); // Profile has 'property' wrapper
        
        // Check for status properties that are not in profile
        foreach ($statusFlat as $statusKey => $statusValue) {
            // Skip null values
            if ($statusValue === null) {
                continue;
            }
            
            // Check if this key exists in profile
            // Profile structure: property.{resource}.{property}.type or .mode or .value
            // Status structure: {resource}.{property} = value
            
            // Look for corresponding profile entry
            $found = false;
            foreach ($profileFlat as $profileKey => $_) {
                // Match: status "timer.relativeHourToStart" with profile "property.timer.relativeHourToStart.type"
                if (strpos($profileKey, $statusKey) !== false || strpos($statusKey, str_replace('property.', '', explode('.type', $profileKey)[0])) !== false) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $this->SendDebug('statusHasNewProperties', sprintf('New property found in status: %s', $statusKey), 0);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract all top-level keys from nested array
     * 
     * @param array<string, mixed> $arr
     * @return array<string>
     */
    private function flattenKeys(array $arr): array
    {
        $keys = [];
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                foreach ($this->flattenKeysRecursive($key, $value) as $subKey) {
                    $keys[] = $subKey;
                }
            } else {
                $keys[] = $key;
            }
        }
        return array_unique($keys);
    }
    
    /**
     * Recursively extract keys
     * 
     * @param string $prefix
     * @param array<string, mixed> $arr
     * @return array<string>
     */
    private function flattenKeysRecursive(string $prefix, array $arr): array
    {
        $keys = [$prefix];
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeysRecursive($prefix . '.' . $key, $value));
            } else {
                $keys[] = $prefix . '.' . $key;
            }
        }
        return $keys;
    }
    
    /**
     * Fetch fresh profile from API
     * 
     * @return array<string, mixed>
     */
    private function fetchProfileFromAPI(): array
    {
        try {
            $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
            if ($deviceId === '') {
                return [];
            }
            
            // Send request to bridge using existing sendAction method
            $response = $this->sendAction('GetProfile', ['DeviceID' => $deviceId]);
            $data = json_decode($response, true);
            
            if (isset($data['profile']) && is_array($data['profile'])) {
                $this->SendDebug('fetchProfileFromAPI', 'Profile successfully fetched from API', 0);
                return $data['profile'];
            }
            
            return [];
        } catch (\Throwable $e) {
            $this->SendDebug('fetchProfileFromAPI', 'Failed: ' . $e->getMessage(), 0);
            return [];
        }
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

    // Gate all debug output via the module's Debug property
    protected function SendDebug($Message, $Data, $Format)
    {
        try {
            if (!(bool)$this->ReadPropertyBoolean('Debug')) {
                return;
            }
        } catch (\Throwable $e) {
            // If property is not available for any reason, default to suppressed debug
            return;
        }
        parent::SendDebug($Message, $Data, $Format);
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
