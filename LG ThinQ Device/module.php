<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/CapabilityEngine.php';
require_once __DIR__ . '/libs/ThinQPresentationBuilder.php';
require_once __DIR__ . '/libs/ThinQEnergyManager.php';

class LGThinQDevice extends IPSModule
{
    use ThinQModuleTrait;

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
        $this->RegisterAttributeString('EnergyProfile', '');
        // Timer registered at 0 (disabled); interval is set in ApplyChanges when needed
        $this->RegisterTimer('InitialUpdateStatus', 0, 'LGTQD_InitialSetup($_IPS[\'TARGET\']);');
        $this->ConnectParent(self::GATEWAY_MODULE_GUID);
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
            $valid['ENERGY_YESTERDAY'] = true; $valid['ENERGY_THIS_MONTH'] = true; $valid['ENERGY_LAST_MONTH'] = true;

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

        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            $this->SetStatus(104);
            return;
        }
        // Configuration is complete: mark instance as Ready
        $this->SetStatus(102);

        $info = ['deviceId' => $deviceId, 'alias' => $alias];

        @SetValueString($this->getVarId('INFO'), json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));


        // One-time initial status fetch (HTTP) BEFORE creating variables so that
        // auto-discovery with create=statusHasAny can actually create variables
        $hasParent = !method_exists($this, 'HasActiveParent') || $this->HasActiveParent();
        if ($hasParent) {
            try {
                $this->UpdateStatus();
            } catch (\Throwable $e) {
                $this->logThrowable('InitialUpdateStatus', $e);
            }
        } else {
            // Parent not active yet — defer the full initial setup (status + variables)
            // Use SetTimerInterval on the pre-registered timer (reliable; avoids RegisterOnceTimer race condition)
            @$this->SetTimerInterval('InitialUpdateStatus', 5000);
        }

        // Create variables using the latest stored status (only when parent is available)
        if ($hasParent) {
            try {
                $this->SetupDeviceVariables();
            } catch (\Throwable $e) {
                $this->logThrowable('SetupDeviceVariables', $e);
            }
            // Race-condition guard: if DeviceType is still empty after setup, schedule a retry
            if (trim((string)$this->ReadAttributeString('DeviceType')) === '') {
                @$this->SetTimerInterval('InitialUpdateStatus', 10000);
            }
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

        // Kill any leftover FinalizeSetup timer from previous module versions
        if (method_exists($this, 'SetTimerInterval')) {
            @$this->SetTimerInterval('FinalizeSetup', 0);
        }

        $this->updateReferences();
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
        // Legacy method kept only to stop timers from previous module versions.
        // All setup work is now done directly in ApplyChanges().
        if (method_exists($this, 'SetTimerInterval')) {
            @$this->SetTimerInterval('FinalizeSetup', 0);
        }
    }

    /**
     * Deferred initial setup: fetches status + creates variables once the parent is active.
     * Called by the InitialUpdateStatus one-shot timer when the parent was not ready at ApplyChanges time.
     */
    public function InitialSetup(): void
    {
        if (method_exists($this, 'SetTimerInterval')) {
            @$this->SetTimerInterval('InitialUpdateStatus', 0);
        }
        try {
            $this->UpdateStatus();
        } catch (\Throwable $e) {
            $this->logThrowable('InitialSetup/UpdateStatus', $e);
        }
        try {
            $this->SetupDeviceVariables();
        } catch (\Throwable $e) {
            $this->logThrowable('InitialSetup/SetupDeviceVariables', $e);
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
        // sendAction() throws an Exception on API error — if we reach here, the call succeeded
        $this->sendAction('Control', ['DeviceID' => $deviceId, 'Payload' => $payload]);
        return true;
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
        // ConnectParent is called in Create() – do NOT call it here
        // because ConnectParent() triggers ApplyChanges() and causes an infinite loop.
    }

    private function sendAction(string $action, array $params = []): string
    {
        if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
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
            if (isset($decoded['energyProfile'])) {
                return json_encode($decoded['energyProfile']);
            }
            if (isset($decoded['energyData'])) {
                return json_encode($decoded['energyData']);
            }
        }
        return $result;
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

        // Energy API: fetch energy profile and create variables if supported
        try {
            $energyProfile = $this->fetchEnergyProfile($deviceId);
            $this->WriteAttributeString('EnergyProfile', json_encode($energyProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->setupEnergyVariables();
            $this->scheduleEnergyTimer();
        } catch (\Throwable $e) {
            $this->logThrowable('SetupEnergy', $e);
        }
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
            // Energy variables are managed outside the capability plan
            $valid['ENERGY_YESTERDAY'] = true; $valid['ENERGY_THIS_MONTH'] = true; $valid['ENERGY_LAST_MONTH'] = true;

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

            // Apply presentation on creation
            if ($justCreated && isset($entry['presentation']) && is_array($entry['presentation'])) {
                $this->applyPresentation($vid, (string)$ident, $entry['presentation'], $flatProfile, (string)($entry['type'] ?? 'STRING'));
            } elseif (isset($entry['presentation']) && is_array($entry['presentation'])) {
                // If variable pre-existed, apply presentation if not yet assigned
                $varInfo = @IPS_GetVariable($vid);
                $custom = is_array($varInfo) ? ($varInfo['VariableCustomPresentation'] ?? null) : null;
                $hasCustomPres = !empty($custom);
                $shouldUpgradeOptions = false;
                // If plan provides options but current presentation has none, we should upgrade
                $planHasOptions = isset($entry['presentation']['options']) && is_array($entry['presentation']['options']) && !empty($entry['presentation']['options']);
                if ($planHasOptions && $hasCustomPres) {
                    $customHasOptions = false;
                    if (is_array($custom)) {
                        $customHasOptions = isset($custom['OPTIONS']) && is_array($custom['OPTIONS']) && !empty($custom['OPTIONS']);
                    } elseif (is_string($custom)) {
                        $decoded = json_decode($custom, true);
                        if (is_array($decoded)) {
                            $customHasOptions = isset($decoded['OPTIONS']) && is_array($decoded['OPTIONS']) && !empty($decoded['OPTIONS']);
                        } else {
                            $customHasOptions = (strpos($custom, 'OPTIONS') !== false);
                        }
                    }
                    $shouldUpgradeOptions = !$customHasOptions;
                }
                if (!$hasCustomPres || $shouldUpgradeOptions) {
                    $this->applyPresentation($vid, (string)$ident, $entry['presentation'], $flatProfile, (string)($entry['type'] ?? 'STRING'));
                }
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
        // Ensure writeable capabilities have actions enabled even if variable pre-existed
        // Uses engine policy listIdentsToEnableOnSetup() (reassertOn: ["setup"])
        try {
            $identsToEnable = $engine->listIdentsToEnableOnSetup();
            foreach ($identsToEnable as $ident) {
                $vid = $this->getVarId((string)$ident);
                if ($vid > 0 && method_exists($this, 'EnableAction')) {
                    try {
                        $this->EnableAction((string)$ident);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logThrowable('EnableActions(Reassert)', $e);
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
            // Normalize to a profile object that preserves property + error + notification if present
            // sendAction('GetProfile') may already return the inner 'profile' JSON or wrap it in 'response'
            $profile = [];
            // Case A: wrapper contains 'response'
            if (isset($data['response']) && is_array($data['response'])) {
                $profile = $data['response'];
            } elseif (isset($data['property']) || isset($data['error']) || isset($data['notification'])) {
                // Case B: data looks like a full profile object with keys like 'property', 'error', 'notification'
                $profile = $data;
            } elseif (isset($data['profile']) && is_array($data['profile'])) {
                // Case C: wrapper contains 'profile'
                $profile = $data['profile'];
            } else {
                // Case D: legacy: treat entire payload as property content
                $profile = ['property' => $data];
            }
            // Ensure structure types are arrays
            if (!isset($profile['property']) || !is_array($profile['property'])) {
                // some devices might return 'property' wrapped in index 0
                if (isset($profile[0]) && is_array($profile[0])) {
                    $profile['property'] = $profile[0];
                }
            }
            return $profile;
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
        $builder = new ThinQPresentationBuilder(
            $this->InstanceID,
            fn(string $s) => $this->Translate($s),
            fn(string $tag, string $msg) => $this->SendDebug($tag, $msg, 0)
        );
        $builder->applyPresentation($vid, $ident, $presentation, $flatProfile, $type);
    }

    private function translatePresentationPayload(array $payload): array
    {
        $builder = new ThinQPresentationBuilder(
            $this->InstanceID,
            fn(string $s) => $this->Translate($s),
            fn(string $tag, string $msg) => $this->SendDebug($tag, $msg, 0)
        );
        return $builder->translatePresentationPayload($payload);
    }

    private function applyProfileFallback(int $vid, string $ident, array $presentation, string $type): void
    {
        $builder = new ThinQPresentationBuilder(
            $this->InstanceID,
            fn(string $s) => $this->Translate($s),
            fn(string $tag, string $msg) => $this->SendDebug($tag, $msg, 0)
        );
        $builder->applyProfileFallback($vid, $ident, $presentation, $type);
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
        return $vid;
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
        // Use Symcon's MaintainVariable for creating/updating variables
        $engine->setMaintainVariableCallback(function(string $ident, string $name, int $type, string $profile, int $position, bool $keep): int {
            // MaintainVariable returns bool in IPSModule context; always return the variable ID
            $this->MaintainVariable($ident, $name, $type, $profile, $position, $keep);
            return (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
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
                $this->SendDebug('fetchProfileFromAPI', 'Profile successfully fetched from API (profile)', 0);
                return $data['profile'];
            }
            if (isset($data['property']) && is_array($data['property'])) {
                $this->SendDebug('fetchProfileFromAPI', 'Profile successfully fetched from API (property)', 0);
                return $data['property'];
            }
            // Some bridges may already return the profile object
            if (is_array($data)) {
                $this->SendDebug('fetchProfileFromAPI', 'Profile fetched from API (raw array)', 0);
                return $data;
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
            throw new \RuntimeException('Failed to create temporary file');
        }
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to open ZIP');
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
            throw new \RuntimeException('ZIP is empty');
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

    // ── Energy API ──────────────────────────────────────────────────────

    private function fetchEnergyProfile(string $deviceId): array
    {
        $em = new ThinQEnergyManager(
            $this, $this->InstanceID,
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(string $ident) => $this->getVarId($ident),
            fn(int $vid, string $ident, array $pres, array $fp, string $type) => $this->applyPresentation($vid, $ident, $pres, $fp, $type)
        );
        return $em->fetchEnergyProfile($deviceId);
    }

    private function getEnergyProperties(): array
    {
        $em = new ThinQEnergyManager(
            $this, $this->InstanceID,
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(string $ident) => $this->getVarId($ident),
            fn(int $vid, string $ident, array $pres, array $fp, string $type) => $this->applyPresentation($vid, $ident, $pres, $fp, $type)
        );
        return $em->getEnergyProperties();
    }

    private function setupEnergyVariables(): void
    {
        $em = new ThinQEnergyManager(
            $this, $this->InstanceID,
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(string $ident) => $this->getVarId($ident),
            fn(int $vid, string $ident, array $pres, array $fp, string $type) => $this->applyPresentation($vid, $ident, $pres, $fp, $type)
        );
        $em->setupEnergyVariables();
    }

    private function scheduleEnergyTimer(): void
    {
        $em = new ThinQEnergyManager(
            $this, $this->InstanceID,
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(string $ident) => $this->getVarId($ident),
            fn(int $vid, string $ident, array $pres, array $fp, string $type) => $this->applyPresentation($vid, $ident, $pres, $fp, $type)
        );
        $em->scheduleEnergyTimer();
    }

    public function UpdateEnergy(): void
    {
        $em = new ThinQEnergyManager(
            $this, $this->InstanceID,
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(string $ident) => $this->getVarId($ident),
            fn(int $vid, string $ident, array $pres, array $fp, string $type) => $this->applyPresentation($vid, $ident, $pres, $fp, $type)
        );
        $em->execute();
    }

    private function fetchEnergyUsage(string $deviceId, string $property, string $period, string $startDate, string $endDate): ?float
    {
        $em = new ThinQEnergyManager(
            $this, $this->InstanceID,
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(string $ident) => $this->getVarId($ident),
            fn(int $vid, string $ident, array $pres, array $fp, string $type) => $this->applyPresentation($vid, $ident, $pres, $fp, $type)
        );
        return null; // Now handled by ThinQEnergyManager
    }
}
