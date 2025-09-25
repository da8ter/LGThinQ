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
        $this->ensureBooted();
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

            $this->NotifyUser(sprintf('SubscribeAll: %d/%d Geräte abonniert', $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('SubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser('SubscribeAll fehlgeschlagen: ' . $e->getMessage());
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
            $this->NotifyUser(sprintf('UnsubscribeAll: %d/%d Geräte abgemeldet', $ok, $total));
        } catch (Throwable $e) {
            $this->SendDebug('UnsubscribeAll', $e->getMessage(), 0);
            $this->NotifyUser('UnsubscribeAll fehlgeschlagen: ' . $e->getMessage());
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
            if (!$success) {
                $errors[] = 'Event subscription failed';
            }
            $ok = $success && $ok;
        }
        if ($Push) {
            try {
                $this->httpClient->request('POST', 'push/' . rawurlencode($DeviceID) . '/subscribe');
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
            return 'data:text/plain,' . rawurlencode('Fehler beim Erstellen der Zertifikate: ' . $e->getMessage());
        }
    }

    private function buildMQTTClientCertsZip(): string
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('OpenSSL wird nicht unterstützt (openssl_* Funktionen fehlen)');
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
            throw new \RuntimeException('Konnte temporäre OpenSSL-Konfiguration nicht erstellen');
        }
        fwrite($cfgHandle, $strCONFIG);
        fclose($cfgHandle);
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
                throw new \RuntimeException('openssl_pkey_new fehlgeschlagen');
            }

            $pkPrivate = '';
            if (!openssl_pkey_export($pkGenerate, $pkPrivate, null, $config)) { // unverschlüsselt
                throw new \RuntimeException('openssl_pkey_export fehlgeschlagen');
            }
            $pkDetails = openssl_pkey_get_details($pkGenerate);
            if ($pkDetails === false || !isset($pkDetails['key'])) {
                throw new \RuntimeException('openssl_pkey_get_details fehlgeschlagen');
            }
            $pkPublic = (string)$pkDetails['key'];

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
                    throw new \RuntimeException('Konnte temporäre OpenSSL-Konfiguration (Fallback) nicht erstellen');
                }
                fwrite($cfgHandle2, $strCONFIG2);
                fclose($cfgHandle2);

                $config2 = [
                    'config' => $cfgPath,
                    'digest_alg' => 'sha256'
                ];
                $csr = openssl_csr_new($dn, $pkGenerate, $config2);
                if ($csr === false) {
                    throw new \RuntimeException('openssl_csr_new fehlgeschlagen');
                }
                // Use config2 for signing as well
                $config = $config2;
            }
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
            throw new \RuntimeException('LG certificate request returned no certificatePem');
        }
        $certOut = $lgCertOut;
        $csrOut = '';
        if (!openssl_csr_export($csr, $csrOut)) {
            // Non-fatal, but log
            $csrOut = '';
        }

        // Create ZIP
        $zip = new \ZipArchive();
        $tmpZip = tempnam(sys_get_temp_dir(), 'lgtq_mqtt_zip_');
        if ($tmpZip === false) {
            throw new \RuntimeException('Konnte temporäre ZIP-Datei nicht erstellen');
        }
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);
            throw new \RuntimeException('Konnte ZIP nicht öffnen');
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
        $zip->addFromString('00_meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

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
            throw new \RuntimeException('Konnte ZIP-Inhalt nicht lesen');
        }
        @unlink($cfgPath);
        return $data;
    }
}
