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
            $cert = $this->certificate();
            $mqttId = $this->mqttClient($broker['host'], $cert['cn']);
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
    private function certificate(): array
    {
        $subjectCN = ThinQClientId::sanitize($this->config->clientId);
        if ($subjectCN === '') {
            $subjectCN = ThinQClientId::generate();
        }
        $api = new ThinQApi(new ThinQHttpClient($this->ctx, $this->config->withClientId($subjectCN), $this->apiKey));
        return (new ThinQCertificateManager($this->ctx->instanceId))->requestLGSignedCert($api, $subjectCN);
    }

    /** The MQTT Client to use (configured, marked by ident, same ClientID, or new), configured for $cn. */
    private function mqttClient(string $host, string $cn): int
    {
        $guid = ThinQMqttInstances::MQTT_CLIENT_GUID;
        if (!in_array($guid, (array)@IPS_GetModuleList(), true)) {
            throw new \RuntimeException($this->ctx->t("Module 'MQTT Client' not found."));
        }
        $candidates = @IPS_GetInstanceListByModuleID($guid);
        $id = 0;
        $configured = $this->ctx->propertyInteger('MQTTClientID');
        if ($configured > 0 && @IPS_InstanceExists($configured)) {
            $info = @IPS_GetInstance($configured);
            if (is_array($info) && isset($info['ModuleID']) && (string)$info['ModuleID'] === $guid) {
                $id = $configured;
            }
        }
        $iid = (string)$this->ctx->instanceId;
        if ($id === 0) {
            $id = ThinQMqttInstances::byIdent($candidates, ['LGThinQMQTT' . $iid, 'LGThinQ.MQTT.' . $iid]);
        }
        if ($id === 0) {
            foreach ($candidates as $candidate) {
                if ((ThinQMqttInstances::config($candidate)['ClientID'] ?? null) === $cn) {
                    $id = $candidate;
                    break;
                }
            }
        }
        if ($id === 0) {
            $id = IPS_CreateInstance($guid);
            IPS_SetName($id, 'LGThinQ MQTT Client (' . $host . ')');
            @IPS_SetIdent($id, 'LGThinQMQTT' . $iid);
        }

        ThinQMqttInstances::set($id, 'ClientID', $cn);
        ThinQMqttInstances::setFirst($id, ['UserName', 'Username'], '');
        ThinQMqttInstances::set($id, 'Password', '');
        ThinQMqttInstances::setFirst($id, ['KeepAliveInterval', 'KeepAlive'], 60);
        $subscriptions = array_map(static fn(string $topic): array => ['Topic' => $topic, 'QoS' => 0], $this->topics($cn));
        if (!ThinQMqttInstances::setList($id, 'Subscriptions', $subscriptions)) {
            ThinQMqttInstances::setList($id, 'Subscribe', $subscriptions);
        }
        IPS_ApplyChanges($id);
        return $id;
    }

    /** @return array<int, string> the push topic of $cn, or the configured filter with {ClientID} filled in */
    private function topics(string $cn): array
    {
        $filter = trim($this->ctx->propertyString('MQTTTopicFilter'));
        if ($filter === '' || $filter === 'app/clients/*/push' || $filter === 'app/clients/*/#') {
            return ['app/clients/' . $cn . '/push'];
        }
        return [ThinQBridgeConfig::expandTopic($filter, $cn)];
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
