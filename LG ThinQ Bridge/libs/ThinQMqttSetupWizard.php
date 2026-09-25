<?php

declare(strict_types=1);

/**
 * One-click MQTT setup: broker from LG's route, an LG-signed client certificate, MQTT Client and
 * Client Socket (reused or created), the TLS material in the socket, the Bridge connected to the
 * MQTT Client and the client ID registered with LG.
 */
final class ThinQMqttSetupWizard
{
    private const FILE_PROPERTIES = ['CertificateFile', 'ClientCertificateFile', 'LocalCert', 'LocalCertificate', 'PrivateKeyFile',
        'ClientKeyFile', 'LocalPrivateKey', 'LocalPrivateKeyFile', 'CertificateAuthorityFile', 'CAFile', 'CACertificateFile',
        'RootCertificateFile', 'RootCAFile', 'CACertFile'];

    /** @param Closure $describe debug output of the Bridge's MQTT parents */
    public function __construct(
        private ThinQModuleContext $ctx,
        private string $apiKey,
        private ThinQBridgeConfig $config,
        private ThinQApi $api,
        private Closure $describe
    ) {
    }

    /**
     * Runs the whole setup. Returns what was set up; the module reports it to the user.
     * @return array{clientId: string, host: string, port: int, mqttId: int, ioId: int}
     */
    public function run(): array
    {
        try {
            $broker = $this->broker();
            // The MQTT Client first: its ClientID becomes the certificate CN, so the order matters.
            $mqttId = $this->chooseMqttClient();
            $cert = $this->certificate($this->clientIdFor($mqttId));
            $mqttId = $this->mqttClient($mqttId, $broker['host'], $cert);
            $ioId = $this->clientSocket($mqttId, $broker['host']);
            $this->configureSocket($ioId, $broker, $cert);
            $this->connectBridge($mqttId);
            $this->registerClient($cert['cn']);
            return ['clientId' => $cert['cn'], 'host' => $broker['host'], 'port' => $broker['port'], 'mqttId' => $mqttId, 'ioId' => $ioId];
        } catch (\Throwable $e) {
            $this->ctx->debug('UISetupMqttConnection', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Broker from LG's route (mqttServer, else webSocketServer), name resolved.
     * @return array{host: string, port: int, tls: bool, ca: string}
     */
    private function broker(): array
    {
        $host = '';
        $port = 0;
        $tls = true;
        $ca = '';
        try {
            $route = $this->api->route();
            $mqtt = $route['mqttServer'] ?? ($route['mqtt'] ?? null);
            $url = is_string($mqtt) ? $mqtt : (is_array($mqtt) ? (string)($mqtt['url'] ?? ($mqtt['endpoint'] ?? ($mqtt['server'] ?? ''))) : '');
            $ca = (string)($route['certificateAuthority'] ?? ($route['caCertificate'] ?? ''));
            $viaWebSocket = false;
            if ($url === '' && is_string($route['webSocketServer'] ?? null) && stripos($route['webSocketServer'], 'wss://') === 0) {
                $url = $route['webSocketServer'];
                $viaWebSocket = true;
            }
            $parts = $url !== '' ? @parse_url($url) : false;
            if (is_array($parts)) {
                $host = (string)($parts['host'] ?? '');
                $port = $viaWebSocket ? 8883 : (int)($parts['port'] ?? 0);
                $tls = strtolower((string)($parts['scheme'] ?? '')) !== 'mqtt';
            }
        } catch (\Throwable $e) {
            $this->ctx->debug('Route', 'Route call failed: ' . $e->getMessage());
        }
        if ($host === '') {
            throw new \RuntimeException($this->ctx->t('Broker host could not be determined from API data.'));
        }
        $port = $port > 0 ? $port : ($tls ? 8883 : 1883);
        $this->ctx->debug('Route', 'MQTT: host=' . $host . ' port=' . $port . ' tls=' . ($tls ? 'true' : 'false') . ' caLen=' . strlen($ca));
        $ip = @gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException(sprintf($this->ctx->t("Hostname '%s' could not be resolved."), $host));
        }
        return ['host' => $host, 'port' => $port, 'tls' => $tls, 'ca' => $ca];
    }

    /** @return array{cn:string, cert:string, key:string, public:string, csr:string, subscriptions:mixed} */
    private function certificate(string $cn): array
    {
        $api = new ThinQApi(new ThinQHttpClient($this->ctx, $this->config->withClientId($cn), $this->apiKey));
        return (new ThinQCertificateManager($this->ctx->instanceId))->requestLGSignedCert($api, $cn);
    }

    /** The configured MQTT Client, else the Bridge's parent, else the one marked for this Bridge; 0 = create one. */
    private function chooseMqttClient(): int
    {
        if (!in_array(ThinQMqttInstances::MQTT_CLIENT_GUID, (array)@IPS_GetModuleList(), true)) {
            throw new \RuntimeException($this->ctx->t("Module 'MQTT Client' not found."));
        }
        foreach ([$this->ctx->propertyInteger('MQTTClientID'), ThinQMqttInstances::connectionOf($this->ctx->instanceId)] as $id) {
            if (ThinQMqttInstances::isMqttClient($id)) {
                return $id;
            }
        }
        $iid = (string)$this->ctx->instanceId;
        return ThinQMqttInstances::byIdent(@IPS_GetInstanceListByModuleID(ThinQMqttInstances::MQTT_CLIENT_GUID), ['LGThinQMQTT' . $iid, 'LGThinQ.MQTT.' . $iid]);
    }

    /**
     * The client ID for the chosen MQTT Client: its own, else the Bridge's, else a new one; never one
     * another MQTT Client instance uses (AWS IoT drops a connection when the same ID connects twice).
     */
    private function clientIdFor(int $mqttId): string
    {
        $taken = [];
        foreach (@IPS_GetInstanceListByModuleID(ThinQMqttInstances::MQTT_CLIENT_GUID) as $id) {
            if ($id !== $mqttId) {
                $taken[] = trim((string)(ThinQMqttInstances::config($id)['ClientID'] ?? ''));
            }
        }
        $own = $mqttId > 0 ? (string)(ThinQMqttInstances::config($mqttId)['ClientID'] ?? '') : '';
        foreach ([$own, $this->config->clientId] as $candidate) {
            $cn = ThinQClientId::sanitize($candidate);
            if ($cn !== '' && !in_array($cn, $taken, true)) {
                return $cn;
            }
        }
        do {
            $cn = ThinQClientId::generate();
        } while (in_array($cn, $taken, true));
        return $cn;
    }

    /**
     * Creates the MQTT Client if $id is 0 and configures it for the certificate's CN and topics.
     * @param array{cn: string, subscriptions: mixed} $cert
     */
    private function mqttClient(int $id, string $host, array $cert): int
    {
        if ($id === 0) {
            $id = IPS_CreateInstance(ThinQMqttInstances::MQTT_CLIENT_GUID);
            IPS_SetName($id, 'LGThinQ MQTT Client (' . $host . ')');
            @IPS_SetIdent($id, 'LGThinQMQTT' . $this->ctx->instanceId);
        }
        ThinQMqttInstances::set($id, 'ClientID', $cert['cn']);
        ThinQMqttInstances::setFirst($id, ['UserName', 'Username'], '');
        ThinQMqttInstances::set($id, 'Password', '');
        ThinQMqttInstances::setFirst($id, ['KeepAliveInterval', 'KeepAlive'], 60);
        $subscriptions = array_map(static fn(string $topic): array => ['Topic' => $topic, 'QoS' => 0], $this->topics($cert));
        if (!ThinQMqttInstances::setList($id, 'Subscriptions', $subscriptions)) {
            ThinQMqttInstances::setList($id, 'Subscribe', $subscriptions);
        }
        IPS_ApplyChanges($id);
        return $id;
    }

    /**
     * The topics LG named in the certificate answer (a list of strings), else app/clients/<CN>/push.
     * @param array{cn: string, subscriptions: mixed} $cert
     * @return array<int, string>
     */
    private function topics(array $cert): array
    {
        $topics = is_array($cert['subscriptions']) ? array_values(array_filter($cert['subscriptions'], static fn($t): bool => is_string($t) && $t !== '')) : [];
        return $topics !== [] ? $topics : ['app/clients/' . $cert['cn'] . '/push'];
    }

    /** The MQTT Client's Client Socket: its parent, a marked one, or a new one. */
    private function clientSocket(int $mqttId, string $host): int
    {
        $ioId = ThinQMqttInstances::connectionOf($mqttId);
        for ($i = 0; $i < 20 && $ioId === 0; $i++) {
            IPS_Sleep(100);
            $ioId = ThinQMqttInstances::connectionOf($mqttId);
        }
        if ($ioId > 0) {
            return $ioId;
        }
        $iid = (string)$this->ctx->instanceId;
        $ioId = ThinQMqttInstances::byIdent(ThinQMqttInstances::instancesOf('Client Socket'), ['LGThinQIO' . $iid, 'LGThinQ.IO.' . $iid]);
        if ($ioId === 0) {
            $guid = ThinQMqttInstances::moduleGuidByName('Client Socket');
            if ($guid === null) {
                throw new \RuntimeException($this->ctx->t("Module 'Client Socket' not found."));
            }
            $ioId = IPS_CreateInstance($guid);
            IPS_SetName($ioId, 'LGThinQ MQTT Client Socket (' . $host . ')');
            @IPS_SetIdent($ioId, 'LGThinQIO' . $iid);
        }
        IPS_ConnectInstance($mqttId, $ioId);
        IPS_Sleep(100);
        return $ioId;
    }

    /**
     * Host, TLS and the certificate material (inline, base64) for the Client Socket, then open it.
     * @param array{host: string, port: int, tls: bool, ca: string} $broker
     * @param array{cert: string, key: string} $cert
     */
    private function configureSocket(int $ioId, array $broker, array $cert): void
    {
        if (!ThinQMqttInstances::isInstanceOf($ioId, 'Client Socket')) {
            $this->ctx->debug('MQTT', 'Warning: Parent #' . $ioId . ' is not a Client Socket');
            return;
        }
        ThinQMqttInstances::set($ioId, 'Open', false);
        ThinQMqttInstances::set($ioId, 'Host', $broker['host']);
        ThinQMqttInstances::set($ioId, 'Port', $broker['port']);
        ThinQMqttInstances::setFirst($ioId, ['UseSSL', 'EnableSSL'], $broker['tls']);
        ThinQMqttInstances::set($ioId, 'VerifyPeer', true);
        ThinQMqttInstances::set($ioId, 'VerifyHost', true);

        $manager = new ThinQCertificateManager($this->ctx->instanceId);
        $certPem = $manager->ensureCertificatePEM($cert['cert']);
        $keyPem = $manager->ensurePrivateKeyPEM($cert['key']);
        $caPem = $this->certificateAuthority($manager, $broker['ca']);
        $chainPem = rtrim($certPem) . "\n" . ($caPem !== '' ? rtrim($caPem) . "\n" : '');

        ThinQMqttInstances::set($ioId, 'UseCertificate', true);
        foreach (self::FILE_PROPERTIES as $property) {
            ThinQMqttInstances::set($ioId, $property, '');
        }
        ThinQMqttInstances::set($ioId, 'Certificate', base64_encode($chainPem));
        ThinQMqttInstances::set($ioId, 'PrivateKey', base64_encode($keyPem));
        ThinQMqttInstances::set($ioId, 'CertificateAuthority', $caPem !== '' ? base64_encode($caPem) : '');
        ThinQMqttInstances::setFirst($ioId, ['Password', 'PassPhrase'], '');
        if ($this->ctx->debugEnabled()) {
            $this->ctx->debug('MQTT', sprintf('Props applied: Certificate(len=%d, parse=%s), PrivateKey(len=%d, parse=%s), PairMatch=%s, CA(%s)',
                strlen($chainPem), @openssl_x509_read($certPem) !== false ? 'OK' : 'FAIL', strlen($keyPem),
                @openssl_pkey_get_private($keyPem) !== false ? 'OK' : 'FAIL', @openssl_x509_check_private_key($certPem, $keyPem) ? 'yes' : 'no',
                $caPem !== '' ? 'len=' . strlen($caPem) : 'none'));
        }
        IPS_ApplyChanges($ioId);
        IPS_SetName($ioId, 'LGThinQ MQTT Client Socket (' . $broker['host'] . ')');
        $this->checkPersisted($ioId, $caPem !== '');
        ThinQMqttInstances::set($ioId, 'Open', true);
        IPS_ApplyChanges($ioId);
        if ($this->ctx->debugEnabled()) {
            ($this->describe)();
        }
    }

    /** CA for the broker: the one from the route if valid, else Amazon Root CA 1 (AWS IoT), else none. */
    private function certificateAuthority(ThinQCertificateManager $manager, string $routeCa): string
    {
        if ($routeCa !== '') {
            $pem = $manager->ensureCertificatePEM($routeCa);
            if (@openssl_x509_read($pem) !== false) {
                return $pem;
            }
            $this->ctx->debug('MQTT', 'Route-provided CA invalid, length=' . strlen($routeCa));
        }
        $aws = $manager->downloadAmazonRootCA1();
        if (str_contains($aws, '-----BEGIN CERTIFICATE-----')) {
            $pem = $manager->ensureCertificatePEM($aws);
            if (@openssl_x509_read($pem) !== false) {
                $this->ctx->debug('MQTT', 'CA fallback: Amazon Root CA 1 applied (len=' . strlen($pem) . ')');
                return $pem;
            }
        }
        return '';
    }

    /** Reads the certificate material back from the socket; a mismatch goes to the debug output. */
    private function checkPersisted(int $ioId, bool $haveCa): void
    {
        $config = ThinQMqttInstances::config($ioId);
        $pem = static function (string $value): string {
            $value = trim($value);
            $bin = str_starts_with($value, '-----BEGIN') ? $value : base64_decode($value, true);
            return is_string($bin) ? $bin : '';
        };
        $okCert = @openssl_x509_read($pem((string)($config['Certificate'] ?? ''))) !== false;
        $okKey = @openssl_pkey_get_private($pem((string)($config['PrivateKey'] ?? ''))) !== false;
        $okCa = !$haveCa || @openssl_x509_read($pem((string)($config['CertificateAuthority'] ?? ''))) !== false;
        if (!$okCert || !$okKey || !$okCa) {
            $this->ctx->debug('MQTT', sprintf('Inline PEM persistence check failed (cert=%s, key=%s, ca=%s). Please verify your Symcon version supports inline certificate properties.',
                $okCert ? 'ok' : 'fail', $okKey ? 'ok' : 'fail', $okCa ? 'ok' : 'fail'));
        }
    }

    private function connectBridge(int $mqttId): void
    {
        IPS_ConnectInstance($this->ctx->instanceId, $mqttId);
        IPS_SetProperty($this->ctx->instanceId, 'UseMQTT', true);
        IPS_SetProperty($this->ctx->instanceId, 'MQTTClientID', $mqttId);
        // The template follows the client ID; a concrete topic of an earlier client would drop every push.
        IPS_SetProperty($this->ctx->instanceId, 'MQTTTopicFilter', ThinQBridgeConfig::DEFAULT_TOPIC);
        IPS_ApplyChanges($this->ctx->instanceId);
    }

    /** Registers the final client ID with LG (idempotent); a failure only goes to the debug output. */
    private function registerClient(string $cn): void
    {
        try {
            (new ThinQApi(new ThinQHttpClient($this->ctx, $this->config->withClientId($cn), $this->apiKey)))->registerClient();
        } catch (\Throwable $e) {
            $this->ctx->debug('UISetupMqttConnection', 'Register client ignored: ' . $e->getMessage());
        }
    }
}
