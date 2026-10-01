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
require_once __DIR__ . '/libs/ThinQDeviceStatus.php';
require_once __DIR__ . '/libs/ThinQCleanup.php';

class LGThinQDevice extends IPSModuleStrict
{
    use ThinQModuleTrait;

    private const GATEWAY_MODULE_GUID = '{FCD02091-9189-0B0A-0C70-D607F1941C05}';
    private const DATA_FLOW_GUID      = '{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}';


    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Alias', '');
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterAttributeString('LastStatus', '');
        $this->RegisterAttributeString('DeviceType', '');
        $this->RegisterAttributeString('LastProfile', '');
        $this->RegisterAttributeString('EnergyProfile', '');
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

    /** Sets the names of the existing variables to the current names (plan, energy, generic). */
    public function ReapplyNames(): int
    {
        try {
            return $this->setup()->reapplyNames();
        } catch (\Throwable $e) {
            $this->logThrowable('ReapplyNames', $e);
            return 0;
        }
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
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
        $this->MaintainVariable('LASTUPDATE', $this->t('Last Update'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME], 30, true);

        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            $this->SetStatus(104);
            return;
        }
        // Configuration is complete: mark instance as Ready
        $this->SetStatus(102);

        $info = ['deviceId' => $deviceId, 'alias' => $alias];

        @SetValueString($this->getVarId('INFO'), json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));


        // Once the Bridge becomes active (e.g. its PAT is entered), an incomplete setup continues at once
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->watchParent();

        // Status, profile, type, variables, energy, subscription; repeated with backoff until complete
        $this->setup()->run();
    }

    /** IM_CHANGESTATUS of the current parent (the Bridge), and no longer of an earlier one. */
    private function watchParent(): void
    {
        $info = @IPS_GetInstance($this->InstanceID);
        $parent = is_array($info) ? (int)($info['ConnectionID'] ?? 0) : 0;
        $watched = (int)$this->GetBuffer('WatchedParent');
        if ($watched > 0 && $watched !== $parent) {
            $this->UnregisterMessage($watched, IM_CHANGESTATUS);
        }
        if ($parent > 0) {
            $this->RegisterMessage($parent, IM_CHANGESTATUS);
        }
        $this->SetBuffer('WatchedParent', (string)$parent);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->watchParent();
                break;
            case IM_CHANGESTATUS:
                // The Bridge became active: an incomplete setup need not wait for its next attempt
                if ((int)($Data[0] ?? 0) === IS_ACTIVE && $this->GetBuffer('SetupState') !== 'done') {
                    $this->SetTimerInterval('InitialUpdateStatus', 1000);
                }
                break;
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
            $this->deviceStatus()->update($deviceId);
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
        $this->deviceStatus()->remember($payload);
        return true;
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $deviceId = trim((string)$this->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            throw new Exception($this->t('ControlDevice: Missing DeviceID'));
        }


        $engine = $this->deviceStatus()->engine();
        if ($engine === null) {
            throw new Exception($this->t('Unknown action'));
        }

        $this->SendDebug('RequestAction', sprintf('Calling buildControlPayload for ident=%s, value=%s', $Ident, json_encode($Value)), 0);
        $payload = $engine->buildControlPayload($Ident, $Value);
        $this->SendDebug('RequestAction', sprintf('buildControlPayload returned: %s', $payload === null ? 'NULL' : 'array'), 0);
        if (!is_array($payload)) {
            throw new Exception($this->t('Unknown action') . ': ' . $Ident);
        }

        $payloadJson = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->SendDebug('RequestAction', sprintf('Ident=%s, Value=%s, Payload=%s', $Ident, json_encode($Value), $payloadJson), 0);
        
        $ok = $this->ControlDevice($payloadJson);
        if ($ok) {
            $this->setValueByVarType($Ident, $Value);
        }
    }

    public function ReceiveData(string $JSONString): string
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
            $this->deviceStatus()->push((string)($buf['PushCode'] ?? ''));
        } elseif (is_array($buf['Event'] ?? null)) {
            $this->deviceStatus()->event($buf['Event']);
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
        return ThinQDeviceUtil::bridgeResult(@$this->SendDataToParent((string)json_encode($packet)));
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


    private function fetchDeviceProfile(string $deviceId): array
    {
        return $this->getProfileManager()->fetchDeviceProfile($deviceId);
    }

    /**
     * Sets the module presentation of an existing variable via MaintainVariable, as the SDK
     * intends it (name, type and position stay those of the variable). Earlier versions wrote
     * the presentation as the user's custom presentation; such a leftover of the same presentation
     * kind is removed once, a custom presentation of another kind is the user's and stays.
     */
    private function applyPresentation(int $vid, string $ident, array $presentation, array $flatProfile, string $type): void
    {
        $builder = new ThinQPresentationBuilder(
            $this->InstanceID,
            fn(string $s) => $this->Translate($s),
            fn(string $tag, string $msg) => $this->SendDebug($tag, $msg, 0)
        );
        $payload = $builder->build($vid, $ident, $presentation, $flatProfile, $type);
        $var = @IPS_GetVariable($vid);
        $object = @IPS_GetObject($vid);
        if ($payload === [] || !is_array($var) || !is_array($object)) {
            return;
        }
        @IPS_SetVariableCustomProfile($vid, ''); // profile of a version before the presentations
        $this->MaintainVariable($ident, (string)$object['ObjectName'], (int)$var['VariableType'], $payload, (int)$object['ObjectPosition'], true);
        $custom = $var['VariableCustomPresentation'] ?? [];
        $custom = is_string($custom) ? (json_decode($custom, true) ?: []) : (array)$custom;
        if ($custom !== [] && (string)($custom['PRESENTATION'] ?? '') === (string)$payload['PRESENTATION']) {
            @IPS_SetVariableCustomPresentation($vid, []);
            $this->SendDebug('Presentation', 'ident=' . $ident . ': custom presentation of an earlier version removed', 0);
        }
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

    private function deviceStatus(): ThinQDeviceStatus
    {
        return new ThinQDeviceStatus($this->moduleContext(), $this->getProfileManager(), fn(string $a, array $p = []) => $this->sendAction($a, $p),
            fn(): CapabilityEngine => $this->getCapabilityEngine(), fn(): ThinQDeviceSetup => $this->setup());
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
