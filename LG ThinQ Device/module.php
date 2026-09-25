<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/CapabilityEngine.php';
require_once __DIR__ . '/libs/ThinQPresentationBuilder.php';
require_once __DIR__ . '/libs/ThinQEnergyManager.php';
require_once __DIR__ . '/libs/ThinQSupportBundle.php';
require_once __DIR__ . '/libs/ThinQShape.php';
require_once __DIR__ . '/libs/ThinQDeviceProfileManager.php';
require_once __DIR__ . '/libs/ThinQDeviceUtil.php';
require_once __DIR__ . '/libs/ThinQDeviceSetup.php';
require_once __DIR__ . '/libs/ThinQCleanup.php';

class LGThinQDevice extends IPSModule
{
    use ThinQModuleTrait;

    private const GATEWAY_MODULE_GUID = '{FCD02091-9189-0B0A-0C70-D607F1941C05}';
    private const DATA_FLOW_GUID      = '{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}';


    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Alias', '');
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterAttributeString('LastStatus', '');
        $this->RegisterAttributeString('DeviceType', '');
        $this->RegisterAttributeString('LastProfile', '');
        $this->RegisterAttributeString('EnergyProfile', '');
        $this->RegisterAttributeInteger('LastSelfHealTs', 0);
        // Timer registered at 0 (disabled); interval is set in ApplyChanges when needed
        $this->RegisterTimer('InitialUpdateStatus', 0, 'LGTQD_InitialSetup($_IPS[\'TARGET\']);');
        $this->RegisterTimer('UpdateEnergy', 0, 'LGTQD_UpdateEnergy($_IPS[\'TARGET\']);');
        $this->ConnectParent(self::GATEWAY_MODULE_GUID);
    }

    /** Variables that CleanupVariables(true) would delete, as a list with a sum. */
    public function UICleanupPreview(): string
    {
        try {
            return $this->cleanup()->preview();
        } catch (\Throwable $e) {
            $this->logThrowable('UICleanupPreview', $e);
            return 'UICleanupPreview failed: ' . $e->getMessage();
        }
    }

    /** Applies the current presentations of the plan to the existing variables again. */
    public function ReapplyPresentations(): void
    {
        try {
            $this->setup()->reapplyPresentations();
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
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
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


        // Status, profile, type, variables, energy, subscription; repeated with backoff until complete
        $this->setup()->run();
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
        // These timers should only fire once; disable after running UpdateStatus
        @$this->SetTimerInterval('InitialUpdateStatus', 0);

        try {
            $payload = $this->sendAction('GetStatus', ['DeviceID' => $deviceId]);
            $status = json_decode((string)$payload, true);
            if (!is_array($status)) {
                throw new Exception($this->t('Invalid status response'));
            }

            $status = ThinQShape::status($status);

            if ($status === []) {
                // An empty answer carries no state; keep the last known one instead of wiping it.
                $this->SendDebug('UpdateStatus', 'Empty status response, keeping the last known status', 0);
                return;
            }

            $encoded = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->WriteAttributeString('LastStatus', $encoded);

            @SetValueString($this->getVarId('STATUS'), $encoded);
            @SetValueInteger($this->getVarId('LASTUPDATE'), ThinQClock::now());

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

    /** Timer target of InitialUpdateStatus: the next attempt of an incomplete setup. */
    public function InitialSetup(): void
    {
        $this->setup()->run();
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
        
        if ($action !== 'Event' && $action !== 'Push') {
            return '';
        }

        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        $incomingDeviceId = (string)($buf['DeviceID'] ?? '');
        $this->SendDebug('ReceiveData', sprintf('My DeviceID: %s, Incoming: %s', $deviceId, $incomingDeviceId), 0);
        
        if ($deviceId === '' || strcasecmp($deviceId, $incomingDeviceId) !== 0) {
            $this->SendDebug('ReceiveData', 'DeviceID mismatch - ignoring', 0);
            return '';
        }

        if ($action === 'Push') {
            // A push notification (e.g. WASHING_IS_COMPLETE) is not device state: PUSH_LAST only.
            $code = trim((string)($buf['PushCode'] ?? ''));
            if ($code !== '' && $this->getVarId('PUSH_LAST') > 0) {
                $this->setValueByVarType('PUSH_LAST', $code);
                @SetValueInteger($this->getVarId('LASTUPDATE'), ThinQClock::now());
            }
            return '';
        }

        $event = $buf['Event'] ?? null;
        if (!is_array($event)) {
            return '';
        }

        // Zones by location, element lists by selector (a report may carry just one compartment)
        $event = ThinQShape::status($event);
        $merged = ThinQShape::merge($this->readLastStatus(), $event);
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
        if ($this->hasOnlyGenericVariables() || $profile === [] || $type === '') {
            $this->SendDebug('ReceiveData', sprintf('self-heal check: type=%s profileEmpty=%s cooldown=%s',
                $type !== '' ? $type : '(empty)', empty($profile) ? 'yes' : 'no',
                $this->selfHealCooldownElapsed() ? 'elapsed' : 'active'), 0);
            $hasParent = $this->HasActiveParent();
            if ($this->selfHealCooldownElapsed() && $hasParent && trim((string)$this->ReadPropertyString('DeviceID')) !== '') {
                $this->WriteAttributeInteger('LastSelfHealTs', ThinQClock::now());
                try {
                    if (!empty($profile) && $type !== '') {
                        $this->SendDebug('ReceiveData', 'Self-heal: recreating variables from cached profile', 0);
                        $this->ensureDeviceVariablesWithPresentations($profile, $merged, $type);
                    } else {
                        $this->SendDebug('ReceiveData', 'Self-heal: profile/type missing -> full setup (re-fetch from API)', 0);
                        $this->setup()->run();
                    }
                } catch (\Throwable $e) {
                    $this->logThrowable('SelfHeal', $e);
                }
                // Refresh local copies for the value-apply below
                $profile = $this->readStoredProfile();
                $type = trim((string)$this->ReadAttributeString('DeviceType'));
            }
        }

        // Keys of this report the profile does not list: one fresh profile; keys LG reports but never
        // lists (AC airQualitySensor) are remembered, so they do not cost a profile call per push.
        $needsSetup = false;
        $unknown = !empty($profile) ? $this->unknownReportKeys($event, $profile) : [];
        if ($unknown !== []) {
            $this->SendDebug('ReceiveData', 'Report has keys the profile does not list (' . implode(', ', $unknown) . '), refreshing it', 0);
            $freshProfile = $this->fetchProfileFromAPI();
            if (!empty($freshProfile)) {
                $profile = $freshProfile;
                $this->WriteAttributeString('LastProfile', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $needsSetup = true;
                $this->rememberUnprofiledKeys(array_keys(array_diff_key(array_flip($unknown), ThinQShape::profileKeys($profile))));
            }
        }

        if ($type !== '' && !empty($profile)) {
            $engine = $this->getCapabilityEngine();
            $engine->buildPlan($type, $profile, $merged);
            if ($needsSetup || $engine->missingStatusVariables() !== []) {
                // New properties, or variables that only appear with a status value (timer SET)
                $this->ensureDeviceVariablesWithPresentations($profile, $merged, $type);
            } else {
                $engine->applyStatus($merged);
            }
        }

        return '';
    }

    private function sendAction(string $action, array $params = []): string
    {
        if (!$this->HasActiveParent()) {
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


    /**
     * Deletes the variables that are not part of the current plan (older discovery versions).
     * @param bool $delete false only counts them
     */
    public function CleanupVariables(bool $delete = false): string
    {
        try {
            return $this->cleanup()->run($delete);
        } catch (\Throwable $e) {
            $this->logThrowable('CleanupVariables', $e);
            return 'CleanupVariables failed: ' . $e->getMessage();
        }
    }


    private function ensureDeviceVariablesWithPresentations(array $profile, array $status, string $type): void
    {
        $this->setup()->ensureVariables($profile, $status, $type);
    }

    private function hasOnlyGenericVariables(): bool
    {
        return $this->setup()->hasOnlyGenericVariables();
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
        if (!$this->HasActiveParent()) {
            // Parent not active; skip automatic retries
            return;
        }
        try {
            $this->doAutoSubscribe($deviceID);
        } catch (\Throwable $e) {
            $this->logThrowable('AutoSubscribe', $e);
        }
    }

    private function getVarId(string $ident): int
    {
        return (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    }

    private function getCapabilityEngine(): CapabilityEngine
    {
        $engine = new CapabilityEngine($this->InstanceID);
        
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
            $this->moduleContext(),
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(array $a, string $p = '') => $this->flatten($a, $p)
        );
    }

    private function readStoredProfile(): array
    {
        return $this->getProfileManager()->readStoredProfile();
    }

    /** @return array<int, string> "resource.property" keys of a report that neither the profile nor the remembered list knows */
    private function unknownReportKeys(array $report, array $profile): array
    {
        $known = ThinQShape::profileKeys($profile) + array_fill_keys($this->unprofiledKeys(), true);
        return array_keys(array_diff_key(ThinQShape::statusKeys($report), $known));
    }

    /** @return array<int, string> keys LG reports without listing them in the profile (kept until the next kernel start) */
    private function unprofiledKeys(): array
    {
        $keys = json_decode((string)$this->GetBuffer('UnprofiledKeys'), true);
        return is_array($keys) ? $keys : [];
    }

    /** @param array<int, string> $keys */
    private function rememberUnprofiledKeys(array $keys): void
    {
        if ($keys !== []) {
            $this->SetBuffer('UnprofiledKeys', (string)json_encode(array_values(array_unique(array_merge($this->unprofiledKeys(), $keys)))));
        }
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
        return new ThinQDeviceUtil($this->moduleContext());
    }

    private function setValueByVarType(string $ident, mixed $value): void
    {
        $this->util()->setValueByVarType($ident, $value);
    }

    private function flatten(array $data, string $prefix = ''): array
    {
        return ThinQDeviceUtil::flatten($data, $prefix);
    }

    private function anonymizeArray(array $data): array
    {
        return $this->util()->anonymizeArray($data);
    }

    private function logThrowable(string $context, \Throwable $e): void
    {
        $this->util()->logThrowable($context, $e);
    }

    public function UIExportSupportBundle(): string
    {
        $bundle = new ThinQSupportBundle(
            $this->moduleContext(),
            fn(array $a) => $this->anonymizeArray($a),
            fn(array $a, string $p = '') => $this->flatten($a, $p),
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(string $id) => $this->fetchDeviceProfile($id),
            fn() => $this->getCapabilityEngine()
        );
        return $bundle->export();
    }

    


    // ── Energy API ──────────────────────────────────────────────────────

    private function setup(): ThinQDeviceSetup
    {
        return new ThinQDeviceSetup($this->moduleContext(), $this->getProfileManager(), fn(): CapabilityEngine => $this->getCapabilityEngine(),
            fn(): ThinQEnergyManager => $this->energy(),
            fn(int $vid, string $ident, array $presentation, array $flatProfile, string $type) => $this->applyPresentation($vid, $ident, $presentation, $flatProfile, $type),
            fn() => $this->UpdateEnergy(), fn() => $this->UpdateStatus(),
            fn() => $this->doAutoSubscribe(trim($this->ReadPropertyString('DeviceID'))));
    }

    private function cleanup(): ThinQCleanup
    {
        return new ThinQCleanup($this->moduleContext(), $this->getProfileManager(), fn(): CapabilityEngine => $this->getCapabilityEngine());
    }

    private function energy(): ThinQEnergyManager
    {
        return new ThinQEnergyManager(
            $this->moduleContext(),
            fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(string $ident) => $this->getVarId($ident),
            fn(int $vid, string $ident, array $pres, array $fp, string $type) => $this->applyPresentation($vid, $ident, $pres, $fp, $type)
        );
    }

    public function UpdateEnergy(): void
    {
        $this->energy()->execute();
        $this->SetBuffer('EnergyFetchedAt', (string)ThinQClock::now());
    }


}
