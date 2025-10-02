<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/ThinQHelpers.php';
require_once __DIR__ . '/libs/ThinQConfig.php';
require_once __DIR__ . '/libs/ThinQDeviceRepository.php';
require_once __DIR__ . '/libs/ThinQEventSubscriptionRepository.php';
require_once __DIR__ . '/libs/ThinQHttpClient.php';
require_once __DIR__ . '/libs/ThinQEventManager.php';
require_once __DIR__ . '/libs/ThinQEventPipeline.php';
require_once __DIR__ . '/libs/ThinQMqttRouter.php';

class LGThinQBridge extends IPSModule
{
    public const API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3';
    private const DATA_FLOW_GUID = '{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}';
    private const CHILD_INTERFACE_GUID = '{5E9D1B64-0F44-4F21-9D74-09C5BB90FB2F}';
    private const MQTT_MODULE_GUID = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';

    private ?ThinQBridgeConfig $config = null;
    private ?ThinQHttpClient $httpClient = null;
    private ?ThinQDeviceRepository $deviceRepository = null;
    private ?ThinQEventSubscriptionRepository $subscriptionRepository = null;
    private ?ThinQEventManager $eventManager = null;
    private ?ThinQEventPipeline $eventPipeline = null;
    private ?ThinQMqttRouter $mqttRouter = null;

    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }

    private function isKernelReady(): bool
    {
        return function_exists('IPS_GetKernelRunlevel') ? (IPS_GetKernelRunlevel() === KR_READY) : true;
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
        // Reduce push subscribe requests: cooldown in minutes
        $this->RegisterPropertyInteger('PushCooldownMin', 30);

        $this->RegisterAttributeString('ClientID', '');
        $this->RegisterAttributeString('AccessTokenBackup', '');
        $this->RegisterAttributeString('Devices', '[]');
        $this->RegisterAttributeString('EventSubscriptions', '{}');
        // Push subscribe throttling metadata
        $this->RegisterAttributeInteger('PushRegisteredAt', 0);
        $this->RegisterAttributeString('PushDeviceSubs', '{}');
        $this->RegisterTimer('EventRenewTimer', 0, 'LGTQ_RenewEvents($_IPS[\'TARGET\']);');
        // Initialize default ClientID on first installation so it appears in the form
        $propCID = trim((string)$this->ReadPropertyString('ClientID'));
        $attrCID = trim((string)$this->ReadAttributeString('ClientID'));
        if ($propCID === '' && $attrCID === '') {
            try {
                $rand5 = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            } catch (\Throwable $e) {
                $rand5 = str_pad((string)mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
            }
            $cid = 'Symcon' . $rand5;
            $this->WriteAttributeString('ClientID', $cid);
            $okProp = IPS_SetProperty($this->InstanceID, 'ClientID', $cid);
            if (!$okProp) {
                $this->SendDebug('Create', 'Failed to set default ClientID property', 0);
            }
            // (removed) Avoid ApplyChanges during Create to prevent state mutation loops
            // Also set a concrete default MQTT topic filter using the generated ClientID
            $curFilter = trim((string)$this->ReadPropertyString('MQTTTopicFilter'));
            if ($curFilter === '' || $curFilter === 'app/clients/{ClientID}/push' || $curFilter === 'app/clients/*/#' || $curFilter === 'app/clients/*/push') {
                $okProp2 = IPS_SetProperty($this->InstanceID, 'MQTTTopicFilter', 'app/clients/' . $cid . '/push');
                if (!$okProp2) { $this->SendDebug('Create', 'Failed to set default MQTTTopicFilter', 0); }
                // (removed) Avoid ApplyChanges during Create to prevent state mutation loops
            }
        }
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
        // Ensure PAT survives module reloads (restore from backup if property got cleared)
        $this->ensureAccessTokenPersistence();
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
        $effectiveId = $propClientId !== '' ? $propClientId : $attrClientId;
        // For display only: if nothing set, show a generated default (do not persist here)
        if ($effectiveId === '') {
            try {
                $rand5 = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            } catch (\Throwable $e) {
                $rand5 = str_pad((string)mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
            }
            $effectiveId = 'Symcon' . $rand5;
        }

        if (isset($form['elements']) && is_array($form['elements'])) {
            foreach ($form['elements'] as &$el) {
                if (!is_array($el) || !isset($el['name'])) { continue; }
                if ($el['name'] === 'ClientID') {
                    $el['value'] = $effectiveId;
                } elseif ($el['name'] === 'AccessToken') {
                    // Keep PAT visible after module reload
                    $el['value'] = (string)$this->ReadPropertyString('AccessToken');
                } elseif ($el['name'] === 'CountryCode') {
                    $el['value'] = (string)$this->ReadPropertyString('CountryCode');
                }
            }
            unset($el);
        }

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Restore AccessToken (PAT) from attribute backup if the property is empty (e.g., after module reload),
     * and keep the attribute in sync when the property is set.
     */
    private function ensureAccessTokenPersistence(): void
    {
        try {
            $prop = (string)@($this->ReadPropertyString('AccessToken'));
            $attr = (string)@($this->ReadAttributeString('AccessTokenBackup'));
            // Only keep backup attribute in sync; do not modify properties here
            if ($prop !== '' && $attr !== $prop) {
                $this->WriteAttributeString('AccessTokenBackup', $prop);
            }
        } catch (\Throwable $e) {
            // Do not log secrets; just ignore
        }
    }

    public function ForwardData($JSONString)
    {
        $this->ensureBooted();
        $this->SendDebug('ForwardData', (string)$JSONString, 0);
        $json = json_decode((string)$JSONString, true);
        if (!is_array($json)) {
            return json_encode(['success' => false, 'error' => 'invalid payload']);
        }
        $buffer = $json['Buffer'] ?? [];
        if (is_string($buffer)) {
            $buffer = json_decode($buffer, true);
        }
        if (!is_array($buffer)) {
            $buffer = [];
        }

        $action = (string)($buffer['Action'] ?? '');
        try {
            switch ($action) {
                case 'GetDevices':
                    $devices = $this->fetchDevices();
                    return json_encode(['success' => true, 'devices' => $devices], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                case 'GetStatus':
                    $deviceId = (string)($buffer['DeviceID'] ?? '');
                    if ($deviceId === '') {
                        throw new Exception('DeviceID missing');
                    }
                    $status = $this->fetchDeviceStatus($deviceId);
                    return json_encode(['success' => true, 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                case 'GetProfile':
                    $deviceId = (string)($buffer['DeviceID'] ?? '');
                    if ($deviceId === '') {
                        throw new Exception('DeviceID missing');
                    }
                    $profile = $this->httpClient->request('GET', 'devices/' . rawurlencode($deviceId) . '/profile');
                    return json_encode(['success' => true, 'profile' => $profile], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                case 'Control':
                    $deviceId = (string)($buffer['DeviceID'] ?? '');
                    $payload = $buffer['Payload'] ?? null;
                    if ($deviceId === '' || !is_array($payload)) {
                        throw new Exception('Control payload invalid');
                    }
                    $response = $this->httpClient->request('POST', 'devices/' . rawurlencode($deviceId) . '/control', $payload, ['x-conditional-control: false']);
                    return json_encode(['success' => true, 'response' => $response], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                case 'SubscribeDevice':
                    $deviceId = (string)($buffer['DeviceID'] ?? '');
                    $withPush = (bool)($buffer['Push'] ?? true);
                    $withEvent = (bool)($buffer['Event'] ?? true);
                    if ($deviceId === '') {
                        throw new Exception('DeviceID missing');
                    }
                    $res = $this->trySubscribeDevice($deviceId, $withPush, $withEvent);
                    $payload = ['success' => $res['ok']];
                    if (!$res['ok'] && !empty($res['errors'])) {
                        $payload['error'] = implode('; ', $res['errors']);
                    }
                    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                case 'UnsubscribeDevice':
                    $deviceId = (string)($buffer['DeviceID'] ?? '');
                    $fromPush = (bool)($buffer['Push'] ?? true);
                    $fromEvent = (bool)($buffer['Event'] ?? true);
                    if ($deviceId === '') {
                        throw new Exception('DeviceID missing');
                    }
                    $ok = $this->UnsubscribeDevice($deviceId, $fromPush, $fromEvent);
                    return json_encode(['success' => $ok], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                case 'RenewEventForDevice':
                    $deviceId = (string)($buffer['DeviceID'] ?? '');
                    if ($deviceId === '') {
                        throw new Exception('DeviceID missing');
                    }
                    $ok = $this->eventManager->subscribe($deviceId);
                    return json_encode(['success' => $ok], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                default:
                    return json_encode(['success' => false, 'error' => 'unknown action']);
            }
        } catch (Throwable $e) {
            $this->SendDebug('ForwardData Error', $e->getMessage(), 0);
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
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
            $devices = $this->fetchDevices();
            $count = count($devices);
            $this->NotifyUser($this->t('Verbindung OK. Geräte') . ': ' . $count);
            $this->SetStatus(102);
            echo $this->t('Verbindung OK. Geräte') . ': ' . $count;
        } catch (Throwable $e) {
            $this->SetStatus(104);
            $this->SendDebug('TestConnection', $e->getMessage(), 0);
            $this->NotifyUser($this->t('Verbindung fehlgeschlagen') . ': ' . $e->getMessage());
            echo $this->t('Verbindung fehlgeschlagen') . ': ' . $e->getMessage();
        }
    }


    public function SyncDevices(): void
    {
        $this->ensureBooted();
        try {
            $devices = $this->fetchDevices();
            $this->deviceRepository->saveAll($devices);
            $this->NotifyUser($this->t('Geräteliste aktualisiert') . ': ' . count($devices) . ' ' . $this->t('Geräte') . '.');
        } catch (Throwable $e) {
            $this->SendDebug('SyncDevices', $e->getMessage(), 0);
            $this->NotifyUser($this->t('Sync fehlgeschlagen') . ': ' . $e->getMessage());
        }
    }

    public function Update(): void
    {
        $this->ensureBooted();
        try {
            $devices = $this->fetchDevices();
            $this->deviceRepository->saveAll($devices);
        } catch (Throwable $e) {
            $this->SendDebug('Update', $e->getMessage(), 0);
        }
    }

    public function SubscribeAll(): void
    {
        $this->ensureBooted();
        try {
            $devices = $this->fetchDevices();
            $this->deviceRepository->saveAll($devices);
            $ok = 0;
            $total = 0;
            foreach ($devices as $device) {
                $deviceId = (string)($device['deviceId'] ?? ($device['device_id'] ?? ''));
                if ($deviceId === '') {
                    continue;
                }
                $total++;
                if ($this->SubscribeDevice($deviceId, true, true)) {
                    $ok++;
                }
            }

            try {
                $this->httpClient->request('POST', 'push/devices');
            } catch (Throwable $e) {
                $this->SendDebug('SubscribeAll Push', $e->getMessage(), 0);
            }

            $this->NotifyUser(sprintf($this->t('SubscribeAll: %d/%d Geräte abonniert'), $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('SubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser($this->t('SubscribeAll fehlgeschlagen') . ': ' . $e->getMessage());
        }
    }

    public function UnsubscribeAll(): void
    {
        $this->ensureBooted();
        try {
            $ids = [];
            foreach ($this->subscriptionRepository->getAll() as $deviceId => $_) {
                if ($deviceId !== '') {
                    $ids[$deviceId] = true;
                }
            }
            foreach ($this->deviceRepository->getAll() as $device) {
                $deviceId = (string)($device['deviceId'] ?? ($device['device_id'] ?? ''));
                if ($deviceId !== '') {
                    $ids[$deviceId] = true;
                }
            }
            $ok = 0;
            $total = count($ids);
            foreach (array_keys($ids) as $deviceId) {
                if ($this->UnsubscribeDevice((string)$deviceId, true, true)) {
                    $ok++;
                }
            }
            try {
                $this->httpClient->request('DELETE', 'push/devices');
            } catch (Throwable $e) {
                $this->SendDebug('UnsubscribeAll Push', $e->getMessage(), 0);
            }
            $this->subscriptionRepository->saveAll([]);
            $this->NotifyUser(sprintf($this->t('UnsubscribeAll: %d/%d Geräte abgemeldet'), $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('UnsubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser($this->t('UnsubscribeAll fehlgeschlagen') . ': ' . $e->getMessage());
        }
    }

    public function RenewAll(): void
    {
        $this->ensureBooted();
        try {
            $subs = $this->subscriptionRepository->getAll();
            $ok = 0;
            $total = count($subs);
            foreach (array_keys($subs) as $deviceId) {
                if ($deviceId === '') {
                    continue;
                }
                if ($this->eventManager->subscribe((string)$deviceId)) {
                    $ok++;
                }
            }
            $this->NotifyUser(sprintf($this->t('RenewAll: %d/%d Event-Abos erneuert'), $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('RenewAll', $e->getMessage(), 0);
            $this->NotifyUser($this->t('RenewAll fehlgeschlagen') . ': ' . $e->getMessage());
        }
    }

    public function RenewEvents(): void
    {
        $this->ensureBooted();
        try {
            $this->eventManager->renewExpiring();
        } catch (Throwable $e) {
            $this->SendDebug('RenewEvents', $e->getMessage(), 0);
        }
    }

    public function SubscribeDevice(string $DeviceID, bool $Push = true, bool $Event = true): bool
    {
        $this->ensureBooted();
        $res = $this->trySubscribeDevice($DeviceID, $Push, $Event);
        return $res['ok'];
    }

    /**
     * @return array{ok: bool, errors: array<int, string>}
     */
    private function trySubscribeDevice(string $DeviceID, bool $Push, bool $Event): array
    {
        $ok = true;
        $errors = [];
        if ($Event) {
            $success = $this->eventManager->subscribe($DeviceID);
            if ($success) {
                $this->SendDebug('Event Subscribe', 'OK for ' . $DeviceID, 0);
            } else {
                $this->SendDebug('Event Subscribe', 'FAILED for ' . $DeviceID, 0);
            }
            if (!$success) {
                $errors[] = 'Event subscription failed';
            }
            $ok = $success && $ok;
        }
        if ($Push) {
            try {
                // Ensure this client is registered as push recipient (idempotent)
                try {
                    $this->httpClient->request('POST', 'push/devices');
                    $this->SendDebug('Push Subscribe', 'push/devices OK', 0);
                } catch (Throwable $e2) {
                    $this->SendDebug('Push Subscribe', 'push/devices error: ' . $e2->getMessage(), 0);
                }
                $this->httpClient->request('POST', 'push/' . rawurlencode($DeviceID) . '/subscribe');
                $this->SendDebug('Push Subscribe', 'OK for ' . $DeviceID, 0);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'already subscribed') !== false) {
                    // Idempotent: consider already subscribed as success
                    $this->SendDebug('Push Subscribe', 'Already subscribed: treating as OK', 0);
                } else {
                    $this->SendDebug('Push Subscribe', $msg, 0);
                    $errors[] = 'Push subscribe failed: ' . $msg;
                    $ok = false;
                }
            }
        }
        return ['ok' => $ok, 'errors' => $errors];
    }

    public function UnsubscribeDevice(string $DeviceID, bool $Push = true, bool $Event = true): bool
    {
        $this->ensureBooted();
        $ok = true;
        if ($Event) {
            $ok = $this->eventManager->unsubscribe($DeviceID) && $ok;
        }
        if ($Push) {
            try {
                $this->httpClient->request('DELETE', 'push/' . rawurlencode($DeviceID) . '/unsubscribe');
            } catch (Throwable $e) {
                $this->SendDebug('Push Unsubscribe', $e->getMessage(), 0);
                $ok = false;
            }
        }
        return $ok;
    }

    public function GetDevices(): string
    {
        $this->ensureBooted();
        $devices = $this->fetchDevices();
        return json_encode($devices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function GetDeviceStatus(string $DeviceID): string
    {
        $this->ensureBooted();
        $status = $this->fetchDeviceStatus($DeviceID);
        return json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function GetDeviceProfile(string $DeviceID): string
    {
        $this->ensureBooted();
        $profile = $this->httpClient->request('GET', 'devices/' . rawurlencode($DeviceID) . '/profile');
        return json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ControlDevice(string $DeviceID, string $JSONPayload): bool
    {
        $this->ensureBooted();
        $payload = json_decode($JSONPayload, true);
        if (!is_array($payload)) {
            throw new Exception('ControlDevice: ungültiges JSON');
        }
        $this->httpClient->request('POST', 'devices/' . rawurlencode($DeviceID) . '/control', $payload, ['x-conditional-control: false']);
        return true;
    }

    private function bootServices(): void
    {
        $this->config = $this->createBridgeConfig();
        $this->deviceRepository = new ThinQDeviceRepository($this);
        $this->subscriptionRepository = new ThinQEventSubscriptionRepository($this);
        $this->httpClient = new ThinQHttpClient($this, $this->config, self::API_KEY);
        $this->eventManager = new ThinQEventManager($this, $this->config, $this->httpClient, $this->subscriptionRepository);
        $this->eventPipeline = new ThinQEventPipeline();
        $this->eventPipeline->onEvent(function (string $deviceId, array $payload): void {
            $this->SendDataToChildren(json_encode([
                'DataID' => self::CHILD_INTERFACE_GUID,
                'Buffer' => json_encode([
                    'Action' => 'Event',
                    'DeviceID' => $deviceId,
                    'Event' => $payload
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        });
        $this->eventPipeline->onMeta(function (string $type, string $deviceId, array $payload): void {
            $this->handleMetaEvent($type, $deviceId, $payload);
        });
        $this->mqttRouter = new ThinQMqttRouter($this, $this->config, $this->eventPipeline);
    }

    private function createBridgeConfig(): ThinQBridgeConfig
    {
        $accessToken = trim($this->ReadPropertyString('AccessToken'));
        $countryCode = strtoupper(trim($this->ReadPropertyString('CountryCode')));
        $debug = (bool)$this->ReadPropertyBoolean('Debug');
        $useMqtt = (bool)$this->ReadPropertyBoolean('UseMQTT');
        $mqttClientId = (int)$this->ReadPropertyInteger('MQTTClientID');
        $mqttTopicFilter = $this->ReadPropertyString('MQTTTopicFilter');
        $ignoreRetained = (bool)$this->ReadPropertyBoolean('IgnoreRetained');
        $eventTtlHours = (int)$this->ReadPropertyInteger('EventTTLHrs');
        $eventRenewLeadMin = (int)$this->ReadPropertyInteger('EventRenewLeadMin');

        $clientIdProperty = trim($this->ReadPropertyString('ClientID'));
        $clientIdAttr = trim($this->ReadAttributeString('ClientID'));
        $clientId = $clientIdProperty !== '' ? $clientIdProperty : $clientIdAttr;
        if ($clientId === '') {
            // First installation: generate 'Symcon' + 5-digit random number
            try {
                $rand5 = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            } catch (\Throwable $e) {
                // Fallback for environments without random_int
                $rand5 = str_pad((string)mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
            }
            $clientId = 'Symcon' . $rand5;
            $this->WriteAttributeString('ClientID', $clientId);
        } elseif ($clientIdProperty !== '' && $clientIdProperty !== $clientIdAttr) {
            $this->WriteAttributeString('ClientID', $clientIdProperty);
            $clientId = $clientIdProperty;
        }

        // Prefer MQTT parent ClientID to keep HTTP x-client-id aligned with certificate CN
        $instInfo = @IPS_GetInstance($this->InstanceID);
        $parentId = is_array($instInfo) ? (int)($instInfo['ConnectionID'] ?? 0) : 0;
        if ($parentId > 0) {
            $parentClientId = trim((string)IPS_GetProperty($parentId, 'ClientID'));
            if ($parentClientId !== '') {
                if ($clientId !== $parentClientId) {
                    // Reflect parent ClientID via attribute only; avoid mutating properties here
                    $this->WriteAttributeString('ClientID', $parentClientId);
                    $clientId = $parentClientId;
                }
            }
        }

        return ThinQBridgeConfig::create(
            $accessToken,
            $countryCode,
            $clientId,
            $debug,
            $useMqtt,
            $mqttClientId,
            $mqttTopicFilter,
            $ignoreRetained,
            $eventTtlHours,
            $eventRenewLeadMin
        );
    }

    private function ensureBooted(): void
    {
        if ($this->config === null || $this->httpClient === null || $this->eventManager === null || $this->mqttRouter === null) {
            $this->bootServices();
        }
    }

    private function configureTimers(): void
    {
        $ttl = $this->config->normalizedEventTtlHours();
        $lead = $this->config->normalizedEventRenewLeadMinutes();
        $interval = max(60, $ttl * 3600 - $lead * 60);
        $errors = $this->config->validate();
        $this->SetTimerInterval('EventRenewTimer', empty($errors) ? $interval * 1000 : 0);
    }

    private function ensureMqttParent(): void
    {
        if (!(bool)$this->ReadPropertyBoolean('UseMQTT')) {
            return;
        }
        $inst = @IPS_GetInstance($this->InstanceID);
        $parentId = is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
        if ($parentId > 0) {
            return;
        }
        if (method_exists($this, 'ConnectParent')) {
            $this->ConnectParent(self::MQTT_MODULE_GUID);
        }
    }

    private function debugMqttParentInfo(): void
    {
        if (!(bool)$this->ReadPropertyBoolean('Debug')) {
            return;
        }
        $instInfo = @IPS_GetInstance($this->InstanceID);
        $parentId = is_array($instInfo) ? (int)($instInfo['ConnectionID'] ?? 0) : 0;
        $this->SendDebug('MQTT', 'Bridge InstanceID=' . $this->InstanceID . ' ParentID=' . $parentId, 0);
        if ($parentId > 0) {
            $parentInfo = @IPS_GetInstance($parentId);
            $this->SendDebug('MQTT', 'Parent ModuleID=' . ($parentInfo['ModuleID'] ?? ''), 0);
            $this->SendDebug('MQTT', 'Parent Status=' . ($parentInfo['InstanceStatus'] ?? ''), 0);
            $parentCfgJson = @IPS_GetConfiguration($parentId);
            if (is_string($parentCfgJson) && $parentCfgJson !== '') {
                $this->SendDebug('MQTT', 'Parent Config=' . $parentCfgJson, 0);
            }
            $ioId = (int)($parentInfo['ConnectionID'] ?? 0);
            $this->SendDebug('MQTT', 'IO (Client Socket) ID=' . $ioId, 0);
            if ($ioId > 0) {
                $ioInfo = @IPS_GetInstance($ioId);
                $this->SendDebug('MQTT', 'IO ModuleID=' . ($ioInfo['ModuleID'] ?? ''), 0);
                $this->SendDebug('MQTT', 'IO Status=' . ($ioInfo['InstanceStatus'] ?? ''), 0);
                $ioCfgJson = @IPS_GetConfiguration($ioId);
                if (is_string($ioCfgJson) && $ioCfgJson !== '') {
                    $this->SendDebug('MQTT', 'IO Config=' . $ioCfgJson, 0);
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDevices(): array
    {
        $data = $this->httpClient->request('GET', 'devices');
        if (isset($data['devices']) && is_array($data['devices'])) {
            return $data['devices'];
        }
        if (empty($data)) {
            return [];
        }
        if (isset($data[0])) {
            return $data;
        }
        return [$data];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDeviceStatus(string $deviceId): array
    {
        return $this->httpClient->request('GET', 'devices/' . rawurlencode($deviceId) . '/state');
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
            case 'DEVICE_PUSH':
            default:
                $this->SendDebug('Push', 'Meta event ' . $type . ' for ' . $deviceId, 0);
                break;
        }
    }

    public function DebugLog(string $tag, string $message): void
    {
        // Wrapper to allow helper classes to log debug output via module context
        $this->SendDebug($tag, $message, 0);
    }

    private function NotifyUser(string $message): void
    {
        $this->LogMessage($message, KL_MESSAGE);
    }

    // --- Public wrappers for repositories (avoid protected method access) ---
    /**
     * @return array<int, array<string, mixed>>
     */
    public function GetDevicesCache(): array
    {
        $raw = (string)$this->ReadAttributeString('Devices');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int, array<string, mixed>> $devices
     */
    public function SaveDevicesCache(array $devices): void
    {
        $this->WriteAttributeString('Devices', json_encode($devices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function GetEventSubscriptionsCache(): array
    {
        $raw = (string)$this->ReadAttributeString('EventSubscriptions');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array<string, mixed>> $subs
     */
    public function SaveEventSubscriptionsCache(array $subs): void
    {
        $this->WriteAttributeString('EventSubscriptions', json_encode($subs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // --- UI: Generate new MQTT Client SSL Certificates ---
    public function UIGenerateMQTTClientCerts(): string
    {
        try {
            $zipData = $this->buildMQTTClientCertsZip();
            return 'data:application/zip;base64,' . base64_encode($zipData);
        } catch (\Throwable $e) {
            $this->SendDebug('UIGenerateMQTTClientCerts', $e->getMessage(), 0);
            return 'data:text/plain,' . rawurlencode($this->t('Error generating certificates') . ': ' . $e->getMessage());
        }
    }

    private function buildMQTTClientCertsZip(): string
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException($this->t('OpenSSL is not supported (openssl_* functions missing)'));
        }

        $clientId = trim((string)$this->ReadAttributeString('ClientID'));
        if ($clientId === '') {
            // fallback: try property or generate
            $clientId = trim((string)$this->ReadPropertyString('ClientID'));
            if ($clientId === '') {
                $clientId = 'client-' . (string)$this->InstanceID;
            }
        }
        // Subject CN: prefer MQTT ClientID from parent if available
        $subjectCN = $clientId;
        $instInfo = @IPS_GetInstance($this->InstanceID);
        if (is_array($instInfo)) {
            $parentId = (int)($instInfo['ConnectionID'] ?? 0);
            if ($parentId > 0) {
                $parentClientId = trim((string)@IPS_GetProperty($parentId, 'ClientID'));
                if ($parentClientId !== '') {
                    $subjectCN = $parentClientId;
                }
            }
        }
        // Sanitize CN (no spaces or special chars)
        $subjectCN = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$subjectCN);
        if (!is_string($subjectCN) || trim($subjectCN) === '') {
            $subjectCN = 'client-' . (string)$this->InstanceID;
        }
        if ((bool)$this->ReadPropertyBoolean('Debug')) {
            $this->SendDebug('CertGen', 'Effective CN=' . $subjectCN . ' (Bridge ClientID=' . $clientId . ')', 0);
            $this->debugMqttParentInfo();
        }

        $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lgtq_mqtt_' . $this->InstanceID . '_' . bin2hex(random_bytes(4)) . '.cnf';
        $nl = "\r\n";
        $strCONFIG  = 'default_md = sha256' . $nl;
        $strCONFIG .= 'default_days = 3650' . $nl;
        $strCONFIG .= $nl;
        $strCONFIG .= '[ req ]' . $nl;
        $strCONFIG .= 'default_bits = 2048' . $nl;
        $strCONFIG .= 'distinguished_name = req_DN' . $nl;
        $strCONFIG .= 'string_mask = nombstr' . $nl;
        $strCONFIG .= 'prompt = no' . $nl;
        $strCONFIG .= 'req_extensions = v3_req' . $nl;
        $strCONFIG .= 'x509_extensions = v3_client' . $nl;
        $strCONFIG .= $nl;
        $strCONFIG .= '[ req_DN ]' . $nl;
        $strCONFIG .= 'commonName = "' . addslashes($subjectCN) . '"' . $nl;
        $strCONFIG .= $nl;
        $strCONFIG .= '[ v3_req ]' . $nl;
        $strCONFIG .= 'basicConstraints = critical, CA:FALSE' . $nl;
        $strCONFIG .= 'keyUsage = critical, digitalSignature' . $nl;
        $strCONFIG .= 'extendedKeyUsage = clientAuth' . $nl;
        $strCONFIG .= $nl;
        $strCONFIG .= '[ v3_client ]' . $nl;
        $strCONFIG .= 'basicConstraints = critical, CA:FALSE' . $nl;
        $strCONFIG .= 'keyUsage = critical, digitalSignature' . $nl;
        $strCONFIG .= 'extendedKeyUsage = clientAuth' . $nl;
        $strCONFIG .= 'subjectKeyIdentifier = hash' . $nl;
        $strCONFIG .= 'authorityKeyIdentifier = keyid' . $nl;
        $cfgHandle = fopen($cfgPath, 'w');
        if ($cfgHandle === false) {
            throw new \RuntimeException($this->t('Failed to create temporary OpenSSL configuration'));
        }
        fwrite($cfgHandle, $strCONFIG);
        fclose($cfgHandle);
        if ((bool)$this->ReadPropertyBoolean('Debug')) {
            $this->SendDebug('CertGen', 'OpenSSL cfg written: ' . $cfgPath, 0);
        }
        $dn = [
            'commonName'       => $subjectCN
        ];
        $config = [
            'config' => $cfgPath,
            'digest_alg' => 'sha256'
        ];
        // Prefer EC P-256 key, fallback to RSA 2048
        $configKey = [
            'config'           => $cfgPath,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1'
        ];

        $pkGenerate = @openssl_pkey_new($configKey);
        if ($pkGenerate === false) {
            $configKey = [
                'config'           => $cfgPath,
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA
            ];
            $pkGenerate = openssl_pkey_new($configKey);
        }
        if ($pkGenerate === false) {
            throw new \RuntimeException($this->t('openssl_pkey_new failed'));
        }

        $pkPrivate = '';
        if (!openssl_pkey_export($pkGenerate, $pkPrivate, null, $config)) { // unverschlüsselt
            throw new \RuntimeException($this->t('openssl_pkey_export failed'));
        }
        $pkDetails = openssl_pkey_get_details($pkGenerate);
        if ($pkDetails === false || !isset($pkDetails['key'])) {
            throw new \RuntimeException($this->t('openssl_pkey_get_details failed'));
        }
        $pkPublic = (string)$pkDetails['key'];
        if ((bool)$this->ReadPropertyBoolean('Debug')) {
            $typeStr = (($pkDetails['type'] ?? null) === OPENSSL_KEYTYPE_EC) ? 'EC' : 'RSA';
            $bits = (int)($pkDetails['bits'] ?? 0);
            $this->SendDebug('CertGen', 'Key generated: ' . $typeStr . ' bits=' . $bits, 0);
            $this->SendDebug('CertGen', 'Key PEM lengths: private=' . strlen($pkPrivate) . ' public=' . strlen($pkPublic), 0);
        }

        $csr = openssl_csr_new($dn, $pkGenerate, $config);
        if ($csr === false) {
            // Retry without req_extensions to support OpenSSL builds that can't load custom req sections
            if (method_exists($this, 'SendDebug')) {
                $this->SendDebug('CertGen', 'CSR with v3_req failed; retrying without req_extensions', 0);
            }
            $strCONFIG2  = 'default_md = sha256' . $nl;
            $strCONFIG2 .= 'default_days = 3650' . $nl;
            $strCONFIG2 .= $nl;
            $strCONFIG2 .= '[ req ]' . $nl;
            $strCONFIG2 .= 'default_bits = 2048' . $nl;
            $strCONFIG2 .= 'distinguished_name = req_DN' . $nl;
            $strCONFIG2 .= 'string_mask = nombstr' . $nl;
            $strCONFIG2 .= 'prompt = no' . $nl;
            $strCONFIG2 .= 'x509_extensions = v3_client' . $nl;
            $strCONFIG2 .= $nl;
            $strCONFIG2 .= '[ req_DN ]' . $nl;
            $strCONFIG2 .= 'commonName = "' . addslashes($subjectCN) . '"' . $nl;
            $strCONFIG2 .= $nl;
            $strCONFIG2 .= '[ v3_client ]' . $nl;
            $strCONFIG2 .= 'basicConstraints = critical, CA:FALSE' . $nl;
            $strCONFIG2 .= 'keyUsage = critical, digitalSignature' . $nl;
            $strCONFIG2 .= 'extendedKeyUsage = clientAuth' . $nl;
            $strCONFIG2 .= 'subjectKeyIdentifier = hash' . $nl;
            $strCONFIG2 .= 'authorityKeyIdentifier = keyid' . $nl;

            $cfgHandle2 = fopen($cfgPath, 'w');
            if ($cfgHandle2 === false) {
                throw new \RuntimeException($this->t('Failed to create temporary OpenSSL configuration (fallback)'));
            }
            fwrite($cfgHandle2, $strCONFIG2);
            fclose($cfgHandle2);

            $config2 = [
                'config' => $cfgPath,
                'digest_alg' => 'sha256'
            ];
            $csr = openssl_csr_new($dn, $pkGenerate, $config2);
            if ($csr === false) {
                throw new \RuntimeException($this->t('openssl_csr_new failed'));
            }
            // Use config2 for signing as well
            $config = $config2;
        }

        // Try to obtain LG-signed certificate first (preferred)
        $lgCertOut = '';
        $lgSubscriptions = null;
        try {
            // Build a temporary HTTP client whose x-client-id equals the CSR CN (subjectCN)
            $baseCfg = $this->createBridgeConfig();
            $tmpCfg = ThinQBridgeConfig::create(
                $baseCfg->accessToken,
                $baseCfg->countryCode,
                $subjectCN, // x-client-id must match CSR CN
                $baseCfg->debug,
                $baseCfg->useMqtt,
                $baseCfg->mqttClientId,
                $baseCfg->mqttTopicFilter,
                $baseCfg->ignoreRetained,
                $baseCfg->eventTtlHours,
                $baseCfg->eventRenewLeadMin
            );
            $tmpHttp = new ThinQHttpClient($this, $tmpCfg, self::API_KEY);

            // Export CSR PEM for API
            $csrPemForApi = '';
            @openssl_csr_export($csr, $csrPemForApi);
            if ((bool)$this->ReadPropertyBoolean('Debug')) {
                $this->SendDebug('CertGen', 'CSR length=' . strlen((string)$csrPemForApi), 0);
                $this->SendDebug('CertGen', 'Register client and request certificate for x-client-id=' . $subjectCN, 0);
            }

            // 1) Register client (idempotent)
            try {
                $payloadRegister = ['body' => ['type' => 'MQTT', 'service-code' => 'SVC202', 'device-type' => '607']];
                $tmpHttp->request('POST', 'client', $payloadRegister);
            } catch (\Throwable $e) {
                // ignore errors (idempotent/register may already exist)
                $this->SendDebug('CertGen', 'Register client ignored: ' . $e->getMessage(), 0);
            }

            // 2) Request certificate
            $payloadCert = ['body' => ['service-code' => 'SVC202', 'csr' => $csrPemForApi]];
            $resp = $tmpHttp->request('POST', 'client/certificate', $payloadCert);
            // ThinQHttpClient returns $decoded['response'] ?? $decoded
            $resNode = $resp;
            if (isset($resp['result']) && is_array($resp['result'])) {
                $resNode = $resp['result'];
            }
            $maybeCert = $resNode['certificatePem'] ?? null;
            if (is_string($maybeCert) && trim($maybeCert) !== '') {
                $lgCertOut = (string)$maybeCert;
            }
            $lgSubscriptions = $resNode['subscriptions'] ?? null;

            // Fallback: some APIs might return without 'body' wrapper
            if ($lgCertOut === '') {
                $payloadCert2 = ['service-code' => 'SVC202', 'csr' => $csrPemForApi];
                $resp2 = $tmpHttp->request('POST', 'client/certificate', $payloadCert2);
                $resNode2 = $resp2;
                if (isset($resp2['result']) && is_array($resp2['result'])) {
                    $resNode2 = $resp2['result'];
                }
                $maybeCert2 = $resNode2['certificatePem'] ?? null;
                if (is_string($maybeCert2) && trim($maybeCert2) !== '') {
                    $lgCertOut = (string)$maybeCert2;
                }
                if ($lgSubscriptions === null) {
                    $lgSubscriptions = $resNode2['subscriptions'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            $this->SendDebug('CertGen', 'LG certificate request failed: ' . $e->getMessage(), 0);
            $lgCertOut = '';
        }

        // Require LG-signed certificate; no self-signed fallback
        if ($lgCertOut === '') {
            throw new \RuntimeException($this->t('LG certificate request returned no certificatePem'));
        }
        $certOut = $lgCertOut;
        $csrOut = '';
        if (!openssl_csr_export($csr, $csrOut)) {
            // Non-fatal, but log
            $csrOut = '';
        }
        if ((bool)$this->ReadPropertyBoolean('Debug')) {
            if ($lgSubscriptions !== null) {
                $this->SendDebug('CertGen', 'LG Subscriptions: ' . substr(json_encode($lgSubscriptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1000) . (strlen(json_encode($lgSubscriptions)) > 1000 ? ' ...[truncated]' : ''), 0);
            }
            $fpSha = hash('sha256', (string)$certOut);
            $x509 = @openssl_x509_read($certOut);
            if ($x509 !== false) {
                $parsed = @openssl_x509_parse($x509);
                if (is_array($parsed)) {
                    $subCN = $parsed['subject']['CN'] ?? ($parsed['subject']['commonName'] ?? '');
                    $issuer = $parsed['issuer']['CN'] ?? ($parsed['issuer']['commonName'] ?? '');
                    $eku = $parsed['extensions']['extendedKeyUsage'] ?? '';
                    $ku = $parsed['extensions']['keyUsage'] ?? '';
                    $this->SendDebug('CertGen', 'Cert subjectCN=' . $subCN . ' issuer=' . $issuer, 0);
                    $this->SendDebug('CertGen', 'Cert KU=' . $ku . ' EKU=' . $eku, 0);
                }
            }
            $this->SendDebug('CertGen', 'Cert PEM length=' . strlen($certOut) . ' sha256=' . $fpSha, 0);
            // Verify that the certificate matches the generated private key
            $matches = @openssl_x509_check_private_key($certOut, $pkPrivate);
            $this->SendDebug('CertGen', 'Cert matches private key: ' . ($matches ? 'yes' : 'no'), 0);
        }

        // Create ZIP
        $zip = new \ZipArchive();
        $tmpZip = tempnam(sys_get_temp_dir(), 'lgtq_mqtt_' . $this->InstanceID . '_' . bin2hex(random_bytes(4)) . '.cnf');
        if ($tmpZip === false) {
            throw new \RuntimeException($this->t('Failed to create temporary ZIP file'));
        }
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);
            throw new \RuntimeException($this->t('Failed to open ZIP'));
        }

        // 00_meta.json
        $meta = [
            'module' => 'LG ThinQ Bridge',
            'purpose' => 'MQTT Client Zertifikate',
            'instanceId' => $this->InstanceID,
            'alias' => @IPS_GetName($this->InstanceID),
            'clientId' => $clientId,
            'subjectCN' => $subjectCN,
            'lgSigned' => ($lgCertOut !== ''),
            'lgSubscriptions' => $lgSubscriptions,
            'timestamp' => date('c'),
            'phpVersion' => PHP_VERSION,
            'kernelVersion' => function_exists('IPS_GetKernelVersion') ? @IPS_GetKernelVersion() : ''
        ];
        $zip->addFromString('00_meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Cert material
        $zip->addFromString('client_cert.pem', $certOut);
        $zip->addFromString('client_private_key.pem', $pkPrivate);
        $zip->addFromString('client_public_key.pem', $pkPublic);
        if ($csrOut !== '') {
            $zip->addFromString('client_csr.pem', $csrOut);
        }

        $readme = "Diese ZIP-Datei enthält ein über die LG ThinQ API signiertes Client-Zertifikat für den MQTT-Client (inkl. X.509 v3 Extended Key Usage: clientAuth).\n\n"
            . "Dateien:\n"
            . "- client_cert.pem: X.509 Client-Zertifikat (LG-signiert)\n"
            . "- client_private_key.pem: Privater Schlüssel (PEM, unverschlüsselt)\n"
            . "- client_public_key.pem: Öffentlicher Schlüssel\n"
            . ($csrOut !== '' ? "- client_csr.pem: Certificate Signing Request (CSR)\n" : '')
            . "\nHinweise:\n"
            . "- Importieren Sie Zertifikat und privaten Schlüssel dort, wo Ihr MQTT-Client diese benötigt.\n"
            . "- Die CN (Common Name) muss exakt der MQTT ClientID entsprechen.\n";
        $zip->addFromString('README.txt', $readme);

        $zip->close();
        $data = file_get_contents($tmpZip);
        @unlink($tmpZip);
        if ($data === false) {
            throw new \RuntimeException($this->t('Failed to read ZIP content'));
        }
        @unlink($cfgPath);
        return $data;
    }

    // --- UI: One-click MQTT setup (create certs, MQTT Client, Client Socket) ---
    public function UISetupMqttConnection(): void
    {
        try {
            $this->ensureBooted();
            // 1) Determine broker host/port from LG /route endpoint (preferred)
            $route = $this->fetchRouteBroker();
            $HOST = (string)$route['host'];
            $USE_TLS = (bool)$route['tls'];
            $PORT = (int)$route['port'];
            $mqttUrl = (string)($route['url'] ?? '');
            $routeCA = (string)($route['ca'] ?? '');

            // 2) Generate/get LG-signed certificate (also returns subscriptions; used as fallback for host discovery)
            $certInfo = $this->generateMqttClientCertMaterial();
            $subjectCN = (string)$certInfo['cn'];
            $certPem   = (string)$certInfo['cert'];
            $keyPem    = (string)$certInfo['key'];
            $subsMeta  = $certInfo['subscriptions'];

            // Fallback: if /route did not yield a host, try to extract from certificate response subscriptions
            if ($HOST === '') {
                $broker = $this->extractBrokerFromSubscriptions($subsMeta);
                $HOST = (string)$broker['host'];
                $USE_TLS = (bool)$broker['tls'];
                $PORT = (int)$broker['port'];
            }

            $VERIFY_PEER = true;
            $VERIFY_HOST = true;

            if ($HOST === '') {
                throw new \RuntimeException($this->t('Broker host could not be determined from API data.'));
            }
            if ($PORT <= 0) {
                $PORT = $USE_TLS ? 8883 : 1883;
            }
            // 3) Subscriptions: if a custom filter is set in the module, use it; otherwise subscribe to the LG push topic for this ClientID
            $filter = trim((string)$this->ReadPropertyString('MQTTTopicFilter'));
            if ($filter !== '') {
                $topic = $filter;
                // Support placeholder replacement and the common wildcard pattern
                if (strpos($topic, '{ClientID}') !== false) {
                    $topic = str_replace('{ClientID}', $subjectCN, $topic);
                } elseif ($topic === 'app/clients/*/push' || $topic === 'app/clients/*/#') {
                    $topic = 'app/clients/' . $subjectCN . '/push';
                }
                $SUB_TOPICS = [$topic];
            } else {
                // Exact push topic for this client
                $SUB_TOPICS = ['app/clients/' . $subjectCN . '/push'];
            }

            // 0) DNS check to avoid host-not-found later
            $ip = @gethostbyname($HOST);
            if ($ip === $HOST && !filter_var($HOST, FILTER_VALIDATE_IP)) {
                throw new \RuntimeException(sprintf($this->t("Hostname '%s' could not be resolved."), $HOST));
            }

            // 4) Create or reuse MQTT Client instance
            $NAME_MQTT = 'LGThinQ MQTT Client (' . $HOST . ')';
            $NAME_IO   = 'LGThinQ MQTT Client Socket (' . $HOST . ')';
            $mqttGUID = $this->findModuleGUIDByName('MQTT Client');
            if ($mqttGUID === null) {
                throw new \RuntimeException($this->t("Module 'MQTT Client' not found."));
            }
            $mqttID = 0;
            // Prefer configured property if it points to a valid MQTT Client instance
            $propMqttId = (int)$this->ReadPropertyInteger('MQTTClientID');
            if ($propMqttId > 0 && @IPS_InstanceExists($propMqttId)) {
                $info = @IPS_GetInstance($propMqttId);
                if (is_array($info) && isset($info['ModuleID']) && (string)$info['ModuleID'] === (string)$mqttGUID) {
                    $mqttID = $propMqttId;
                }
            }
            // Next: find by ObjectIdent marker
            if ($mqttID === 0) {
                $targetIdent = 'LGThinQ.MQTT.' . (string)$this->InstanceID;
                foreach ($this->instancesOf('MQTT Client') as $id) {
                    $obj = @IPS_GetObject($id);
                    $ident = is_array($obj) ? (string)($obj['ObjectIdent'] ?? '') : '';
                    if ($ident === $targetIdent) { $mqttID = $id; break; }
                }
            }
            // Next: find by exact ClientID match
            if ($mqttID === 0 && $subjectCN !== '') {
                foreach ($this->instancesOf('MQTT Client') as $id) {
                    $c = $this->cfg($id);
                    if (($c['ClientID'] ?? null) === $subjectCN) { $mqttID = $id; break; }
                }
            }
            if ($mqttID === 0) {
                $mqttID = IPS_CreateInstance($mqttGUID);
                IPS_SetName($mqttID, $NAME_MQTT);
                // Mark instance with stable ident for diagnostics (do not rely on names in lookups)
                try { IPS_SetIdent($mqttID, 'LGThinQMQTT' . (string)$this->InstanceID); } catch (\Throwable $e) { /* ignore */ }
            }

            // Configure MQTT Client first
            if ($subjectCN !== '') { $this->safeSetProperty($mqttID, 'ClientID', $subjectCN); }
            $this->setFirstAvailableProperty($mqttID, ['UserName','Username'], '');
            $this->safeSetProperty($mqttID, 'Password', '');
            $this->safeSetProperty($mqttID, 'KeepAlive', 60);
            $this->safeSetProperty($mqttID, 'CleanSession', true);
            $subs = array_map(function ($t) { return ['Topic' => $t, 'QoS' => 0]; }, $SUB_TOPICS);
            if (!$this->setJsonCompatibleProperty($mqttID, 'Subscriptions', $subs)) {
                $this->setJsonCompatibleProperty($mqttID, 'Subscribe', $subs);
            }
            IPS_ApplyChanges($mqttID);

            // 5) Find/Create IO (Client Socket) and connect
            $ioID = (int)(@IPS_GetInstance($mqttID)['ConnectionID'] ?? 0);
            if ($ioID === 0) {
                // give Symcon a chance to auto-create
                for ($i = 0; $i < 20 && $ioID === 0; $i++) { IPS_Sleep(100); $ioID = (int)(@IPS_GetInstance($mqttID)['ConnectionID'] ?? 0); }
            }
            if ($ioID === 0) {
                // Try to reuse a pre-existing IO by ident
                $reuse = 0;
                $targetIdentIO = 'LGThinQ.IO.' . (string)$this->InstanceID;
                foreach ($this->instancesOf('Client Socket') as $id) {
                    $obj = @IPS_GetObject($id);
                    $ident = is_array($obj) ? (string)($obj['ObjectIdent'] ?? '') : '';
                    if ($ident === $targetIdentIO) { $reuse = $id; break; }
                }
                if ($reuse > 0) {
                    $ioID = $reuse;
                    IPS_ConnectInstance($mqttID, $ioID);
                    IPS_Sleep(100);
                } else {
                    $ioGUID = $this->findModuleGUIDByName('Client Socket');
                    if ($ioGUID === null) { throw new \RuntimeException($this->t("Module 'Client Socket' not found.")); }
                    $ioID = IPS_CreateInstance($ioGUID);
                    IPS_SetName($ioID, $NAME_IO);
                    IPS_ConnectInstance($mqttID, $ioID);
                    IPS_Sleep(100);
                    try { IPS_SetIdent($ioID, 'LGThinQ.IO.' . (string)$this->InstanceID); } catch (\Throwable $e) { /* ignore */ }
                }
            }

            // Configure IO
            if ($this->isInstanceOfModule($ioID, 'Client Socket')) {
                $this->safeSetProperty($ioID, 'Open', false);
                $this->safeSetProperty($ioID, 'Host', $HOST);
                $this->safeSetProperty($ioID, 'Port', (int)$PORT);
                $this->setFirstAvailableProperty($ioID, ['UseSSL','EnableSSL'], (bool)$USE_TLS);
                $this->safeSetProperty($ioID, 'VerifyPeer', (bool)$VERIFY_PEER);
                $this->safeSetProperty($ioID, 'VerifyHost', (bool)$VERIFY_HOST);
                // Set exact Client Socket properties (inline base64 PEMs)
                $ioCfg = $this->cfg($ioID);
                // Ensure proper PEM formatting with correct line endings
                $certPemFormatted = $this->ensureCertificatePEM($certPem);
                $keyPemFormatted = $this->ensurePrivateKeyPEM($keyPem);
                $caPemFormatted = '';
                $haveCA = false;
                
                // Validate and format CA PEM before using it (accept headerless base64 from route as well)
                $haveCA = false;
                if ($routeCA !== '') {
                    $caPemFormatted = $this->ensureCertificatePEM($routeCA);
                    if (@openssl_x509_read($caPemFormatted) !== false) {
                        $haveCA = true;
                    } else if ((bool)$this->ReadPropertyBoolean('Debug')) {
                        $this->SendDebug('MQTT', 'Route-provided CA invalid, length=' . strlen((string)$routeCA), 0);
                    }
                }
                // Prefer CA from LG API subscriptions metadata (if present)
                if (!$haveCA && $subsMeta !== null) {
                    $apiCAPem = $this->extractCAPEMFromSubscriptions($subsMeta);
                    if (is_string($apiCAPem) && $apiCAPem !== '') {
                        $caPemFormatted = $this->ensureCertificatePEM($apiCAPem);
                        if (@openssl_x509_read($caPemFormatted) !== false) {
                            $haveCA = true;
                            if ((bool)$this->ReadPropertyBoolean('Debug')) {
                                $this->SendDebug('MQTT', 'CA from LG API subscriptions applied (len=' . strlen($caPemFormatted) . ')', 0);
                            }
                        }
                    }
                }
                // Fallback: try to fetch Amazon Root CA 1 for AWS IoT ATS endpoints
                if (!$haveCA) {
                    $awsCA = $this->downloadAmazonRootCA1();
                    if (is_string($awsCA) && $awsCA !== '' && strpos($awsCA, '-----BEGIN CERTIFICATE-----') !== false) {
                        $caPemFormatted = $this->ensureCertificatePEM($awsCA);
                        if (@openssl_x509_read($caPemFormatted) !== false) {
                            $haveCA = true;
                            if ((bool)$this->ReadPropertyBoolean('Debug')) {
                                $this->SendDebug('MQTT', 'CA fallback: Amazon Root CA 1 applied (len=' . strlen($caPemFormatted) . ')', 0);
                            }
                        }
                    }
                }

                // Build concatenated chain (leaf + optional CA) for inline usage
                $chainPem = rtrim($certPemFormatted) . "\n";
                if ($haveCA) { $chainPem .= rtrim($caPemFormatted) . "\n"; }

                // Assign INLINE PEM content directly to properties (Certificate, PrivateKey, CertificateAuthority)
                $this->safeSetProperty($ioID, 'UseCertificate', true);
                // Clear any file-path properties to avoid conflicts
                foreach (['CertificateFile','ClientCertificateFile','LocalCert','LocalCertificate'] as $prop) { $this->safeSetProperty($ioID, $prop, ''); }
                foreach (['PrivateKeyFile','ClientKeyFile','LocalPrivateKey','LocalPrivateKeyFile'] as $prop) { $this->safeSetProperty($ioID, $prop, ''); }
                foreach (['CertificateAuthorityFile','CAFile','CACertificateFile','RootCertificateFile','RootCAFile','CACertFile'] as $prop) { $this->safeSetProperty($ioID, $prop, ''); }

                // Write inline PEMs as base64 (mirrors manual UI behavior)
                $this->safeSetProperty($ioID, 'Certificate', base64_encode($chainPem));
                $this->safeSetProperty($ioID, 'PrivateKey', base64_encode($keyPemFormatted));
                if ($haveCA) {
                    $this->safeSetProperty($ioID, 'CertificateAuthority', base64_encode($caPemFormatted));
                } else {
                    // ensure we don't keep stale inline CA
                    $this->safeSetProperty($ioID, 'CertificateAuthority', '');
                }

                // Private key is unencrypted; ensure empty password (handled by $usedPwdProp if available)
                $usedPwdProp  = $this->setFirstAvailableProperty($ioID, ['Password','PassPhrase'], '');

                if ((bool)$this->ReadPropertyBoolean('Debug')) {
                    $this->SendDebug('MQTT', 'IO keys: ' . implode(',', array_keys($ioCfg)), 0);
                    $this->SendDebug('MQTT', 'Using cert prop: inline(base64) Certificate, key prop: inline(base64) PrivateKey', 0);
                    $okCert = @openssl_x509_read($certPemFormatted) !== false;
                    $okKey  = @openssl_pkey_get_private($keyPemFormatted) !== false;
                    $okPair = @openssl_x509_check_private_key($certPemFormatted, $keyPemFormatted);
                    $this->SendDebug('MQTT', 'Props applied: UseCertificate, Certificate(len=' . strlen($chainPem) . ', parse=' . ($okCert?'OK':'FAIL') . '), PrivateKey(len=' . strlen($keyPemFormatted) . ', parse=' . ($okKey?'OK':'FAIL') . '), PairMatch=' . ($okPair?'yes':'no') . ', Password(empty), CA(' . ($haveCA ? ('len=' . strlen($caPemFormatted)) : 'none') . ')', 0);
                }
                IPS_ApplyChanges($ioID);
                IPS_SetName($ioID, $NAME_IO);

                // Validate that inline PEMs persisted correctly; if not, log diagnostics (no file fallback)
                try {
                    $curCfg = $this->cfg($ioID);
                    $curCert = (string)($curCfg['Certificate'] ?? '');
                    $curKey  = (string)($curCfg['PrivateKey'] ?? '');
                    $curCA   = (string)($curCfg['CertificateAuthority'] ?? '');

                    // Helper to decode if base64-encoded PEM was returned
                    $decodeIfB64 = function (string $s): string {
                        $trim = trim($s);
                        if ($trim === '') { return ''; }
                        if (strpos($trim, '-----BEGIN') === 0) { return $trim; }
                        // try base64 decode
                        $bin = base64_decode($trim, true);
                        if ($bin !== false && strpos($bin, '-----BEGIN') !== false) { return $bin; }
                        return $trim; // unknown format; return as-is
                    };

                    $curCertPem = $decodeIfB64($curCert);
                    $curKeyPem  = $decodeIfB64($curKey);
                    $curCAPem   = $decodeIfB64($curCA);

                    $okCertPersist = (strpos($curCertPem, '-----BEGIN CERTIFICATE-----') !== false) && (@openssl_x509_read($curCertPem) !== false);
                    $okKeyPersist  = (strpos($curKeyPem, '-----BEGIN') !== false) && (@openssl_pkey_get_private($curKeyPem) !== false);
                    $okCAPersist   = ($haveCA === false) || ((strpos($curCAPem, '-----BEGIN CERTIFICATE-----') !== false) && (@openssl_x509_read($curCAPem) !== false));

                    if ((bool)$this->ReadPropertyBoolean('Debug')) {
                        $this->SendDebug('MQTT', 'Post-write check: certLen=' . strlen($curCert) . ' keyLen=' . strlen($curKey) . ' caLen=' . strlen($curCA) . ' okCert=' . ($okCertPersist?'yes':'no') . ' okKey=' . ($okKeyPersist?'yes':'no') . ' okCA=' . ($okCAPersist?'yes':'no'), 0);
                    }

                    if (!$okCertPersist || !$okKeyPersist || !$okCAPersist) {
                        // No file fallback anymore; surface diagnostics so the user can adjust manually if needed
                        $this->SendDebug('MQTT', 'Inline PEM persistence check failed (cert=' . ($okCertPersist?'ok':'fail') . ', key=' . ($okKeyPersist?'ok':'fail') . ', ca=' . ($okCAPersist?'ok':'fail') . '). Please verify your Symcon version supports inline certificate properties.', 0);
                    }
                } catch (\Throwable $e) {
                    if ((bool)$this->ReadPropertyBoolean('Debug')) {
                        $this->SendDebug('MQTT', 'Post-write check exception: ' . $e->getMessage(), 0);
                    }
                }

                // Open socket now
                $this->safeSetProperty($ioID, 'Open', true);
                IPS_ApplyChanges($ioID);
                // Log final parent/io configuration for diagnostics
                if ((bool)$this->ReadPropertyBoolean('Debug')) {
                    $this->debugMqttParentInfo();
                }
            } else {
                $this->SendDebug('MQTT', 'Warnung: Parent #' . $ioID . ' ist kein Client Socket', 0);
            }

            // 6) Connect this Bridge to the configured MQTT Client and persist setting (no name matching)
            IPS_ConnectInstance($this->InstanceID, $mqttID);
            IPS_SetProperty($this->InstanceID, 'UseMQTT', true);
            IPS_SetProperty($this->InstanceID, 'MQTTClientID', (int)$mqttID);
            IPS_ApplyChanges($this->InstanceID);

            // 7) Post-setup: register client idempotently with final ClientID (subjectCN)
            try {
                $baseCfg2 = $this->createBridgeConfig();
                $tmpCfg2 = ThinQBridgeConfig::create(
                    $baseCfg2->accessToken,
                    $baseCfg2->countryCode,
                    $subjectCN,
                    $baseCfg2->debug,
                    $baseCfg2->useMqtt,
                    $baseCfg2->mqttClientId,
                    $baseCfg2->mqttTopicFilter,
                    $baseCfg2->ignoreRetained,
                    $baseCfg2->eventTtlHours,
                    $baseCfg2->eventRenewLeadMin
                );
                $http2 = new ThinQHttpClient($this, $tmpCfg2, self::API_KEY);
                try {
                    $http2->request('POST', 'client', ['body' => ['type' => 'MQTT', 'service-code' => 'SVC202', 'device-type' => '607']]);
                } catch (\Throwable $e) {
                    // ignore failures (idempotent), but log for diagnostics
                    $this->SendDebug('UISetupMqttConnection', 'Register client ignored: ' . $e->getMessage(), 0);
                }
            } catch (\Throwable $e) {
                $this->SendDebug('UISetupMqttConnection', 'Register client failed: ' . $e->getMessage(), 0);
            }

            // Done / Report
            echo $this->t('Done') . ".\n" . 'Client Socket ID: ' . $ioID . "\n" . 'MQTT Client ID:   ' . $mqttID . "\n";
            $this->NotifyUser($this->t('MQTT connection configured') . ' (ClientID=' . $subjectCN . ', Host=' . $HOST . ':' . $PORT . ').');
        } catch (\Throwable $e) {
            $this->SendDebug('UISetupMqttConnection', $e->getMessage(), 0);
            echo $this->t('Error') . ': ' . $e->getMessage();
        }
    }

    /**
     * @return array{cn:string, cert:string, key:string, public:string, subscriptions:mixed}
     */
    private function generateMqttClientCertMaterial(): array
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('OpenSSL wird nicht unterstützt (openssl_* Funktionen fehlen)');
        }

        // Determine CN (prefer parent MQTT ClientID); init ClientID if missing
        $clientId = trim((string)$this->ReadAttributeString('ClientID'));
        if ($clientId === '') {
            $propId = trim((string)$this->ReadPropertyString('ClientID'));
            if ($propId !== '') {
                $clientId = $propId;
                $this->WriteAttributeString('ClientID', $clientId);
            } else {
                try {
                    $rand5 = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                } catch (\Throwable $e) {
                    $rand5 = str_pad((string)mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
                }
                $clientId = 'Symcon' . $rand5;
                $this->WriteAttributeString('ClientID', $clientId);
                @IPS_SetProperty($this->InstanceID, 'ClientID', $clientId);
            }
        }
        $subjectCN = $clientId;
        $instInfo = @IPS_GetInstance($this->InstanceID);
        if (is_array($instInfo)) {
            $parentId = (int)($instInfo['ConnectionID'] ?? 0);
            if ($parentId > 0) {
                $parentClientId = trim((string)@IPS_GetProperty($parentId, 'ClientID'));
                if ($parentClientId !== '') {
                    $subjectCN = $parentClientId;
                }
            }
        }
        $subjectCN = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$subjectCN);
        if (!is_string($subjectCN) || trim($subjectCN) === '') {
            $subjectCN = 'Symcon00000';
        }

        // OpenSSL temp config
        $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lgtq_mqtt_' . $this->InstanceID . '_' . bin2hex(random_bytes(4)) . '.cnf';
        $nl = "\r\n";
        $strCONFIG  = 'default_md = sha256' . $nl;
        $strCONFIG .= 'default_days = 3650' . $nl;
        $strCONFIG .= $nl;
        $strCONFIG .= '[ req ]' . $nl;
        $strCONFIG .= 'default_bits = 2048' . $nl;
        $strCONFIG .= 'distinguished_name = req_DN' . $nl;
        $strCONFIG .= 'string_mask = nombstr' . $nl;
        $strCONFIG .= 'prompt = no' . $nl;
        $strCONFIG .= 'x509_extensions = v3_client' . $nl;
        $strCONFIG .= $nl;
        $strCONFIG .= '[ req_DN ]' . $nl;
        $strCONFIG .= 'commonName = "' . addslashes($subjectCN) . '"' . $nl;
        $strCONFIG .= $nl;
        $strCONFIG .= '[ v3_client ]' . $nl;
        $strCONFIG .= 'basicConstraints = critical, CA:FALSE' . $nl;
        $strCONFIG .= 'keyUsage = critical, digitalSignature' . $nl;
        $strCONFIG .= 'extendedKeyUsage = clientAuth' . $nl;
        $strCONFIG .= 'subjectKeyIdentifier = hash' . $nl;
        $strCONFIG .= 'authorityKeyIdentifier = keyid' . $nl;
        $cfgHandle = fopen($cfgPath, 'w');
        if ($cfgHandle === false) { throw new \RuntimeException('Konnte temporäre OpenSSL-Konfiguration nicht erstellen'); }
        fwrite($cfgHandle, $strCONFIG); fclose($cfgHandle);

        // Generate key (EC P-256 preferred)
        $configKey = ['config' => $cfgPath, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $pkGenerate = @openssl_pkey_new($configKey);
        if ($pkGenerate === false) {
            $configKey = ['config' => $cfgPath, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $pkGenerate = openssl_pkey_new($configKey);
        }
        if ($pkGenerate === false) { throw new \RuntimeException('openssl_pkey_new fehlgeschlagen'); }

        $pkPrivate = '';
        if (!openssl_pkey_export($pkGenerate, $pkPrivate, null, ['config' => $cfgPath, 'digest_alg' => 'sha256'])) {
            throw new \RuntimeException('openssl_pkey_export fehlgeschlagen');
        }
        $pkDetails = openssl_pkey_get_details($pkGenerate);
        if ($pkDetails === false || !isset($pkDetails['key'])) { throw new \RuntimeException('openssl_pkey_get_details fehlgeschlagen'); }
        $pkPublic = (string)$pkDetails['key'];

        // CSR (with fallback minimal config)
        $dn = ['commonName' => $subjectCN];
        $config = ['config' => $cfgPath, 'digest_alg' => 'sha256'];
        $csr = openssl_csr_new($dn, $pkGenerate, $config);
        if ($csr === false) {
            $strCONFIG2  = 'default_md = sha256' . $nl;
            $strCONFIG2 .= 'default_days = 3650' . $nl;
            $strCONFIG2 .= $nl;
            $strCONFIG2 .= '[ req ]' . $nl;
            $strCONFIG2 .= 'default_bits = 2048' . $nl;
            $strCONFIG2 .= 'distinguished_name = req_DN' . $nl;
            $strCONFIG2 .= 'string_mask = nombstr' . $nl;
            $strCONFIG2 .= 'prompt = no' . $nl;
            $strCONFIG2 .= 'x509_extensions = v3_client' . $nl;
            $strCONFIG2 .= $nl;
            $strCONFIG2 .= '[ req_DN ]' . $nl;
            $strCONFIG2 .= 'commonName = "' . addslashes($subjectCN) . '"' . $nl;
            $strCONFIG2 .= $nl;
            $strCONFIG2 .= '[ v3_client ]' . $nl;
            $strCONFIG2 .= 'basicConstraints = critical, CA:FALSE' . $nl;
            $strCONFIG2 .= 'keyUsage = critical, digitalSignature' . $nl;
            $strCONFIG2 .= 'extendedKeyUsage = clientAuth' . $nl;
            $strCONFIG2 .= 'subjectKeyIdentifier = hash' . $nl;
            $strCONFIG2 .= 'authorityKeyIdentifier = keyid' . $nl;
            $cfgHandle2 = fopen($cfgPath, 'w'); if ($cfgHandle2 === false) { throw new \RuntimeException('Konnte temporäre OpenSSL-Konfiguration (Fallback) nicht erstellen'); }
            fwrite($cfgHandle2, $strCONFIG2); fclose($cfgHandle2);
            $config = ['config' => $cfgPath, 'digest_alg' => 'sha256'];
            $csr = openssl_csr_new($dn, $pkGenerate, $config);
            if ($csr === false) { throw new \RuntimeException('openssl_csr_new fehlgeschlagen'); }
        }

        // Request LG-signed certificate; ensure x-client-id equals CSR CN
        $baseCfg = $this->createBridgeConfig();
        $tmpCfg = ThinQBridgeConfig::create(
            $baseCfg->accessToken,
            $baseCfg->countryCode,
            $subjectCN,
            $baseCfg->debug,
            $baseCfg->useMqtt,
            $baseCfg->mqttClientId,
            $baseCfg->mqttTopicFilter,
            $baseCfg->ignoreRetained,
            $baseCfg->eventTtlHours,
            $baseCfg->eventRenewLeadMin
        );
        $tmpHttp = new ThinQHttpClient($this, $tmpCfg, self::API_KEY);
        $csrPemForApi = '';
        @openssl_csr_export($csr, $csrPemForApi);

        try {
            // idempotent register
            try {
                $tmpHttp->request('POST', 'client', ['body' => ['type' => 'MQTT', 'service-code' => 'SVC202', 'device-type' => '607']]);
            } catch (\Throwable $e) {
                // ignore errors (idempotent/register may already exist)
                $this->SendDebug('CertGen', 'Register client ignored: ' . $e->getMessage(), 0);
            }
            $resp = $tmpHttp->request('POST', 'client/certificate', ['body' => ['service-code' => 'SVC202', 'csr' => $csrPemForApi]]);
        } catch (\Throwable $e) {
            // Fallback without body wrapper
            $resp = $tmpHttp->request('POST', 'client/certificate', ['service-code' => 'SVC202', 'csr' => $csrPemForApi]);
        }
        $resNode = $resp;
        if (isset($resp['result']) && is_array($resp['result'])) { $resNode = $resp['result']; }
        $certOut = (string)($resNode['certificatePem'] ?? '');
        if ($certOut === '') { throw new \RuntimeException('LG certificate request returned no certificatePem'); }
        @unlink($cfgPath);
        return [
            'cn' => $subjectCN,
            'cert' => $certOut,
            'key' => $pkPrivate,
            'public' => $pkPublic,
            'subscriptions' => $resNode['subscriptions'] ?? null
        ];
    }

    /**
     * @param mixed $subscriptions
     * @return array{host:string, port:int, tls:bool}
     */
    private function extractBrokerFromSubscriptions($subscriptions): array
    {
        $host = '';
        $port = 0;
        $tls = true;
        $scan = function ($node) use (&$host, &$port, &$tls, &$scan): void {
            if (!is_array($node)) { return; }
            foreach ($node as $k => $v) {
                $lk = strtolower((string)$k);
                if ($lk === 'host' && is_string($v) && $host === '') { $host = $v; }
                if ($lk === 'port' && is_numeric($v) && $port === 0) { $port = (int)$v; }
                if (($lk === 'tls' || $lk === 'secure' || $lk === 'ssl') && is_bool($v)) { $tls = (bool)$v; }
                if ($lk === 'url' || $lk === 'endpoint' || $lk === 'broker' || $lk === 'server') {
                    if (is_string($v)) {
                        $p = @parse_url($v);
                        if (is_array($p)) {
                            if ($host === '' && isset($p['host'])) { $host = (string)$p['host']; }
                            if ($port === 0 && isset($p['port'])) { $port = (int)$p['port']; }
                            if (isset($p['scheme'])) { $tls = (strtolower((string)$p['scheme']) !== 'mqtt'); }
                        }
                    }
                }
                if (is_array($v)) { $scan($v); }
            }
        };
        $scan($subscriptions);
        return ['host' => (string)$host, 'port' => (int)$port, 'tls' => (bool)$tls];
    }

    /**
     * Determine MQTT broker from LG /route endpoint.
     * @return array{url:string, host:string, port:int, tls:bool}
     */
    private function fetchRouteBroker(): array
    {
        $url = '';
        $host = '';
        $port = 0;
        $tls = true;
        $usedWssFallback = false;
        $caPem = '';
        try {
            $resp = $this->httpClient->request('GET', 'route');
            // ThinQHttpClient already unwraps ['response'] if present.
            $mqtt = $resp['mqttServer'] ?? ($resp['mqtt'] ?? null);
            $wss  = $resp['webSocketServer'] ?? null;
            // Heuristic: Some routes may provide certificate authority material
            $caPem = (string)($resp['certificateAuthority'] ?? ($resp['caCertificate'] ?? ($resp['caPem'] ?? '')));

            // Accept either a string URL or an object { url: "mqtts://..." }
            if (is_string($mqtt)) {
                $url = $mqtt;
            } elseif (is_array($mqtt)) {
                $url = (string)($mqtt['url'] ?? ($mqtt['endpoint'] ?? ($mqtt['server'] ?? '')));
                if ($url === '' && isset($mqtt['host'])) {
                    $host = (string)$mqtt['host'];
                    $port = (int)$mqtt['port'];
                    $tls  = (bool)$mqtt['tls'];
                }
                if ($caPem === '') {
                    $caPem = (string)($mqtt['certificateAuthority'] ?? ($mqtt['ca'] ?? ($mqtt['caPem'] ?? '')));
                }
            }

            if ($url === '' && is_string($wss) && stripos($wss, 'wss://') === 0) {
                // last-resort: derive host from web socket server; do NOT use port 443 for raw MQTT
                $url = $wss;
                $usedWssFallback = true;
            }

            if ($url !== '') {
                $p = @parse_url($url);
                if (is_array($p)) {
                    if ($host === '' && isset($p['host'])) { $host = (string)$p['host']; }
                    if ($port === 0 && isset($p['port'])) { $port = (int)$p['port']; }
                    $scheme = strtolower((string)($p['scheme'] ?? ''));
                    if ($scheme !== '') {
                        $tls = ($scheme !== 'mqtt'); // mqtts/wss => TLS; mqtt => no TLS
                    }
                }
            }
        } catch (\Throwable $e) {
            if ((bool)$this->ReadPropertyBoolean('Debug')) {
                $this->SendDebug('Route', 'Route-Call fehlgeschlagen: ' . $e->getMessage(), 0);
            }
        }

        if ($host === '') {
            // Let caller attempt fallback via certificate subscriptions
            return ['url' => (string)$url, 'host' => '', 'port' => 0, 'tls' => (bool)$tls];
        }
        // If we derived from WSS, force standard MQTT TLS port 8883 (MQTT Client does not use WebSocket)
        if ($usedWssFallback) {
            $port = 8883;
        } elseif ($port <= 0) {
            $port = $tls ? 8883 : 1883;
        }
        if ((bool)$this->ReadPropertyBoolean('Debug')) {
            $this->SendDebug('Route', 'MQTT: url=' . $url . ' host=' . $host . ' port=' . $port . ' tls=' . ($tls ? 'true' : 'false') . ' caLen=' . strlen((string)$caPem), 0);
        }
        return ['url' => (string)$url, 'host' => (string)$host, 'port' => (int)$port, 'tls' => (bool)$tls, 'ca' => (string)$caPem];
    }

    /**
     * Format PEM content with proper line endings and structure
     */
    private function formatPemContent(string $pemContent): string
    {
        if (trim($pemContent) === '') {
            return '';
        }
        
        // Normalize line endings to Unix style
        $content = str_replace(["\r\n", "\r"], "\n", $pemContent);
        
        // Remove any extra whitespace and ensure proper structure
        $lines = explode("\n", $content);
        $cleanLines = [];
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $cleanLines[] = $trimmed;
            }
        }
        
        // Ensure it ends with a newline
        return implode("\n", $cleanLines) . "\n";
    }

    /**
     * Ensure a valid single CERTIFICATE PEM with proper headers and 64-char wrapping.
     */
    private function ensureCertificatePEM(string $input): string
    {
        $norm = str_replace(["\r\n", "\r"], "\n", trim($input));
        // Try to extract existing PEM block
        if (preg_match('/-----BEGIN CERTIFICATE-----([A-Za-z0-9+\/=`\n\r\s]+)-----END CERTIFICATE-----/m', $norm, $m)) {
            $body = $m[1] ?? '';
            $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', (string)$body);
            $bin = base64_decode((string)$b64, true);
            if ($bin === false) {
                return $this->formatPemContent($norm) ?: $norm . "\n";
            }
            $wrapped = chunk_split(base64_encode($bin), 64, "\n");
            return "-----BEGIN CERTIFICATE-----\n" . rtrim($wrapped, "\n") . "\n-----END CERTIFICATE-----\n";
        }
        // Fallback: assume base64 content (possibly with whitespace)
        $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $norm);
        $bin = base64_decode((string)$b64, true);
        if ($bin === false) {
            // As a last resort, try to treat entire input as already PEM and tidy it
            return $this->formatPemContent($norm) ?: $norm . "\n";
        }
        $wrapped = chunk_split(base64_encode($bin), 64, "\n");
        return "-----BEGIN CERTIFICATE-----\n" . rtrim($wrapped, "\n") . "\n-----END CERTIFICATE-----\n";
    }

    /**
     * Ensure a valid PRIVATE KEY PEM (EC/RSA/PKCS#8) with proper headers and 64-char wrapping.
     */
    private function ensurePrivateKeyPEM(string $input): string
    {
        $norm = str_replace(["\r\n", "\r"], "\n", trim($input));
        if ($norm === '') { return ''; }
        // Try to detect existing PRIVATE KEY PEM (EC/RSA/PKCS#8)
        if (preg_match('/-----BEGIN ([A-Z ]*?)PRIVATE KEY-----([A-Za-z0-9+\/=`\n\r\s]+)-----END \1PRIVATE KEY-----/m', $norm, $m)) {
            $type = trim((string)($m[1] ?? ''));
            $body = (string)($m[2] ?? '');
            $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $body);
            $bin = base64_decode($b64, true);
            if ($bin === false) {
                return $this->formatPemContent($norm) ?: $norm . "\n";
            }
            $wrapped = chunk_split(base64_encode($bin), 64, "\n");
            $hdr = '-----BEGIN ' . ($type !== '' ? ($type . ' ') : '') . 'PRIVATE KEY-----';
            $ftr = '-----END '   . ($type !== '' ? ($type . ' ') : '') . 'PRIVATE KEY-----';
            return $hdr . "\n" . rtrim($wrapped, "\n") . "\n" . $ftr . "\n";
        }
        // Fallback: assume base64 of key and wrap as generic PKCS#8 PRIVATE KEY
        $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $norm);
        $bin = base64_decode($b64, true);
        if ($bin === false) {
            return $this->formatPemContent($norm) ?: $norm . "\n";
        }
        $wrapped = chunk_split(base64_encode($bin), 64, "\n");
        return "-----BEGIN PRIVATE KEY-----\n" . rtrim($wrapped, "\n") . "\n-----END PRIVATE KEY-----\n";
    }

    /**
     * Try to find a CA certificate within the 'subscriptions' metadata returned by the LG API.
     * Accept nested arrays/strings; returns a PEM string or empty string if none found.
     * @param mixed $subscriptions
     */
    private function extractCAPEMFromSubscriptions($subscriptions): string
    {
        $found = '';
        $scan = function ($node) use (&$scan, &$found): void {
            if ($found !== '') { return; }
            if (is_string($node)) {
                $str = trim($node);
                if ($str === '') { return; }
                // Direct PEM present
                if (strpos($str, '-----BEGIN CERTIFICATE-----') !== false) {
                    $pem = $str;
                    if (@openssl_x509_read($pem) !== false) { $found = $pem; }
                    return;
                }
                // Base64 candidate -> wrap to PEM and validate
                $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $str);
                if ($b64 !== '') {
                    $bin = base64_decode($b64, true);
                    if ($bin !== false) {
                        $wrapped = "-----BEGIN CERTIFICATE-----\n" . rtrim(chunk_split(base64_encode($bin), 64, "\n"), "\n") . "\n-----END CERTIFICATE-----\n";
                        if (@openssl_x509_read($wrapped) !== false) { $found = $wrapped; }
                    }
                }
                return;
            }
            if (is_array($node)) {
                // Check common CA-related keys first
                foreach (['ca','CA','certificateAuthority','root','rootCA','cacert','cacertificate'] as $k) {
                    if (isset($node[$k])) { $scan($node[$k]); if ($found !== '') { return; } }
                }
                foreach ($node as $v) { $scan($v); if ($found !== '') { return; } }
            }
        };
        $scan($subscriptions);
        return is_string($found) ? $found : '';
    }

    /**
     * Download Amazon Root CA 1 from Amazon's repository for AWS IoT ATS endpoints.
     * @return string PEM or empty string on failure
     */
    private function downloadAmazonRootCA1(): string
    {
        $url = 'https://www.amazontrust.com/repository/AmazonRootCA1.pem';
        // Try cURL first
        if (function_exists('curl_init')) {
            $ch = @curl_init($url);
            if ($ch !== false) {
                @curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT => 'LGThinQBridge/1.0 (+Symcon)'
                ]);
                $resp = @curl_exec($ch);
                $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
                @curl_close($ch);
                if (is_string($resp) && $code === 200 && strpos($resp, '-----BEGIN CERTIFICATE-----') !== false) {
                    return (string)$resp;
                }
            }
        }
        // Fallback to file_get_contents with short timeout
        $ctx = @stream_context_create([
            'http' => ['timeout' => 10, 'method' => 'GET', 'header' => "User-Agent: LGThinQBridge/1.0\r\n"],
            'https' => ['timeout' => 10]
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if (is_string($resp) && strpos($resp, '-----BEGIN CERTIFICATE-----') !== false) {
            return (string)$resp;
        }
        return '';
    }

    // ---- helper methods for instance/properties ----
    private function findModuleGUIDByName(string $name): ?string
    {
        foreach (@IPS_GetModuleList() as $guid) {
            $m = @IPS_GetModule($guid);
            if (!is_array($m)) { continue; }
            $names = array_merge([$m['ModuleName'] ?? ''], $m['Aliases'] ?? []);
            foreach ($names as $n) {
                if (mb_strtolower((string)$n) === mb_strtolower($name)) { return (string)$guid; }
            }
        }
        return null;
    }

    /**
     * @return array<int,int>
     */
    private function instancesOf(string $moduleName): array
    {
        $guid = $this->findModuleGUIDByName($moduleName);
        return $guid ? @IPS_GetInstanceListByModuleID($guid) : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function cfg(int $id): array
    {
        $raw = @IPS_GetConfiguration($id);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    private function safeSetProperty(int $id, string $prop, $value): bool
    {
        $c = $this->cfg($id);
        if (!array_key_exists($prop, $c)) { return false; }
        @IPS_SetProperty($id, $prop, $value);
        return true;
    }

    private function setFirstAvailableProperty(int $id, array $keys, $value): ?string
    {
        foreach ($keys as $k) {
            if ($this->safeSetProperty($id, $k, $value)) { return (string)$k; }
        }
        return null;
    }

    private function setJsonCompatibleProperty(int $id, string $prop, $arrayValue): bool
    {
        $c = $this->cfg($id);
        if (!array_key_exists($prop, $c)) { return false; }
        $cur = $c[$prop] ?? null;
        $val = is_string($cur) ? json_encode($arrayValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $arrayValue;
        @IPS_SetProperty($id, $prop, $val);
        return true;
    }

    private function isInstanceOfModule(int $instanceID, string $moduleName): bool
    {
        foreach ($this->instancesOf($moduleName) as $id) { if ($id === $instanceID) { return true; } }
        return false;
    }
}
