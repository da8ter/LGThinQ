<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/ThinQHelpers.php';
require_once __DIR__ . '/libs/ThinQConfig.php';
require_once __DIR__ . '/libs/ThinQClientId.php';
require_once __DIR__ . '/libs/ThinQRedactor.php';
require_once __DIR__ . '/libs/ThinQHttpClient.php';
require_once __DIR__ . '/libs/ThinQApi.php';
require_once __DIR__ . '/libs/ThinQSubscriptionService.php';
require_once __DIR__ . '/libs/ThinQForwardHandler.php';
require_once __DIR__ . '/libs/ThinQEventPipeline.php';
require_once __DIR__ . '/libs/ThinQMqttRouter.php';
require_once __DIR__ . '/libs/ThinQCertificateManager.php';
require_once __DIR__ . '/libs/ThinQMqttInstances.php';
require_once __DIR__ . '/libs/ThinQMqttSetupWizard.php';
require_once __DIR__ . '/libs/ThinQMqttCertBuilder.php';

class LGThinQBridge extends IPSModule
{
    use ThinQModuleTrait;

    public const API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3';
    private const CHILD_INTERFACE_GUID = '{5E9D1B64-0F44-4F21-9D74-09C5BB90FB2F}';

    private ?ThinQBridgeConfig $config = null;
    private ?ThinQHttpClient $httpClient = null;
    private ?ThinQApi $api = null;
    private ?ThinQJsonAttribute $deviceRepository = null;
    private ?ThinQSubscriptionService $subscriptions = null;
    private ?ThinQEventPipeline $eventPipeline = null;
    private ?ThinQMqttRouter $mqttRouter = null;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('AccessToken', '');
        $this->RegisterPropertyString('CountryCode', 'DE');
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterPropertyBoolean('UseMQTT', true);
        $this->RegisterPropertyInteger('MQTTClientID', 0);
        $this->RegisterPropertyString('MQTTTopicFilter', 'app/clients/{ClientID}/push');
        $this->RegisterPropertyBoolean('IgnoreRetained', true);
        $this->RegisterPropertyInteger('EventTTLHrs', 24);
        $this->RegisterPropertyInteger('EventRenewLeadMin', 5);
        // Minimum minutes between two push registrations of the same kind (unless forced)
        $this->RegisterPropertyInteger('PushCooldownMin', 30);

        $this->RegisterAttributeString('ClientID', '');
        $this->RegisterAttributeString('AccessTokenBackup', ''); // no longer written, only cleared (held a PAT copy)
        $this->RegisterAttributeString('Devices', '[]');
        $this->RegisterAttributeString('EventSubscriptions', '{}');
        // Push subscribe bookkeeping: last POST push/devices, and per device {at, clientId} of the last push/{id}/subscribe
        $this->RegisterAttributeInteger('PushRegisteredAt', 0);
        $this->RegisterAttributeString('PushDeviceSubs', '{}');
        $this->RegisterTimer('EventRenewTimer', 0, 'LGTQ_RenewEvents($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Best Practice: Avoid heavy work before KR_READY. Re-run on IPS_KERNELSTARTED
        if (!$this->isKernelReady()) {
            if (method_exists($this, 'RegisterMessage')) {
                $this->RegisterMessage(0, IPS_KERNELSTARTED);
            }
            return;
        }
        // Earlier versions kept a copy of the PAT in this attribute; the property is the only place for it.
        if ($this->ReadAttributeString('AccessTokenBackup') !== '') {
            $this->WriteAttributeString('AccessTokenBackup', '');
        }
        $this->bootServices();

        $errors = $this->config->validate();
        if (!empty($errors)) {
            $this->SetStatus(104);
            foreach ($errors as $error) {
                $this->SendDebug('Config', $error, 0);
            }
        } else {
            $this->SetStatus(102);
        }

        $this->configureTimers();

        // Extra diagnostics when Debug is enabled
        if ((bool)$this->ReadPropertyBoolean('Debug')) {
            $this->debugMqttParentInfo();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            // Kernel is ready now. Apply changes again to finish initialization
            $this->ApplyChanges();
        }
    }

