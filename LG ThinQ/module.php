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

class LGThinQ extends IPSModule
{
    public const API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3';
    private const DATA_FLOW_GUID = '{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}';
    private const CHILD_INTERFACE_GUID = '{5E9D1B64-0F44-4F21-9D74-09C5BB90FB2F}';
    private const MQTT_MODULE_GUID = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';
    private const CLIENT_SOCKET_MODULE_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';

    private ?ThinQBridgeConfig $config = null;
    private ?ThinQHttpClient $httpClient = null;
    private ?ThinQDeviceRepository $deviceRepository = null;
    private ?ThinQEventSubscriptionRepository $subscriptionRepository = null;
    private ?ThinQEventManager $eventManager = null;
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
        $this->RegisterPropertyString('MQTTTopicFilter', 'app/clients/*/push');
        $this->RegisterPropertyBoolean('IgnoreRetained', true);
        $this->RegisterPropertyInteger('EventTTLHrs', 24);
        $this->RegisterPropertyInteger('EventRenewLeadMin', 5);

        $this->RegisterAttributeString('ClientID', '');
        $this->RegisterAttributeString('Devices', '[]');
        $this->RegisterAttributeString('EventSubscriptions', '{}');
        $this->RegisterAttributeString('MqttCredentials', '{}');

        $this->RegisterTimer('EventRenewTimer', 0, 'LGTQ_RenewEvents($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->bootServices();

        if ($this->ensureMqttInfrastructure()) {
            return;
        }

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
                    $ok = $this->SubscribeDevice($deviceId, $withPush, $withEvent);
                    return json_encode(['success' => $ok], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        try {
            $devices = $this->fetchDevices();
            $count = count($devices);
            $this->NotifyUser('Verbindung OK. Geräte: ' . $count);
            $this->SetStatus(102);
            echo 'Verbindung OK. Geräte: ' . $count;
        } catch (Throwable $e) {
            $this->SetStatus(104);
            $this->SendDebug('TestConnection', $e->getMessage(), 0);
            $this->NotifyUser('Verbindung fehlgeschlagen: ' . $e->getMessage());
            echo 'Verbindung fehlgeschlagen: ' . $e->getMessage();
        }
    }

    public function SyncDevices(): void
    {
        try {
            $devices = $this->fetchDevices();
            $this->deviceRepository->saveAll($devices);
            $this->NotifyUser('Geräteliste aktualisiert: ' . count($devices) . ' Geräte.');
        } catch (Throwable $e) {
            $this->SendDebug('SyncDevices', $e->getMessage(), 0);
            $this->NotifyUser('Sync fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function Update(): void
    {
        try {
            $devices = $this->fetchDevices();
            $this->deviceRepository->saveAll($devices);
        } catch (Throwable $e) {
            $this->SendDebug('Update', $e->getMessage(), 0);
        }
    }

    public function SubscribeAll(): void
    {
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
            $this->registerPushClient(true);
            $this->NotifyUser(sprintf('SubscribeAll: %d/%d Geräte abonniert', $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('SubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser('SubscribeAll fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function UnsubscribeAll(): void
    {
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
            $this->clearCachedMqttCredentials();
            $this->subscriptionRepository->saveAll([]);
            $this->NotifyUser(sprintf('UnsubscribeAll: %d/%d Geräte abgemeldet', $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('UnsubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser('UnsubscribeAll fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function RenewAll(): void
    {
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
            $this->NotifyUser(sprintf('RenewAll: %d/%d Event-Abos erneuert', $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('RenewAll', $e->getMessage(), 0);
            $this->NotifyUser('RenewAll fehlgeschlagen: ' . $e->getMessage());
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
        $ok = true;
        if ($Event) {
            $ok = $this->eventManager->subscribe($DeviceID) && $ok;
        }
        if ($Push) {
            try {
                $this->httpClient->request('POST', 'push/' . rawurlencode($DeviceID) . '/subscribe');
            } catch (Throwable $e) {
                $this->SendDebug('Push Subscribe', $e->getMessage(), 0);
                $ok = false;
            }
        }
        return $ok;
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
        $this->config = ThinQBridgeConfig::fromModule($this);
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

    private function ensureMqttInfrastructure(): bool
    {
        if ($this->config === null || !$this->config->useMqtt) {
            return false;
        }

        $mqttInstanceId = $this->config->mqttClientId;
        if (!$this->isInstanceOfModule($mqttInstanceId, self::MQTT_MODULE_GUID)) {
            $created = $this->createMqttClientInstance();
            if ($created > 0) {
                $this->SendDebug('MQTT', 'Created MQTT client instance #' . $created, 0);
                if (@IPS_SetProperty($this->InstanceID, 'MQTTClientID', $created)) {
                    @IPS_ApplyChanges($this->InstanceID);
                    return true;
                }
            }
            return false;
        }

        $credentials = $this->registerPushClient(false);
        if (is_array($credentials)) {
            $topic = (string)($credentials['topic'] ?? '');
            if ($topic !== '' && $topic !== (string)$this->ReadPropertyString('MQTTTopicFilter')) {
                if (@IPS_SetProperty($this->InstanceID, 'MQTTTopicFilter', $topic)) {
                    @IPS_ApplyChanges($this->InstanceID);
                    return true;
                }
            }
        }

        $this->configureMqttClient($mqttInstanceId, $credentials);
        $this->ensureModuleConnectedToMqtt($mqttInstanceId);

        return false;
    }

    private function createMqttClientInstance(): int
    {
        $instanceId = @IPS_CreateInstance(self::MQTT_MODULE_GUID);
        if ($instanceId <= 0) {
            $this->SendDebug('MQTT', 'Unable to create MQTT client instance', 0);
            return 0;
        }
        $name = 'LG ThinQ MQTT Client';
        @IPS_SetName($instanceId, $name);
        $parent = @IPS_GetParent($this->InstanceID);
        if (is_int($parent) && $parent > 0) {
            @IPS_SetParent($instanceId, $parent);
        }
        $this->SendDebug('MQTT', 'MQTT client instance created with ID ' . $instanceId, 0);
        return $instanceId;
    }

    private function ensureModuleConnectedToMqtt(int $mqttInstanceId): void
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        $parentId = is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
        if ($parentId === $mqttInstanceId) {
            return;
        }
        if ($mqttInstanceId > 0 && @IPS_InstanceExists($mqttInstanceId)) {
            @IPS_ConnectInstance($this->InstanceID, $mqttInstanceId);
        }
    }

    /**
     * @param array<string, mixed>|null $credentials
     */
    private function configureMqttClient(int $mqttInstanceId, ?array $credentials): void
    {
        if ($mqttInstanceId <= 0 || !@IPS_InstanceExists($mqttInstanceId)) {
            return;
        }

        $socketId = $this->ensureMqttClientSocket($mqttInstanceId, $credentials);

        $availableProps = $this->getInstancePropertyNames($mqttInstanceId);
        $config = $this->readInstanceConfiguration($mqttInstanceId);
        $changed = false;

        $clientId = (string)($credentials['clientId'] ?? $this->config->clientId ?? '');
        if ($clientId !== '') {
            $changed = $this->setInstanceProperty($mqttInstanceId, ['ClientID', 'ClientId'], $clientId, $availableProps, $config) || $changed;
        }
        if (isset($credentials['username'])) {
            $changed = $this->setInstanceProperty($mqttInstanceId, ['Username', 'User'], (string)$credentials['username'], $availableProps, $config) || $changed;
        }
        if (isset($credentials['password'])) {
            $changed = $this->setInstanceProperty($mqttInstanceId, ['Password', 'Pass'], (string)$credentials['password'], $availableProps, $config) || $changed;
        }
        if (isset($credentials['keepAlive'])) {
            $changed = $this->setInstanceProperty($mqttInstanceId, ['KeepAlive'], (int)$credentials['keepAlive'], $availableProps, $config) || $changed;
        }
        if (isset($credentials['cleanSession'])) {
            $changed = $this->setInstanceProperty($mqttInstanceId, ['CleanSession'], (bool)$credentials['cleanSession'], $availableProps, $config) || $changed;
        } else {
            $changed = $this->setInstanceProperty($mqttInstanceId, ['CleanSession'], false, $availableProps, $config) || $changed;
        }

        if ($changed) {
            @IPS_ApplyChanges($mqttInstanceId);
        }

        if ($socketId > 0 && @IPS_InstanceExists($socketId)) {
            @IPS_ConnectInstance($mqttInstanceId, $socketId);
        }
    }

    /**
     * @param array<string, mixed>|null $credentials
     */
    private function ensureMqttClientSocket(int $mqttInstanceId, ?array $credentials): int
    {
        $instance = @IPS_GetInstance($mqttInstanceId);
        $socketId = is_array($instance) ? (int)($instance['ConnectionID'] ?? 0) : 0;
        if (!$this->isInstanceOfModule($socketId, self::CLIENT_SOCKET_MODULE_GUID)) {
            $socketId = @IPS_CreateInstance(self::CLIENT_SOCKET_MODULE_GUID);
            if ($socketId <= 0) {
                $this->SendDebug('MQTT', 'Unable to create client socket for MQTT', 0);
                return 0;
            }
            $parent = @IPS_GetParent($this->InstanceID);
            if (is_int($parent) && $parent > 0) {
                @IPS_SetParent($socketId, $parent);
            }
            @IPS_SetName($socketId, 'LG ThinQ MQTT Socket');
        }

        if ($socketId <= 0) {
            return 0;
        }

        $host = '';
        $port = 0;
        $secure = true;
        if (is_array($credentials)) {
            $host = (string)($credentials['host'] ?? '');
            $port = (int)($credentials['port'] ?? 0);
            if (isset($credentials['secure'])) {
                $secure = (bool)$credentials['secure'];
            }
        }

        $availableProps = $this->getInstancePropertyNames($socketId);
        $config = $this->readInstanceConfiguration($socketId);
        $changed = false;

        if ($host !== '') {
            $changed = $this->setInstanceProperty($socketId, ['Host'], $host, $availableProps, $config) || $changed;
        }
        if ($port > 0) {
            $changed = $this->setInstanceProperty($socketId, ['Port'], $port, $availableProps, $config) || $changed;
        }
        $changed = $this->setInstanceProperty($socketId, ['UseSSL', 'UseTLS'], $secure, $availableProps, $config) || $changed;
        $changed = $this->setInstanceProperty($socketId, ['VerifyCertificate', 'VerifyPeer'], false, $availableProps, $config) || $changed;
        if ($host !== '' && $port > 0) {
            $changed = $this->setInstanceProperty($socketId, ['Open'], true, $availableProps, $config) || $changed;
        }

        if ($changed) {
            @IPS_ApplyChanges($socketId);
        }

        return $socketId;
    }

    private function isInstanceOfModule(int $instanceId, string $moduleGuid): bool
    {
        if ($instanceId <= 0 || !@IPS_InstanceExists($instanceId)) {
            return false;
        }
        $instance = @IPS_GetInstance($instanceId);
        if (!is_array($instance)) {
            return false;
        }
        $info = $instance['ModuleInfo'] ?? null;
        if (!is_array($info)) {
            return false;
        }
        $moduleId = (string)($info['ModuleID'] ?? '');
        return strcasecmp($moduleId, $moduleGuid) === 0;
    }

    private function readInstanceConfiguration(int $instanceId): array
    {
        $json = @IPS_GetConfiguration($instanceId);
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, string>
     */
    private function getInstancePropertyNames(int $instanceId): array
    {
        $form = @IPS_GetConfigurationForm($instanceId);
        if (!is_string($form) || $form === '') {
            return [];
        }
        $decoded = json_decode($form, true);
        if (!is_array($decoded)) {
            return [];
        }
        $names = [];
        if (isset($decoded['elements']) && is_array($decoded['elements'])) {
            $this->collectPropertyNames($decoded['elements'], $names);
        }
        return $names;
    }

    /**
     * @param array<int, mixed> $nodes
     * @param array<int, string> $names
     */
    private function collectPropertyNames(array $nodes, array &$names): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['name'])) {
                $names[] = (string)$node['name'];
            }
            if (isset($node['items']) && is_array($node['items'])) {
                $this->collectPropertyNames($node['items'], $names);
            }
            if (isset($node['columns']) && is_array($node['columns'])) {
                $this->collectPropertyNames($node['columns'], $names);
            }
        }
    }

    /**
     * @param array<int, string> $candidates
     * @param array<int, string> $availableProps
     * @param array<string, mixed> $config
     */
    private function setInstanceProperty(int $instanceId, array $candidates, $value, array $availableProps, array &$config): bool
    {
        foreach ($candidates as $candidate) {
            if (!empty($availableProps) && !in_array($candidate, $availableProps, true)) {
                continue;
            }
            if (array_key_exists($candidate, $config) && $config[$candidate] === $value) {
                return false;
            }
            if (@IPS_SetProperty($instanceId, $candidate, $value)) {
                $config[$candidate] = $value;
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function registerPushClient(bool $forceRefresh): ?array
    {
        $cached = $this->readCachedMqttCredentials();
        if (!$forceRefresh && $this->isValidMqttCredentials($cached)) {
            return $cached;
        }

        if ($this->config->accessToken === '' || $this->config->countryCode === '') {
            return $this->isValidMqttCredentials($cached) ? $cached : null;
        }

        try {
            $response = $this->httpClient->request('POST', 'push/devices');
        } catch (Throwable $e) {
            $this->SendDebug('MQTT Register', $e->getMessage(), 0);
            return $this->isValidMqttCredentials($cached) ? $cached : null;
        }

        $credentials = $this->parseMqttCredentials($response);
        if ($credentials !== null) {
            $this->cacheMqttCredentials($credentials);
            return $credentials;
        }

        return $this->isValidMqttCredentials($cached) ? $cached : null;
    }

    private function clearCachedMqttCredentials(): void
    {
        $this->WriteAttributeString('MqttCredentials', '{}');
    }

    /**
     * @return array<string, mixed>
     */
    private function readCachedMqttCredentials(): array
    {
        $raw = (string)$this->ReadAttributeString('MqttCredentials');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function cacheMqttCredentials(array $credentials): void
    {
        $this->WriteAttributeString('MqttCredentials', json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function isValidMqttCredentials(array $credentials): bool
    {
        if (empty($credentials)) {
            return false;
        }
        $host = (string)($credentials['host'] ?? '');
        $username = (string)($credentials['username'] ?? '');
        $password = (string)($credentials['password'] ?? '');
        return $host !== '' && $username !== '' && $password !== '';
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    private function parseMqttCredentials(array $response): ?array
    {
        if (empty($response)) {
            return null;
        }

        $flat = $this->flattenKeys($response);

        $hostRaw = $this->findFirstString($flat, ['mqttserveruri', 'mqttserverurl', 'mqttserver', 'serveruri', 'server']);
        $host = '';
        $port = 0;
        $secure = true;
        if ($hostRaw !== '') {
            if (preg_match('/^\w+:\/\//', $hostRaw) === 1) {
                $parts = @parse_url($hostRaw);
                if (is_array($parts)) {
                    $host = (string)($parts['host'] ?? '');
                    if (isset($parts['port'])) {
                        $port = (int)$parts['port'];
                    }
                    $scheme = strtolower((string)($parts['scheme'] ?? ''));
                    $secure = in_array($scheme, ['ssl', 'tls', 'mqtts', 'https', 'wss'], true);
                }
            } else {
                $host = $hostRaw;
            }
        }

        $portRaw = $this->findFirstString($flat, ['mqttsslport', 'mqttport', 'port']);
        if ($portRaw !== '' && is_numeric($portRaw)) {
            $port = (int)$portRaw;
        }
        if ($port <= 0) {
            $port = $secure ? 8883 : 1883;
        }

        $username = $this->findFirstString($flat, ['mqttusername', 'username', 'userid', 'user']);
        $password = $this->findFirstString($flat, ['mqttpassword', 'password', 'pass']);
        $clientId = $this->findFirstString($flat, ['mqttclientid', 'clientid']);
        $topic = $this->findFirstString($flat, ['mqtttopic', 'topic', 'topicname']);
        $keepAliveRaw = $this->findFirstString($flat, ['keepalive', 'mqttkeepalive']);
        $keepAlive = is_numeric($keepAliveRaw) ? (int)$keepAliveRaw : null;
        $cleanSessionRaw = $this->findFirstString($flat, ['cleansession']);
        $cleanSession = null;
        if ($cleanSessionRaw !== '') {
            $cleanSession = in_array(strtolower($cleanSessionRaw), ['1', 'true', 'yes'], true);
        }

        if ($host === '' || $username === '' || $password === '') {
            return null;
        }

        $data = [
            'host' => $host,
            'port' => $port,
            'secure' => $secure,
            'username' => $username,
            'password' => $password,
            'clientId' => $clientId !== '' ? $clientId : null,
            'topic' => $topic !== '' ? $topic : null,
        ];
        if ($keepAlive !== null) {
            $data['keepAlive'] = $keepAlive;
        }
        if ($cleanSession !== null) {
            $data['cleanSession'] = $cleanSession;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    private function findFirstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $lookup = strtolower($key);
            if (array_key_exists($lookup, $data)) {
                $value = $data[$lookup];
                if (is_string($value)) {
                    return trim($value);
                }
                if (is_numeric($value)) {
                    return (string)$value;
                }
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function flattenKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $keyName = strtolower((string)$key);
            if (is_array($value)) {
                $nested = $this->flattenKeys($value);
                foreach ($nested as $nKey => $nValue) {
                    if (!array_key_exists($nKey, $result)) {
                        $result[$nKey] = $nValue;
                    }
                }
            } elseif (!array_key_exists($keyName, $result)) {
                $result[$keyName] = $value;
            }
        }
        return $result;
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

    private function NotifyUser(string $message): void
    {
        $this->LogMessage($message, KL_MESSAGE);
    }
}
