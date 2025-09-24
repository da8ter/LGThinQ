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
        $this->RegisterTimer('EventRenewTimer', 0, 'LGTQ_RenewEvents($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
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

            try {
                $this->httpClient->request('POST', 'push/devices');
            } catch (Throwable $e) {
                $this->SendDebug('SubscribeAll Push', $e->getMessage(), 0);
            }

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
            $clientId = ThinQHelpers::generateUUIDv4();
            $this->WriteAttributeString('ClientID', $clientId);
        } elseif ($clientIdProperty !== '' && $clientIdProperty !== $clientIdAttr) {
            $this->WriteAttributeString('ClientID', $clientIdProperty);
            $clientId = $clientIdProperty;
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