    public function GetConfigurationForm(): string
    {
        $json = @file_get_contents(__DIR__ . '/form.json');
        if (!is_string($json) || $json === '') {
            return '{"elements":[],"actions":[],"status":[]}';
        }
        $form = json_decode($json, true);
        if (!is_array($form)) {
            return $json;
        }
        // Do not mutate properties during form generation; only read current values

        $propClientId = trim((string)$this->ReadPropertyString('ClientID'));
        $attrClientId = trim((string)$this->ReadAttributeString('ClientID'));
        $effectiveId = $attrClientId !== '' ? $attrClientId : $propClientId;

        if (isset($form['elements']) && is_array($form['elements'])) {
            foreach ($form['elements'] as &$el) {
                if (!is_array($el) || !isset($el['name'])) { continue; }
                if ($el['name'] === 'ClientID') {
                    $el['value'] = $effectiveId;
                } elseif ($el['name'] === 'CountryCode') {
                    $el['value'] = (string)$this->ReadPropertyString('CountryCode');
                }
            }
            unset($el);
        }

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ForwardData($JSONString)
    {
        $this->ensureBooted();
        $json = json_decode((string)$JSONString, true);
        if (!is_array($json)) {
            return json_encode(['success' => false, 'error' => 'invalid payload']);
        }
        $buffer = is_string($json['Buffer'] ?? null) ? json_decode($json['Buffer'], true) : ($json['Buffer'] ?? []);
        $buffer = is_array($buffer) ? $buffer : [];
        $this->SendDebug('ForwardData', trim((string)($buffer['Action'] ?? '') . ' ' . (string)($buffer['DeviceID'] ?? '')), 0);
        try {
            $reply = (new ThinQForwardHandler($this->api, $this->subscriptions))->handle($buffer);
        } catch (Throwable $e) {
            $this->SendDebug('ForwardData Error', $e->getMessage(), 0);
            $reply = ['success' => false, 'error' => $e->getMessage()];
        }
        return json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ReceiveData($JSONString)
    {
        $this->ensureBooted();
        $this->mqttRouter->handle((string)$JSONString);
    }

    public function TestConnection(): void
    {
        $this->ensureBooted();
        try {
            $devices = $this->api->devices();
            $count = count($devices);
            $this->NotifyUser($this->t('Connection OK. Devices') . ': ' . $count);
            $this->SetStatus(102);
            echo $this->t('Connection OK. Devices') . ': ' . $count;
        } catch (Throwable $e) {
            $this->SetStatus(104);
            $this->SendDebug('TestConnection', $e->getMessage(), 0);
            $this->NotifyUser($this->t('Connection failed') . ': ' . $e->getMessage());
            echo $this->t('Connection failed') . ': ' . $e->getMessage();
        }
    }


    public function SyncDevices(): void
    {
        $this->ensureBooted();
        try {
            $devices = $this->api->devices();
            $this->deviceRepository->replace($devices);
            $this->NotifyUser($this->t('Device list updated') . ': ' . count($devices) . ' ' . $this->t('devices') . '.');
        } catch (Throwable $e) {
            $this->SendDebug('SyncDevices', $e->getMessage(), 0);
            $this->NotifyUser($this->t('Sync failed') . ': ' . $e->getMessage());
        }
    }

    public function Update(): void
    {
        $this->ensureBooted();
        try {
            $devices = $this->api->devices();
            $this->deviceRepository->replace($devices);
        } catch (Throwable $e) {
            $this->SendDebug('Update', $e->getMessage(), 0);
        }
    }

    public function SubscribeAll(): void
    {
        $this->ensureBooted();
        try {
            $devices = $this->api->devices();
            $this->deviceRepository->replace($devices);
            $r = $this->subscriptions->subscribeAll(self::deviceIdsOf($devices));
            $this->NotifyUser(sprintf($this->t('SubscribeAll: %d/%d devices subscribed'), $r['ok'], $r['total']));
        } catch (Throwable $e) {
            $this->SendDebug('SubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser($this->t('SubscribeAll failed') . ': ' . $e->getMessage());
        }
    }

    public function UnsubscribeAll(): void
    {
        $this->ensureBooted();
        try {
            $r = $this->subscriptions->unsubscribeAll(self::deviceIdsOf($this->deviceRepository->all()));
            $this->NotifyUser(sprintf($this->t('UnsubscribeAll: %d/%d devices unsubscribed'), $r['ok'], $r['total']));
        } catch (Throwable $e) {
            $this->SendDebug('UnsubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser($this->t('UnsubscribeAll failed') . ': ' . $e->getMessage());
        }
    }

    public function RenewAll(): void
    {
        $this->ensureBooted();
        try {
            $r = $this->subscriptions->renewAll();
            $this->NotifyUser(sprintf($this->t('RenewAll: %d/%d event subscriptions renewed'), $r['ok'], $r['total']));
        } catch (Throwable $e) {
            $this->SendDebug('RenewAll', $e->getMessage(), 0);
            $this->NotifyUser($this->t('RenewAll failed') . ': ' . $e->getMessage());
        }
    }

    public function RenewEvents(): void
    {
        $this->ensureBooted();
        try {
            $this->subscriptions->tick();
        } catch (Throwable $e) {
            $this->SendDebug('RenewEvents', $e->getMessage(), 0);
        }
    }

    public function SubscribeDevice(string $DeviceID, bool $Push = true, bool $Event = true): bool
    {
        $this->ensureBooted();
        return $this->subscriptions->subscribe($DeviceID, $Push, $Event)['ok'];
    }

    public function UnsubscribeDevice(string $DeviceID, bool $Push = true, bool $Event = true): bool
    {
        $this->ensureBooted();
        return $this->subscriptions->unsubscribe($DeviceID, $Push, $Event);
    }

    /**
     * @param array<int, array<string, mixed>> $devices
     * @return array<int, string>
     */
    private static function deviceIdsOf(array $devices): array
    {
        $ids = [];
        foreach ($devices as $device) {
            $id = is_array($device) ? (string)($device['deviceId'] ?? ($device['device_id'] ?? '')) : '';
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public function GetDevices(): string
    {
        $this->ensureBooted();
        $devices = $this->api->devices();
        return json_encode($devices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function GetDeviceStatus(string $DeviceID): string
    {
        $this->ensureBooted();
        $status = $this->api->deviceStatus($DeviceID);
        return json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function GetDeviceProfile(string $DeviceID): string
    {
        $this->ensureBooted();
        $profile = $this->api->deviceProfile($DeviceID);
        return json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ControlDevice(string $DeviceID, string $JSONPayload): bool
    {
        $this->ensureBooted();
        $payload = json_decode($JSONPayload, true);
        if (!is_array($payload)) {
            throw new Exception('ControlDevice: Invalid JSON payload');
        }
        $this->api->control($DeviceID, $payload);
        return true;
    }

    private function bootServices(): void
    {
        $this->config = $this->createBridgeConfig();
        $ctx = $this->moduleContext();
        $this->deviceRepository = new ThinQJsonAttribute($ctx, 'Devices');
        $this->httpClient = new ThinQHttpClient($ctx, $this->config, self::API_KEY);
        $this->api = new ThinQApi($this->httpClient);
        $this->subscriptions = new ThinQSubscriptionService($ctx, $this->config, $this->api);
        $this->eventPipeline = new ThinQEventPipeline();
        $this->eventPipeline->onEvent(function (string $deviceId, array $payload): void {
            $this->sendToChildren('Event', $deviceId, ['Event' => $payload]);
        });
        $this->eventPipeline->onMeta(function (string $type, string $deviceId, array $payload): void {
            $this->handleMetaEvent($type, $deviceId, $payload);
        });
        $this->mqttRouter = new ThinQMqttRouter($ctx, $this->config, $this->eventPipeline);
    }

    private function createBridgeConfig(): ThinQBridgeConfig
    {
        return ThinQBridgeConfig::create(
            trim($this->ReadPropertyString('AccessToken')),
            strtoupper(trim($this->ReadPropertyString('CountryCode'))),
            $this->resolveClientId(),
            (bool)$this->ReadPropertyBoolean('Debug'),
            $this->ReadPropertyString('MQTTTopicFilter'),
            (bool)$this->ReadPropertyBoolean('IgnoreRetained'),
            (int)$this->ReadPropertyInteger('EventTTLHrs'),
            (int)$this->ReadPropertyInteger('EventRenewLeadMin')
        );
    }

    /**
     * The ClientID of the connected MQTT Client (it is the certificate CN and the topic LG publishes
     * to), else the property, else the stored attribute, else a new one. Kept in the attribute.
     */
    private function resolveClientId(): string
    {
        $clientId = ThinQClientId::ofParent($this->InstanceID);
        foreach ([$this->ReadPropertyString('ClientID'), $this->ReadAttributeString('ClientID')] as $candidate) {
            $clientId = $clientId !== '' ? $clientId : trim((string)$candidate);
        }
        $clientId = $clientId !== '' ? $clientId : ThinQClientId::generate();
        if ($clientId !== $this->ReadAttributeString('ClientID')) {
            $this->WriteAttributeString('ClientID', $clientId);
        }
        return $clientId;
    }

    private function ensureBooted(): void
    {
        if ($this->config === null || $this->subscriptions === null || $this->mqttRouter === null) {
            $this->bootServices();
        }
    }

    private function configureTimers(): void
    {
        // A short fixed check period; what is due follows from the stored expiry times (ThinQSubscriptionService).
        $errors = $this->config->validate();
        $this->SetTimerInterval('EventRenewTimer', empty($errors) ? ThinQSubscriptionService::CHECK_PERIOD * 1000 : 0);
    }

    private function debugMqttParentInfo(): void
    {
        if (!(bool)$this->ReadPropertyBoolean('Debug')) {
            return;
        }
        $parentId = ThinQMqttInstances::connectionOf($this->InstanceID);
        $ioId = $parentId > 0 ? ThinQMqttInstances::connectionOf($parentId) : 0;
        $this->SendDebug('MQTT', 'Bridge #' . $this->InstanceID . ', MQTT parent #' . $parentId . ', IO #' . $ioId, 0);
        foreach (['Parent' => $parentId, 'IO' => $ioId] as $role => $id) {
            if ($id > 0) {
                $this->SendDebug('MQTT', $role . ' ' . ThinQMqttInstances::describe($id), 0);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleMetaEvent(string $type, string $deviceId, array $payload): void
    {
        switch (strtoupper($type)) {
            case 'DEVICE_REGISTERED':
                $this->SendDebug('Push', 'Auto subscribe for ' . $deviceId, 0);
                $this->SubscribeDevice($deviceId, true, true);
                break;
            case 'DEVICE_UNREGISTERED':
                $this->SendDebug('Push', 'Auto unsubscribe for ' . $deviceId, 0);
                $this->UnsubscribeDevice($deviceId, true, true);
                break;
            case 'DEVICE_ALIAS_CHANGED':
                $this->SendDebug('Push', 'Meta event ' . $type . ' for ' . $deviceId, 0);
                break;
            case 'DEVICE_PUSH':
                // The push itself (e.g. WASHING_IS_COMPLETE) goes to the device as an action of its own ...
                $code = (string)($payload['pushCode'] ?? '');
                if ($code !== '' && $deviceId !== '') {
                    $this->sendToChildren('Push', $deviceId, ['PushCode' => $code, 'Push' => $payload]);
                }
                // ... and status data only when the push carries it nested (water heaters send temperatures this way).
                $report = self::nestedReport($payload);
                if ($report !== null && $deviceId !== '') {
                    $this->SendDebug('Push', 'DEVICE_PUSH status forward for ' . $deviceId, 0);
                    $this->eventPipeline->dispatchEvent($deviceId, $report);
                }
                break;
            default:
                $this->SendDebug('Push', 'Meta event ' . $type . ' for ' . $deviceId, 0);
                break;
        }
    }

    /** Every debug line passes the redactor, so no call site can leak the PAT or a key into the debug log. */
    protected function SendDebug($Message, $Data, $Format)
    {
        return parent::SendDebug($Message, ThinQRedactor::text((string)$Data, [trim((string)$this->ReadPropertyString('AccessToken')), self::API_KEY]), $Format);
    }

    /** @param array<string, mixed> $fields */
    private function sendToChildren(string $action, string $deviceId, array $fields): void
    {
        $this->SendDataToChildren((string)json_encode([
            'DataID' => self::CHILD_INTERFACE_GUID,
            'Buffer' => json_encode(['Action' => $action, 'DeviceID' => $deviceId] + $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Status data nested in a push: report, state, data.report, data.state or data itself.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function nestedReport(array $payload): ?array
    {
        foreach (['report', 'state'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }
        foreach (['report', 'state'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }
        return $data;
    }

    private function NotifyUser(string $message): void
    {
        $this->LogMessage($message, KL_MESSAGE);
    }

    // --- UI: Generate new MQTT Client SSL Certificates ---
    public function UIGenerateMQTTClientCerts(): string
    {
        $this->ensureBooted();
        try {
            $cn = ThinQClientId::sanitize($this->config->clientId);
            $api = new ThinQApi(new ThinQHttpClient($this->moduleContext(), $this->config->withClientId($cn), self::API_KEY));
            return 'data:application/zip;base64,' . base64_encode((new ThinQMqttCertBuilder($this->moduleContext(), $api))->build($cn));
        } catch (\Throwable $e) {
            $this->SendDebug('UIGenerateMQTTClientCerts', $e->getMessage(), 0);
            return 'data:text/plain,' . rawurlencode($this->t('Error generating certificates') . ': ' . $e->getMessage());
        }
    }
    // --- UI: One-click MQTT setup (create certs, MQTT Client, Client Socket) ---
    public function UISetupMqttConnection(): void
    {
        $this->ensureBooted();
        try {
            $result = (new ThinQMqttSetupWizard($this->moduleContext(), self::API_KEY, $this->config, $this->api,
                fn() => $this->debugMqttParentInfo()))->run();
            echo rtrim($this->t('Done'), '.') . ".\n" . 'Client Socket ID: ' . $result['ioId'] . "\n" . 'MQTT Client ID:   ' . $result['mqttId'] . "\n";
            $this->NotifyUser($this->t('MQTT connection configured') . ' (ClientID=' . $result['clientId'] . ', Host=' . $result['host'] . ':' . $result['port'] . ').');
        } catch (Throwable $e) {
            echo $this->t('Error') . ': ' . $e->getMessage();
        }
    }
}
