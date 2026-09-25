<?php

declare(strict_types=1);

/**
 * ThinQMqttSetupWizard
 *
 * Extracted from LG ThinQ Bridge/module.php.
 * Orchestrates the full MQTT connection setup: cert generation, broker discovery,
 * IO/MQTT instance creation and configuration.
 */
class ThinQMqttSetupWizard
{
    /** @var callable */
    private $debugMqttInfoCallback;

    public function __construct(
        private ThinQModuleContext $ctx,
        private int $instanceId,
        private string $apiKey,
        private ThinQBridgeConfig $config,
        private ThinQHttpClient $httpClient,
        callable $debugMqttInfoCallback
    ) {
        $this->debugMqttInfoCallback = $debugMqttInfoCallback;
    }

    private function t(string $s): string
    {
        return $this->ctx->t($s);
    }

    /**
     * Runs the whole setup. Returns what was set up; the module reports it to the user.
     * @return array{clientId: string, host: string, port: int, mqttId: int, ioId: int}
     */
    public function run(): array
    {
        try {
            // 1) Determine broker host/port from LG /route endpoint (preferred)
            $route    = $this->fetchRouteBroker();
            $HOST     = (string)$route['host'];
            $USE_TLS  = (bool)$route['tls'];
            $PORT     = (int)$route['port'];
            $routeCA  = (string)($route['ca'] ?? '');

            // 2) Generate/get LG-signed certificate (also returns subscriptions; used as fallback for host discovery)
            $certInfo  = $this->generateMqttClientCertMaterial();
            $subjectCN = (string)$certInfo['cn'];
            $certPem   = (string)$certInfo['cert'];
            $keyPem    = (string)$certInfo['key'];
            $subsMeta  = $certInfo['subscriptions'];

            // Fallback: if /route did not yield a host, try to extract from certificate response subscriptions
            if ($HOST === '') {
                $broker  = $this->extractBrokerFromSubscriptions($subsMeta);
                $HOST    = (string)$broker['host'];
                $USE_TLS = (bool)$broker['tls'];
                $PORT    = (int)$broker['port'];
            }

            $VERIFY_PEER = true;
            $VERIFY_HOST = true;

            if ($HOST === '') {
                throw new \RuntimeException($this->t('Broker host could not be determined from API data.'));
            }
            if ($PORT <= 0) {
                $PORT = $USE_TLS ? 8883 : 1883;
            }

            // 3) Subscriptions: if a custom filter is set in the module, use it; otherwise subscribe to the LG push topic
            $filter = trim((string)$this->ctx->propertyString('MQTTTopicFilter'));
            if ($filter !== '') {
                $topic = $filter;
                if (strpos($topic, '{ClientID}') !== false) {
                    $topic = str_replace('{ClientID}', $subjectCN, $topic);
                } elseif ($topic === 'app/clients/*/push' || $topic === 'app/clients/*/#') {
                    $topic = 'app/clients/' . $subjectCN . '/push';
                }
                $SUB_TOPICS = [$topic];
            } else {
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
            $mqttGUID  = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';
            $moduleList = @IPS_GetModuleList();
            if (!is_array($moduleList) || !in_array($mqttGUID, $moduleList, true)) {
                throw new \RuntimeException($this->t("Module 'MQTT Client' not found."));
            }
            $mqttID = 0;
            // Prefer configured property if it points to a valid MQTT Client instance
            $propMqttId = (int)$this->ctx->propertyInteger('MQTTClientID');
            if ($propMqttId > 0 && @IPS_InstanceExists($propMqttId)) {
                $info = @IPS_GetInstance($propMqttId);
                if (is_array($info) && isset($info['ModuleID']) && (string)$info['ModuleID'] === (string)$mqttGUID) {
                    $mqttID = $propMqttId;
                }
            }
            // Next: find by ObjectIdent marker
            if ($mqttID === 0) {
                $targetIdent       = 'LGThinQMQTT' . (string)$this->instanceId;
                $targetIdentLegacy = 'LGThinQ.MQTT.' . (string)$this->instanceId;
                foreach (@IPS_GetInstanceListByModuleID($mqttGUID) as $id) {
                    $obj   = @IPS_GetObject($id);
                    $ident = is_array($obj) ? (string)($obj['ObjectIdent'] ?? '') : '';
                    if ($ident === $targetIdent || $ident === $targetIdentLegacy) {
                        $mqttID = $id;
                        break;
                    }
                }
            }
            // Next: find by exact ClientID match
            if ($mqttID === 0 && $subjectCN !== '') {
                foreach (@IPS_GetInstanceListByModuleID($mqttGUID) as $id) {
                    $c = $this->cfg($id);
                    if (($c['ClientID'] ?? null) === $subjectCN) {
                        $mqttID = $id;
                        break;
                    }
                }
            }
            if ($mqttID === 0) {
                $mqttID = IPS_CreateInstance($mqttGUID);
                IPS_SetName($mqttID, $NAME_MQTT);
                try { IPS_SetIdent($mqttID, 'LGThinQMQTT' . (string)$this->instanceId); } catch (\Throwable $e) { /* ignore */ }
            }

            // Configure MQTT Client first
            if ($subjectCN !== '') { $this->safeSetProperty($mqttID, 'ClientID', $subjectCN); }
            $this->setFirstAvailableProperty($mqttID, ['UserName', 'Username'], '');
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
                for ($i = 0; $i < 20 && $ioID === 0; $i++) {
                    IPS_Sleep(100);
                    $ioID = (int)(@IPS_GetInstance($mqttID)['ConnectionID'] ?? 0);
                }
            }
            if ($ioID === 0) {
                $reuse             = 0;
                $targetIdentIONew  = 'LGThinQIO' . (string)$this->instanceId;
                $targetIdentIOLeg  = 'LGThinQ.IO.' . (string)$this->instanceId;
                foreach ($this->instancesOf('Client Socket') as $id) {
                    $obj   = @IPS_GetObject($id);
                    $ident = is_array($obj) ? (string)($obj['ObjectIdent'] ?? '') : '';
                    if ($ident === $targetIdentIONew || $ident === $targetIdentIOLeg) {
                        $reuse = $id;
                        break;
                    }
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
                    try { IPS_SetIdent($ioID, 'LGThinQIO' . (string)$this->instanceId); } catch (\Throwable $e) { /* ignore */ }
                }
            }

            // Configure IO
            if ($this->isInstanceOfModule($ioID, 'Client Socket')) {
                $this->safeSetProperty($ioID, 'Open', false);
                $this->safeSetProperty($ioID, 'Host', $HOST);
                $this->safeSetProperty($ioID, 'Port', (int)$PORT);
                $this->setFirstAvailableProperty($ioID, ['UseSSL', 'EnableSSL'], (bool)$USE_TLS);
                $this->safeSetProperty($ioID, 'VerifyPeer', (bool)$VERIFY_PEER);
                $this->safeSetProperty($ioID, 'VerifyHost', (bool)$VERIFY_HOST);

                $ioCfg = $this->cfg($ioID);
                $certMgr = new ThinQCertificateManager($this->instanceId);

                $certPemFormatted = $certMgr->ensureCertificatePEM($certPem);
                $keyPemFormatted  = $certMgr->ensurePrivateKeyPEM($keyPem);
                $caPemFormatted   = '';
                $haveCA           = false;

                if ($routeCA !== '') {
                    $caPemFormatted = $certMgr->ensureCertificatePEM($routeCA);
                    if (@openssl_x509_read($caPemFormatted) !== false) {
                        $haveCA = true;
                    } else if ($this->ctx->debugEnabled()) {
                        $this->ctx->debug('MQTT', 'Route-provided CA invalid, length=' . strlen((string)$routeCA));
                    }
                }
                if (!$haveCA && $subsMeta !== null) {
                    $apiCAPem = $certMgr->extractCAPEMFromSubscriptions($subsMeta);
                    if (is_string($apiCAPem) && $apiCAPem !== '') {
                        $caPemFormatted = $certMgr->ensureCertificatePEM($apiCAPem);
                        if (@openssl_x509_read($caPemFormatted) !== false) {
                            $haveCA = true;
                            if ($this->ctx->debugEnabled()) {
                                $this->ctx->debug('MQTT', 'CA from LG API subscriptions applied (len=' . strlen($caPemFormatted) . ')');
                            }
                        }
                    }
                }
                if (!$haveCA) {
                    $awsCA = $certMgr->downloadAmazonRootCA1();
                    if (is_string($awsCA) && $awsCA !== '' && strpos($awsCA, '-----BEGIN CERTIFICATE-----') !== false) {
                        $caPemFormatted = $certMgr->ensureCertificatePEM($awsCA);
                        if (@openssl_x509_read($caPemFormatted) !== false) {
                            $haveCA = true;
                            if ($this->ctx->debugEnabled()) {
                                $this->ctx->debug('MQTT', 'CA fallback: Amazon Root CA 1 applied (len=' . strlen($caPemFormatted) . ')');
                            }
                        }
                    }
                }

                $chainPem = rtrim($certPemFormatted) . "\n";
                if ($haveCA) { $chainPem .= rtrim($caPemFormatted) . "\n"; }

                $this->safeSetProperty($ioID, 'UseCertificate', true);
                foreach (['CertificateFile', 'ClientCertificateFile', 'LocalCert', 'LocalCertificate'] as $prop) { $this->safeSetProperty($ioID, $prop, ''); }
                foreach (['PrivateKeyFile', 'ClientKeyFile', 'LocalPrivateKey', 'LocalPrivateKeyFile'] as $prop) { $this->safeSetProperty($ioID, $prop, ''); }
                foreach (['CertificateAuthorityFile', 'CAFile', 'CACertificateFile', 'RootCertificateFile', 'RootCAFile', 'CACertFile'] as $prop) { $this->safeSetProperty($ioID, $prop, ''); }

                $this->safeSetProperty($ioID, 'Certificate', base64_encode($chainPem));
                $this->safeSetProperty($ioID, 'PrivateKey', base64_encode($keyPemFormatted));
                if ($haveCA) {
                    $this->safeSetProperty($ioID, 'CertificateAuthority', base64_encode($caPemFormatted));
                } else {
                    $this->safeSetProperty($ioID, 'CertificateAuthority', '');
                }

                $this->setFirstAvailableProperty($ioID, ['Password', 'PassPhrase'], '');

                if ($this->ctx->debugEnabled()) {
                    $this->ctx->debug('MQTT', 'IO keys: ' . implode(',', array_keys($ioCfg)));
                    $this->ctx->debug('MQTT', 'Using cert prop: inline(base64) Certificate, key prop: inline(base64) PrivateKey');
                    $okCert = @openssl_x509_read($certPemFormatted) !== false;
                    $okKey  = @openssl_pkey_get_private($keyPemFormatted) !== false;
                    $okPair = @openssl_x509_check_private_key($certPemFormatted, $keyPemFormatted);
                    $this->ctx->debug('MQTT', 'Props applied: UseCertificate, Certificate(len=' . strlen($chainPem) . ', parse=' . ($okCert ? 'OK' : 'FAIL') . '), PrivateKey(len=' . strlen($keyPemFormatted) . ', parse=' . ($okKey ? 'OK' : 'FAIL') . '), PairMatch=' . ($okPair ? 'yes' : 'no') . ', Password(empty), CA(' . ($haveCA ? ('len=' . strlen($caPemFormatted)) : 'none') . ')');
                }
                IPS_ApplyChanges($ioID);
                IPS_SetName($ioID, $NAME_IO);

                // Validate that inline PEMs persisted correctly
                try {
                    $curCfg  = $this->cfg($ioID);
                    $curCert = (string)($curCfg['Certificate'] ?? '');
                    $curKey  = (string)($curCfg['PrivateKey'] ?? '');
                    $curCA   = (string)($curCfg['CertificateAuthority'] ?? '');

                    $decodeIfB64 = function (string $s): string {
                        $trim = trim($s);
                        if ($trim === '') { return ''; }
                        if (strpos($trim, '-----BEGIN') === 0) { return $trim; }
                        $bin = base64_decode($trim, true);
                        if ($bin !== false && strpos($bin, '-----BEGIN') !== false) { return $bin; }
                        return $trim;
                    };

                    $curCertPem = $decodeIfB64($curCert);
                    $curKeyPem  = $decodeIfB64($curKey);
                    $curCAPem   = $decodeIfB64($curCA);

                    $okCertPersist = (strpos($curCertPem, '-----BEGIN CERTIFICATE-----') !== false) && (@openssl_x509_read($curCertPem) !== false);
                    $okKeyPersist  = (strpos($curKeyPem, '-----BEGIN') !== false) && (@openssl_pkey_get_private($curKeyPem) !== false);
                    $okCAPersist   = ($haveCA === false) || ((strpos($curCAPem, '-----BEGIN CERTIFICATE-----') !== false) && (@openssl_x509_read($curCAPem) !== false));

                    if ($this->ctx->debugEnabled()) {
                        $this->ctx->debug('MQTT', 'Post-write check: certLen=' . strlen($curCert) . ' keyLen=' . strlen($curKey) . ' caLen=' . strlen($curCA) . ' okCert=' . ($okCertPersist ? 'yes' : 'no') . ' okKey=' . ($okKeyPersist ? 'yes' : 'no') . ' okCA=' . ($okCAPersist ? 'yes' : 'no'));
                    }

                    if (!$okCertPersist || !$okKeyPersist || !$okCAPersist) {
                        $this->ctx->debug('MQTT', 'Inline PEM persistence check failed (cert=' . ($okCertPersist ? 'ok' : 'fail') . ', key=' . ($okKeyPersist ? 'ok' : 'fail') . ', ca=' . ($okCAPersist ? 'ok' : 'fail') . '). Please verify your Symcon version supports inline certificate properties.');
                    }
                } catch (\Throwable $e) {
                    if ($this->ctx->debugEnabled()) {
                        $this->ctx->debug('MQTT', 'Post-write check exception: ' . $e->getMessage());
                    }
                }

                $this->safeSetProperty($ioID, 'Open', true);
                IPS_ApplyChanges($ioID);
                if ($this->ctx->debugEnabled()) {
                    ($this->debugMqttInfoCallback)();
                }
            } else {
                $this->ctx->debug('MQTT', 'Warning: Parent #' . $ioID . ' is not a Client Socket');
            }

            // 6) Connect this Bridge to the configured MQTT Client and persist setting
            IPS_ConnectInstance($this->instanceId, $mqttID);
            IPS_SetProperty($this->instanceId, 'UseMQTT', true);
            IPS_SetProperty($this->instanceId, 'MQTTClientID', (int)$mqttID);
            IPS_ApplyChanges($this->instanceId);

            // 7) Post-setup: register client idempotently with final ClientID (subjectCN)
            try {
                $tmpCfg2 = ThinQBridgeConfig::create(
                    $this->config->accessToken,
                    $this->config->countryCode,
                    $subjectCN,
                    $this->config->debug,
                    $this->config->useMqtt,
                    $this->config->mqttClientId,
                    $this->config->mqttTopicFilter,
                    $this->config->ignoreRetained,
                    $this->config->eventTtlHours,
                    $this->config->eventRenewLeadMin
                );
                $http2 = new ThinQHttpClient($this->ctx, $tmpCfg2, $this->apiKey);
                try {
                    $http2->request('POST', 'client', ['body' => ['type' => 'MQTT', 'service-code' => 'SVC202', 'device-type' => '607']]);
                } catch (\Throwable $e) {
                    $this->ctx->debug('UISetupMqttConnection', 'Register client ignored: ' . $e->getMessage());
                }
            } catch (\Throwable $e) {
                $this->ctx->debug('UISetupMqttConnection', 'Register client failed: ' . $e->getMessage());
            }

            return ['clientId' => (string)$subjectCN, 'host' => (string)$HOST, 'port' => (int)$PORT, 'mqttId' => (int)$mqttID, 'ioId' => (int)$ioID];
        } catch (\Throwable $e) {
            $this->ctx->debug('UISetupMqttConnection', $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{cn:string, cert:string, key:string, public:string, subscriptions:mixed}
     */
    private function generateMqttClientCertMaterial(): array
    {
        $clientId = trim((string)$this->ctx->attributeString('ClientID'));
        if ($clientId === '') {
            $propId = trim((string)$this->ctx->propertyString('ClientID'));
            if ($propId !== '') {
                $clientId = $propId;
            } else {
                try {
                    $rand5 = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                } catch (\Throwable $e) {
                    $rand5 = str_pad((string)mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
                }
                $clientId = 'Symcon' . $rand5;
            }
            $this->ctx->writeAttributeString('ClientID', $clientId);
        }
        $subjectCN = $clientId;
        $instInfo = @IPS_GetInstance($this->instanceId);
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
        $tmpCfg = ThinQBridgeConfig::create(
            $this->config->accessToken,
            $this->config->countryCode,
            $subjectCN,
            $this->config->debug,
            $this->config->useMqtt,
            $this->config->mqttClientId,
            $this->config->mqttTopicFilter,
            $this->config->ignoreRetained,
            $this->config->eventTtlHours,
            $this->config->eventRenewLeadMin
        );
        $tmpHttp = new ThinQHttpClient($this->ctx, $tmpCfg, $this->apiKey);
        $certMgr = new ThinQCertificateManager($this->instanceId);
        return $certMgr->requestLGSignedCert($tmpHttp, $subjectCN);
    }

    /**
     * @param mixed $subscriptions
     * @return array{host:string, port:int, tls:bool}
     */
    private function extractBrokerFromSubscriptions(mixed $subscriptions): array
    {
        $host = '';
        $port = 0;
        $tls  = true;
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
        $url              = '';
        $host             = '';
        $port             = 0;
        $tls              = true;
        $usedWssFallback  = false;
        $caPem            = '';
        try {
            $resp  = $this->httpClient->request('GET', 'route');
            $mqtt  = $resp['mqttServer'] ?? ($resp['mqtt'] ?? null);
            $wss   = $resp['webSocketServer'] ?? null;
            $caPem = (string)($resp['certificateAuthority'] ?? ($resp['caCertificate'] ?? ($resp['caPem'] ?? '')));

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
                $url             = $wss;
                $usedWssFallback = true;
            }

            if ($url !== '') {
                $p = @parse_url($url);
                if (is_array($p)) {
                    if ($host === '' && isset($p['host'])) { $host = (string)$p['host']; }
                    if ($port === 0 && isset($p['port'])) { $port = (int)$p['port']; }
                    $scheme = strtolower((string)($p['scheme'] ?? ''));
                    if ($scheme !== '') {
                        $tls = ($scheme !== 'mqtt');
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($this->ctx->debugEnabled()) {
                $this->ctx->debug('Route', 'Route call failed: ' . $e->getMessage());
            }
        }

        if ($host === '') {
            return ['url' => (string)$url, 'host' => '', 'port' => 0, 'tls' => (bool)$tls];
        }
        if ($usedWssFallback) {
            $port = 8883;
        } elseif ($port <= 0) {
            $port = $tls ? 8883 : 1883;
        }
        if ($this->ctx->debugEnabled()) {
            $this->ctx->debug('Route', 'MQTT: url=' . $url . ' host=' . $host . ' port=' . $port . ' tls=' . ($tls ? 'true' : 'false') . ' caLen=' . strlen((string)$caPem));
        }
        return ['url' => (string)$url, 'host' => (string)$host, 'port' => (int)$port, 'tls' => (bool)$tls, 'ca' => (string)$caPem];
    }

    /** @return array<int,int> */
    private function instancesOf(string $moduleName): array
    {
        $guid = $this->findModuleGUIDByName($moduleName);
        return $guid ? @IPS_GetInstanceListByModuleID($guid) : [];
    }

    private function findModuleGUIDByName(string $moduleName): ?string
    {
        foreach (@IPS_GetModuleList() as $guid) {
            $m = @IPS_GetModule($guid);
            if (!is_array($m)) { continue; }
            foreach (array_merge([$m['ModuleName'] ?? ''], $m['Aliases'] ?? []) as $n) {
                if (mb_strtolower((string)$n) === mb_strtolower($moduleName)) { return (string)$guid; }
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function cfg(int $id): array
    {
        $raw  = @IPS_GetConfiguration($id);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    private function safeSetProperty(int $id, string $prop, mixed $value): bool
    {
        $c = $this->cfg($id);
        if (!array_key_exists($prop, $c)) { return false; }
        @IPS_SetProperty($id, $prop, $value);
        return true;
    }

    private function setFirstAvailableProperty(int $id, array $keys, mixed $value): ?string
    {
        foreach ($keys as $k) {
            if ($this->safeSetProperty($id, $k, $value)) { return (string)$k; }
        }
        return null;
    }

    private function setJsonCompatibleProperty(int $id, string $prop, mixed $arrayValue): bool
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
        foreach ($this->instancesOf($moduleName) as $id) {
            if ($id === $instanceID) { return true; }
        }
        return false;
    }
}
