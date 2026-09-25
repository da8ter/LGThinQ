<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/CapabilityEngine.php';
require_once __DIR__ . '/libs/ThinQPresentationBuilder.php';
require_once __DIR__ . '/libs/ThinQEnergyManager.php';
require_once __DIR__ . '/libs/ThinQSupportBundle.php';
require_once __DIR__ . '/libs/ThinQDeviceProfileManager.php';
require_once __DIR__ . '/libs/ThinQDeviceUtil.php';

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
        $this->RegisterAttributeInteger('LastSelfHealTs', 0);
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
            @SetValueInteger($this->getVarId('LASTUPDATE'), ThinQClock::now());

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
        @SetValueInteger($this->getVarId('LASTUPDATE'), ThinQClock::now());

        $profile = $this->readStoredProfile();
        $type = trim((string)$this->ReadAttributeString('DeviceType'));

        // Self-heal (runs BEFORE the profile guard, so it also covers the case where the
        // cached profile/type are missing). A device with only the generic
        // INFO/STATUS/LASTUPDATE variables never got its capability variables created.
        // If the cached profile is available we rebuild from it (no API call); if it is
        // missing (the initial GetProfile failed, e.g. the parent was not ready at create
        // time) we re-run the full setup, which re-fetches the profile from the API.
        // Throttled (selfHealCooldownElapsed) so a device stuck in this state does not
        // hammer the API on every push.
        if ($this->hasOnlyGenericVariables()) {
            $this->SendDebug('ReceiveData', sprintf('self-heal check: type=%s profileEmpty=%s cooldown=%s',
                $type !== '' ? $type : '(empty)', empty($profile) ? 'yes' : 'no',
                $this->selfHealCooldownElapsed() ? 'elapsed' : 'active'), 0);
            $hasParent = !method_exists($this, 'HasActiveParent') || $this->HasActiveParent();
            if ($this->selfHealCooldownElapsed() && $hasParent && trim((string)$this->ReadPropertyString('DeviceID')) !== '') {
                $this->WriteAttributeInteger('LastSelfHealTs', ThinQClock::now());
                try {
                    if (!empty($profile) && $type !== '') {
                        $this->SendDebug('ReceiveData', 'Self-heal: recreating variables from cached profile', 0);
                        $this->ensureDeviceVariablesWithPresentations($profile, $merged, $type);
                    } else {
                        $this->SendDebug('ReceiveData', 'Self-heal: profile/type missing -> full setup (re-fetch from API)', 0);
                        $this->SetupDeviceVariables();
                    }
                } catch (\Throwable $e) {
                    $this->logThrowable('SelfHeal', $e);
                }
                // Refresh local copies for the value-apply below
                $profile = $this->readStoredProfile();
                $type = trim((string)$this->ReadAttributeString('DeviceType'));
            }
        }

        // Check if status has new properties not in profile → full setup required
        $needsSetup = false;
        if (!empty($profile) && $this->statusHasNewProperties($merged, $profile)) {
            $this->SendDebug('ReceiveData', 'Status has new properties not in cached profile, refreshing from API...', 0);
            $freshProfile = $this->fetchProfileFromAPI();
            if (!empty($freshProfile)) {
                $profile = $freshProfile;
                $this->WriteAttributeString('LastProfile', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $needsSetup = true;
            }
        }

        if ($type !== '' && !empty($profile)) {
            if ($needsSetup) {
                // New properties discovered: rebuild variables and presentations
                $this->ensureDeviceVariablesWithPresentations($profile, $merged, $type);
            } else {
                // Regular update: only apply values, no presentation work
                $engine = $this->prepareEngine();
                if ($engine !== null) {
                    $engine->applyStatus($merged);
                }
            }
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

        // Diagnostic: an empty profile here is the root cause of "device created but only
        // generic variables" — GetProfile failed/returned nothing at this moment.
        $propCount = (isset($profile['property']) && is_array($profile['property'])) ? count($profile['property']) : 0;
        $this->SendDebug('setupDevice', sprintf('profile=%s (keys=%s, property entries=%d), type=%s',
            empty($profile) ? 'EMPTY' : 'ok',
            is_array($profile) ? implode(',', array_keys($profile)) : 'n/a',
            $propCount, $type !== '' ? $type : '(empty)'), 0);

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
        // Track which variables exist before creation to apply presentations/actions only once
        $plan = [];
        $preExisting = [];
        try {
            $plan = $engine->buildPlan($type, $profile, $status);
            foreach ($plan as $ident => $_) {
                $preExisting[(string)$ident] = ((int)@IPS_GetObjectIDByIdent((string)$ident, $this->InstanceID)) > 0;
            }
            $engine->ensureVariables($profile, $status, $type);
            // Surface per-capability creation failures (caught inside ensureVariables so one
            // bad variable does not abort the rest) in the message log even without Debug.
            $failures = $engine->getCreateFailures();
            if (!empty($failures)) {
                $this->LogMessage(sprintf('%s: %s', $this->t('Variable creation failed'), implode('; ', $failures)), KL_ERROR);
            }
        } catch (\Throwable $e) {
            // Make the failure visible in the Symcon message log even without Debug,
            // and abort cleanly so presentation/action work is not attempted on a broken plan.
            $this->LogMessage(sprintf('%s (type=%s): %s', $this->t('Variable setup failed'), $type, $e->getMessage()), KL_ERROR);
            $this->logThrowable('EnsureDeviceVariables', $e);
            return;
        }
        
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

    /**
     * True when the instance has only the generic/baseline variables and none of the
     * capability-derived ones. Used to self-heal devices whose capability variables were
     * never created (e.g. the initial setup ran before the parent connection was active).
     * Mirrors the variable-detection pattern used by CleanupVariables().
     */
    private function hasOnlyGenericVariables(): bool
    {
        $generic = [
            'INFO' => true, 'STATUS' => true, 'LASTUPDATE' => true,
            'ENERGY_YESTERDAY' => true, 'ENERGY_THIS_MONTH' => true, 'ENERGY_LAST_MONTH' => true,
        ];
        foreach ((array)@IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            if (!is_array(@IPS_GetVariable((int)$cid))) {
                continue; // not a variable
            }
            $obj = @IPS_GetObject((int)$cid);
            $ident = is_array($obj) ? (string)($obj['ObjectIdent'] ?? '') : '';
            if ($ident === '') {
                continue;
            }
            if (!isset($generic[$ident])) {
                return false; // a capability variable already exists
            }
        }
        return true;
    }

    /**
     * Throttle for self-heal: caps repeated recreation/error-logging on every push when
     * capability variable creation keeps failing. 5-minute cooldown; healing on success is
     * immediate (the device leaves the only-generic state and stops re-entering this branch).
     */
    private function selfHealCooldownElapsed(): bool
    {
        return (ThinQClock::now() - (int)$this->ReadAttributeInteger('LastSelfHealTs')) >= 300;
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
        return $this->getProfileManager()->fetchDeviceProfile($deviceId);
    }

    private function resolveDeviceType(string $deviceId, array $profile): string
    {
        return $this->getProfileManager()->resolveDeviceType($deviceId, $profile);
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

    private function getProfileManager(): ThinQDeviceProfileManager
    {
        return new ThinQDeviceProfileManager(
            $this,
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(array $a, string $p = '') => $this->flatten($a, $p)
        );
    }

    private function readStoredProfile(): array
    {
        return $this->getProfileManager()->readStoredProfile();
    }

    private function statusHasNewProperties(array $status, array $profile): bool
    {
        return $this->getProfileManager()->statusHasNewProperties($status, $profile);
    }

    private function fetchProfileFromAPI(): array
    {
        return $this->getProfileManager()->fetchProfileFromAPI();
    }

    private function readLastStatus(): array
    {
        return $this->getProfileManager()->readLastStatus();
    }

    private function util(): ThinQDeviceUtil
    {
        return new ThinQDeviceUtil($this, $this->InstanceID);
    }

    private function setValueByVarType(string $ident, mixed $value): void
    {
        $this->util()->setValueByVarType($ident, $value);
    }

    private function flatten(array $data, string $prefix = ''): array
    {
        return $this->util()->flatten($data, $prefix);
    }

    private function firstNumericByPaths(array $flat, array $paths): ?float
    {
        return $this->util()->firstNumericByPaths($flat, $paths);
    }

    private function updateReferences(): void
    {
        $this->util()->updateReferences();
    }

    private function maskText(string $s): string
    {
        return $this->util()->maskText($s);
    }

    private function anonymizeArray(array $data): array
    {
        return $this->util()->anonymizeArray($data);
    }

    private function anonymizeText(string $s): string
    {
        return $this->util()->anonymizeText($s);
    }

    private function logThrowable(string $context, \Throwable $e): void
    {
        $this->util()->logThrowable($context, $e);
    }

    public function UIExportSupportBundle(): string
    {
        $bundle = new ThinQSupportBundle(
            $this,
            fn(array $a) => $this->anonymizeArray($a),
            fn(array $a, string $p = '') => $this->flatten($a, $p),
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(string $id) => $this->fetchDeviceProfile($id),
            fn() => $this->getCapabilityEngine()
        );
        return $bundle->export();
    }

    

    private function deepMerge(array $base, array $patch): array
    {
        return $this->util()->deepMerge($base, $patch);
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


}
