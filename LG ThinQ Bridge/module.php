<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ThinQModuleTrait.php';
require_once __DIR__ . '/libs/ThinQHelpers.php';
require_once __DIR__ . '/libs/ThinQConfig.php';
require_once __DIR__ . '/libs/ThinQDeviceRepository.php';
require_once __DIR__ . '/libs/ThinQEventSubscriptionRepository.php';
require_once __DIR__ . '/libs/ThinQRedactor.php';
require_once __DIR__ . '/libs/ThinQHttpClient.php';
require_once __DIR__ . '/libs/ThinQEventManager.php';
require_once __DIR__ . '/libs/ThinQEventPipeline.php';
require_once __DIR__ . '/libs/ThinQMqttRouter.php';
require_once __DIR__ . '/libs/ThinQCertificateManager.php';
require_once __DIR__ . '/libs/ThinQMqttSetupWizard.php';
require_once __DIR__ . '/libs/ThinQMqttCertBuilder.php';

class LGThinQBridge extends IPSModule
{
    use ThinQModuleTrait;

    public const API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3';
    private const DATA_FLOW_GUID = '{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}';
    private const CHILD_INTERFACE_GUID = '{5E9D1B64-0F44-4F21-9D74-09C5BB90FB2F}';
    private const MQTT_MODULE_GUID = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';
    private const DEVICE_MODULE_GUID = '{B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}';

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
        // Initialize default ClientID attribute on first run (without modifying properties)
        $this->ensureDefaultClientID();
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
                } elseif ($el['name'] === 'CountryCode') {
                    $el['value'] = (string)$this->ReadPropertyString('CountryCode');
                }
            }
            unset($el);
        }

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Initialize a default ClientID attribute on first run (without modifying properties).
     * The user can override this via the property in the configuration form.
     */
    private function ensureDefaultClientID(): void
    {
        $propCID = trim((string)$this->ReadPropertyString('ClientID'));
        $attrCID = trim((string)$this->ReadAttributeString('ClientID'));
        if ($propCID === '' && $attrCID === '') {
            try {
                $rand5 = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            } catch (\Throwable $e) {
                $rand5 = str_pad((string)mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
            }
            $this->WriteAttributeString('ClientID', 'Symcon' . $rand5);
        }
    }

    public function ForwardData($JSONString)
    {
        $this->ensureBooted();
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
        $this->SendDebug('ForwardData', trim($action . ' ' . (string)($buffer['DeviceID'] ?? '')), 0);
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
                    $ok = $this->eventManager->subscribe($deviceId, true);
                    return json_encode(['success' => $ok], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                case 'GetEnergyProfile':
                    $deviceId = (string)($buffer['DeviceID'] ?? '');
                    if ($deviceId === '') {
                        throw new Exception('DeviceID missing');
                    }
                    $energyProfile = $this->httpClient->request('GET', 'devices/energy/' . rawurlencode($deviceId) . '/profile');
                    return json_encode(['success' => true, 'energyProfile' => $energyProfile], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                case 'GetEnergyUsage':
                    $deviceId = (string)($buffer['DeviceID'] ?? '');
                    $property = (string)($buffer['Property'] ?? '');
                    $period = (string)($buffer['Period'] ?? '');
                    $startDate = (string)($buffer['StartDate'] ?? '');
                    $endDate = (string)($buffer['EndDate'] ?? '');
                    if ($deviceId === '' || $property === '' || $period === '' || $startDate === '' || $endDate === '') {
                        throw new Exception('GetEnergyUsage: missing parameters');
                    }
                    $qs = http_build_query(['property' => $property, 'period' => $period, 'startDate' => $startDate, 'endDate' => $endDate]);
                    $energyData = $this->httpClient->request('GET', 'devices/energy/' . rawurlencode($deviceId) . '/usage?' . $qs);
                    return json_encode(['success' => true, 'energyData' => $energyData], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            $devices = $this->fetchDevices();
            $this->deviceRepository->saveAll($devices);
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
                if ($this->trySubscribeDevice($deviceId, true, true, true)['ok']) {
                    $ok++;
                }
            }

            $this->NotifyUser(sprintf($this->t('SubscribeAll: %d/%d devices subscribed'), $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('SubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser($this->t('SubscribeAll failed') . ': ' . $e->getMessage());
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
            $this->WriteAttributeInteger('PushRegisteredAt', 0);
            $this->WriteAttributeString('PushDeviceSubs', '{}');
            $this->NotifyUser(sprintf($this->t('UnsubscribeAll: %d/%d devices unsubscribed'), $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('UnsubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser($this->t('UnsubscribeAll failed') . ': ' . $e->getMessage());
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
                if ($this->eventManager->subscribe((string)$deviceId, true)) {
                    $ok++;
                }
            }
            $this->NotifyUser(sprintf($this->t('RenewAll: %d/%d event subscriptions renewed'), $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('RenewAll', $e->getMessage(), 0);
            $this->NotifyUser($this->t('RenewAll failed') . ': ' . $e->getMessage());
        }
    }

    public function RenewEvents(): void
    {
        $this->ensureBooted();
        // Subscriptions follow the Device instances of this Bridge.
        $deviceIds = $this->childDeviceIds();
        try {
            $this->eventManager->renewExpiring($deviceIds);
        } catch (Throwable $e) {
            $this->SendDebug('RenewEvents', $e->getMessage(), 0);
        }
        // A device whose own subscription never succeeded gets one now.
        foreach (array_diff($deviceIds, array_map('strval', array_keys($this->subscriptionRepository->getAll()))) as $deviceId) {
            $this->trySubscribeDevice($deviceId, true, true);
        }

        // Push subscriptions do not expire; re-assert them once a day as a safety net.
        if ($deviceIds !== [] && ThinQClock::now() - (int)$this->ReadAttributeInteger('PushRegisteredAt') >= 86400) {
            $this->registerPushClient(true);
            foreach ($deviceIds as $deviceId) {
                try {
                    $this->subscribePush($deviceId, true);
                } catch (Throwable $e) {
                    $this->SendDebug('RenewEvents', 'Push renew failed for ' . $deviceId . ': ' . $e->getMessage(), 0);
                }
            }
        }
    }

    /** @return array<int, string> DeviceIDs of the LG ThinQ Device instances connected to this Bridge */
    private function childDeviceIds(): array
    {
        $ids = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID) as $id) {
            $info = @IPS_GetInstance($id);
            if (is_array($info) && (int)($info['ConnectionID'] ?? 0) === $this->InstanceID) {
                $deviceId = trim((string)@IPS_GetProperty($id, 'DeviceID'));
                if ($deviceId !== '') {
                    $ids[] = $deviceId;
                }
            }
        }
        return array_values(array_unique($ids));
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
    private function trySubscribeDevice(string $DeviceID, bool $Push, bool $Event, bool $force = false): array
    {
        $ok = true;
        $errors = [];
        if ($Event) {
            $success = $this->eventManager->subscribe($DeviceID, $force);
            $this->SendDebug('Event Subscribe', ($success ? 'OK' : 'FAILED') . ' for ' . $DeviceID, 0);
            if (!$success) {
                $errors[] = 'Event subscription failed';
                $ok = false;
            }
        }
        if ($Push) {
            $this->registerPushClient($force);
            try {
                $this->subscribePush($DeviceID, $force);
            } catch (Throwable $e) {
                $this->SendDebug('Push Subscribe', $e->getMessage(), 0);
                $errors[] = 'Push subscribe failed: ' . $e->getMessage();
                $ok = false;
            }
        }
        return ['ok' => $ok, 'errors' => $errors];
    }

    /** POST push/devices registers this client as push recipient; repeated at most once per cooldown unless forced. */
    private function registerPushClient(bool $force): void
    {
        $at = (int)$this->ReadAttributeInteger('PushRegisteredAt');
        if (!$force && $at > 0 && ThinQClock::now() - $at < $this->pushCooldownSeconds()) {
            return;
        }
        try {
            $this->httpClient->request('POST', 'push/devices');
            $this->SendDebug('Push Subscribe', 'push/devices OK', 0);
        } catch (Throwable $e) {
            if (!self::isAlreadySubscribed($e)) {
                $this->SendDebug('Push Subscribe', 'push/devices error: ' . $e->getMessage(), 0);
                return;
            }
            $this->SendDebug('Push Subscribe', 'push/devices already registered (OK)', 0);
        }
        $this->WriteAttributeInteger('PushRegisteredAt', ThinQClock::now());
    }

    /** POST push/{id}/subscribe, at most once per cooldown and client ID unless forced; throws on real errors. */
    private function subscribePush(string $deviceId, bool $force): void
    {
        $subs = $this->pushSubscriptions();
        $entry = $subs[$deviceId] ?? null;
        if (!$force && is_array($entry) && (string)($entry['clientId'] ?? '') === $this->config->clientId
            && ThinQClock::now() - (int)($entry['at'] ?? 0) < $this->pushCooldownSeconds()) {
            return;
        }
        try {
            $this->httpClient->request('POST', 'push/' . rawurlencode($deviceId) . '/subscribe');
            $this->SendDebug('Push Subscribe', 'OK for ' . $deviceId, 0);
        } catch (Throwable $e) {
            if (!self::isAlreadySubscribed($e)) {
                throw $e;
            }
            $this->SendDebug('Push Subscribe', 'Already subscribed (OK) for ' . $deviceId, 0);
        }
        $subs[$deviceId] = ['at' => ThinQClock::now(), 'clientId' => $this->config->clientId];
        $this->WriteAttributeString('PushDeviceSubs', (string)json_encode($subs, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, array<string, mixed>> deviceId => {at, clientId} of the last successful push subscribe */
    private function pushSubscriptions(): array
    {
        $subs = json_decode((string)$this->ReadAttributeString('PushDeviceSubs'), true);
        return is_array($subs) ? $subs : [];
    }

    private function pushCooldownSeconds(): int
    {
        return max(1, (int)$this->ReadPropertyInteger('PushCooldownMin')) * 60;
    }

    /** LG answers a repeated push registration with 4001 (push/devices, spelled "Subscirbed") or 1207 (push/{id}/subscribe). */
    private static function isAlreadySubscribed(Throwable $e): bool
    {
        return ($e instanceof ThinQApiException && in_array($e->apiCode, ['4001', '1207'], true))
            || stripos($e->getMessage(), 'already subscribed') !== false;
    }

    public function UnsubscribeDevice(string $DeviceID, bool $Push = true, bool $Event = true): bool
    {
        $this->ensureBooted();
        $ok = true;
        if ($Event) {
            $ok = $this->eventManager->unsubscribe($DeviceID) && $ok;
        }
        if ($Push) {
            $subs = $this->pushSubscriptions();
            unset($subs[$DeviceID]);
            $this->WriteAttributeString('PushDeviceSubs', (string)json_encode((object)$subs, JSON_UNESCAPED_SLASHES));
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
            throw new Exception('ControlDevice: Invalid JSON payload');
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
        // A short fixed check period; what is due follows from the stored expiry times (ThinQEventManager).
        $errors = $this->config->validate();
        $this->SetTimerInterval('EventRenewTimer', empty($errors) ? ThinQEventManager::CHECK_PERIOD * 1000 : 0);
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
        $parentId = self::connectionOf($this->InstanceID);
        $ioId = $parentId > 0 ? self::connectionOf($parentId) : 0;
        $this->SendDebug('MQTT', 'Bridge #' . $this->InstanceID . ', MQTT parent #' . $parentId . ', IO #' . $ioId, 0);
        foreach (['Parent' => $parentId, 'IO' => $ioId] as $role => $id) {
            if ($id > 0) {
                $this->SendDebug('MQTT', $role . ' ' . self::describeInstance($id), 0);
            }
        }
    }

    private static function connectionOf(int $instanceId): int
    {
        $info = @IPS_GetInstance($instanceId);
        return is_array($info) ? (int)($info['ConnectionID'] ?? 0) : 0;
    }

    /** Module, status and configuration keys of an instance; values only for keys that never hold a secret. */
    private static function describeInstance(int $instanceId): string
    {
        $info = @IPS_GetInstance($instanceId);
        $config = json_decode((string)@IPS_GetConfiguration($instanceId), true);
        $keys = [];
        foreach (is_array($config) ? $config : [] as $key => $value) {
            $keys[] = in_array($key, ['ClientID', 'Host', 'Port', 'Open', 'UseSSL', 'KeepAliveInterval', 'Subscriptions'], true)
                ? $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$key;
        }
        return sprintf('#%d module=%s status=%s config: %s', $instanceId, is_array($info) ? (string)($info['ModuleInfo']['ModuleID'] ?? '') : '',
            is_array($info) ? (string)($info['InstanceStatus'] ?? '') : '', implode(', ', $keys));
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
                $this->SendDebug('Push', 'Meta event ' . $type . ' for ' . $deviceId, 0);
                break;
            case 'DEVICE_PUSH':
                // DEVICE_PUSH can carry status data (e.g. hot water heat pumps send temperature updates via DEVICE_PUSH)
                $report = null;
                if (isset($payload['report']) && is_array($payload['report'])) {
                    $report = $payload['report'];
                } elseif (isset($payload['state']) && is_array($payload['state'])) {
                    $report = $payload['state'];
                } elseif (isset($payload['data']) && is_array($payload['data'])) {
                    $data = $payload['data'];
                    if (isset($data['report']) && is_array($data['report'])) {
                        $report = $data['report'];
                    } elseif (isset($data['state']) && is_array($data['state'])) {
                        $report = $data['state'];
                    } else {
                        // data itself contains status fields directly (e.g. Water Heater temperature push)
                        $report = $data;
                    }
                }
                // Fallback: status fields sent at top-level of DEVICE_PUSH payload (no report/state/data wrapper)
                if ($report === null) {
                    $metaKeys = ['pushType', 'type', 'deviceId', 'device_id', 'pushCode', 'pushStep', 'timestamp', 'messageId'];
                    $statusData = array_diff_key($payload, array_flip($metaKeys));
                    if (!empty($statusData)) {
                        $report = $statusData;
                    }
                }
                if ($report !== null && $deviceId !== '') {
                    $this->SendDebug('Push', 'DEVICE_PUSH status forward for ' . $deviceId, 0);
                    $this->eventPipeline->dispatchEvent($deviceId, $report);
                } else {
                    $this->SendDebug('Push', 'DEVICE_PUSH (no report/state) for ' . $deviceId, 0);
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
            $zipData = (new ThinQMqttCertBuilder(
                $this,
                $this->InstanceID,
                fn() => $this->debugMqttParentInfo(),
                fn() => $this->createBridgeConfig(),
                self::API_KEY
            ))->build();
            return 'data:application/zip;base64,' . base64_encode($zipData);
        } catch (\Throwable $e) {
            $this->SendDebug('UIGenerateMQTTClientCerts', $e->getMessage(), 0);
            return 'data:text/plain,' . rawurlencode($this->t('Error generating certificates') . ': ' . $e->getMessage());
        }
    }
    // --- UI: One-click MQTT setup (create certs, MQTT Client, Client Socket) ---
    public function UISetupMqttConnection(): void
    {
        $this->ensureBooted();
        (new ThinQMqttSetupWizard(
            $this,
            $this->InstanceID,
            self::API_KEY,
            $this->config,
            $this->httpClient,
            fn() => $this->debugMqttParentInfo()
        ))->run();
    }
}
