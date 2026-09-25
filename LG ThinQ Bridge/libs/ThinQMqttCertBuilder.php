<?php

declare(strict_types=1);

/**
 * ThinQMqttCertBuilder
 *
 * Extracted from LG ThinQ Bridge/module.php.
 * Generates LG-signed MQTT client certificates and packages them as a ZIP archive.
 */
class ThinQMqttCertBuilder
{
    /** @var callable */
    private $debugMqttInfoCallback;
    /** @var callable */
    private $createConfigCallback;

    public function __construct(
        private ThinQModuleContext $ctx,
        private int $instanceId,
        callable $debugMqttInfoCallback,
        callable $createConfigCallback,
        private string $apiKey
    ) {
        $this->debugMqttInfoCallback = $debugMqttInfoCallback;
        $this->createConfigCallback  = $createConfigCallback;
    }

    private function t(string $s): string
    {
        return $this->ctx->t($s);
    }

    public function build(): string
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException($this->t('OpenSSL is not supported (openssl_* functions missing)'));
        }

        $baseCfg   = ($this->createConfigCallback)();
        $clientId  = (string)$baseCfg->clientId;
        $subjectCN = ThinQClientId::sanitize($clientId);
        if ($subjectCN === '') {
            $subjectCN = 'client-' . (string)$this->instanceId;
        }
        if ($this->ctx->debugEnabled()) {
            $this->ctx->debug('CertGen', 'Effective CN=' . $subjectCN . ' (Bridge ClientID=' . $clientId . ')');
            ($this->debugMqttInfoCallback)();
        }

        $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lgtq_mqtt_' . $this->instanceId . '_' . bin2hex(random_bytes(4)) . '.cnf';
        $nl      = "\r\n";
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
        if ($this->ctx->debugEnabled()) {
            $this->ctx->debug('CertGen', 'OpenSSL cfg written: ' . $cfgPath);
        }
        $dn     = ['commonName' => $subjectCN];
        $config = ['config' => $cfgPath, 'digest_alg' => 'sha256'];

        // Prefer EC P-256 key, fallback to RSA 2048
        $configKey = ['config' => $cfgPath, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $pkGenerate = @openssl_pkey_new($configKey);
        if ($pkGenerate === false) {
            $configKey  = ['config' => $cfgPath, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $pkGenerate = openssl_pkey_new($configKey);
        }
        if ($pkGenerate === false) {
            throw new \RuntimeException($this->t('openssl_pkey_new failed'));
        }

        $pkPrivate = '';
        if (!openssl_pkey_export($pkGenerate, $pkPrivate, null, $config)) {
            throw new \RuntimeException($this->t('openssl_pkey_export failed'));
        }
        $pkDetails = openssl_pkey_get_details($pkGenerate);
        if ($pkDetails === false || !isset($pkDetails['key'])) {
            throw new \RuntimeException($this->t('openssl_pkey_get_details failed'));
        }
        $pkPublic = (string)$pkDetails['key'];
        if ($this->ctx->debugEnabled()) {
            $typeStr = (($pkDetails['type'] ?? null) === OPENSSL_KEYTYPE_EC) ? 'EC' : 'RSA';
            $bits    = (int)($pkDetails['bits'] ?? 0);
            $this->ctx->debug('CertGen', 'Key generated: ' . $typeStr . ' bits=' . $bits);
            $this->ctx->debug('CertGen', 'Key PEM lengths: private=' . strlen($pkPrivate) . ' public=' . strlen($pkPublic));
        }

        $csr = openssl_csr_new($dn, $pkGenerate, $config);
        if ($csr === false) {
            $this->ctx->debug('CertGen', 'CSR with v3_req failed; retrying without req_extensions');
            $strCONFIG2  = 'default_md = sha256' . $nl;
            $strCONFIG2 .= 'default_days = 3650' . $nl . $nl;
            $strCONFIG2 .= '[ req ]' . $nl;
            $strCONFIG2 .= 'default_bits = 2048' . $nl;
            $strCONFIG2 .= 'distinguished_name = req_DN' . $nl;
            $strCONFIG2 .= 'string_mask = nombstr' . $nl;
            $strCONFIG2 .= 'prompt = no' . $nl;
            $strCONFIG2 .= 'x509_extensions = v3_client' . $nl . $nl;
            $strCONFIG2 .= '[ req_DN ]' . $nl;
            $strCONFIG2 .= 'commonName = "' . addslashes($subjectCN) . '"' . $nl . $nl;
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
            $config2 = ['config' => $cfgPath, 'digest_alg' => 'sha256'];
            $csr = openssl_csr_new($dn, $pkGenerate, $config2);
            if ($csr === false) {
                throw new \RuntimeException($this->t('openssl_csr_new failed'));
            }
            $config = $config2;
        }

        // Try to obtain LG-signed certificate first (preferred)
        $lgCertOut       = '';
        $lgSubscriptions = null;
        try {
            $tmpCfg  = $baseCfg->withClientId($subjectCN);
            $tmpHttp = new ThinQHttpClient($this->ctx, $tmpCfg, $this->apiKey);

            $csrPemForApi = '';
            @openssl_csr_export($csr, $csrPemForApi);
            if ($this->ctx->debugEnabled()) {
                $this->ctx->debug('CertGen', 'CSR length=' . strlen((string)$csrPemForApi));
                $this->ctx->debug('CertGen', 'Register client and request certificate for x-client-id=' . $subjectCN);
            }

            // 1) Register client (idempotent)
            try {
                $tmpHttp->request('POST', 'client', ['body' => ['type' => 'MQTT', 'service-code' => 'SVC202', 'device-type' => '607']]);
            } catch (\Throwable $e) {
                $this->ctx->debug('CertGen', 'Register client ignored: ' . $e->getMessage());
            }

            // 2) Request certificate
            $resp    = $tmpHttp->request('POST', 'client/certificate', ['body' => ['service-code' => 'SVC202', 'csr' => $csrPemForApi]]);
            $resNode = isset($resp['result']) && is_array($resp['result']) ? $resp['result'] : $resp;
            $maybeCert = $resNode['certificatePem'] ?? null;
            if (is_string($maybeCert) && trim($maybeCert) !== '') {
                $lgCertOut = (string)$maybeCert;
            }
            $lgSubscriptions = $resNode['subscriptions'] ?? null;

            // Fallback: some APIs might return without 'body' wrapper
            if ($lgCertOut === '') {
                $resp2    = $tmpHttp->request('POST', 'client/certificate', ['service-code' => 'SVC202', 'csr' => $csrPemForApi]);
                $resNode2 = isset($resp2['result']) && is_array($resp2['result']) ? $resp2['result'] : $resp2;
                $maybeCert2 = $resNode2['certificatePem'] ?? null;
                if (is_string($maybeCert2) && trim($maybeCert2) !== '') {
                    $lgCertOut = (string)$maybeCert2;
                }
                if ($lgSubscriptions === null) {
                    $lgSubscriptions = $resNode2['subscriptions'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            $this->ctx->debug('CertGen', 'LG certificate request failed: ' . $e->getMessage());
            $lgCertOut = '';
        }

        if ($lgCertOut === '') {
            throw new \RuntimeException($this->t('LG certificate request returned no certificatePem'));
        }
        $certOut = $lgCertOut;
        $csrOut  = '';
        if (!openssl_csr_export($csr, $csrOut)) {
            $csrOut = '';
        }
        if ($this->ctx->debugEnabled()) {
            if ($lgSubscriptions !== null) {
                $this->ctx->debug('CertGen', 'LG Subscriptions: ' . substr(json_encode($lgSubscriptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1000) . (strlen(json_encode($lgSubscriptions)) > 1000 ? ' ...[truncated]' : ''));
            }
            $fpSha = hash('sha256', (string)$certOut);
            $x509  = @openssl_x509_read($certOut);
            if ($x509 !== false) {
                $parsed = @openssl_x509_parse($x509);
                if (is_array($parsed)) {
                    $subCN  = $parsed['subject']['CN'] ?? ($parsed['subject']['commonName'] ?? '');
                    $issuer = $parsed['issuer']['CN'] ?? ($parsed['issuer']['commonName'] ?? '');
                    $eku    = $parsed['extensions']['extendedKeyUsage'] ?? '';
                    $ku     = $parsed['extensions']['keyUsage'] ?? '';
                    $this->ctx->debug('CertGen', 'Cert subjectCN=' . $subCN . ' issuer=' . $issuer);
                    $this->ctx->debug('CertGen', 'Cert KU=' . $ku . ' EKU=' . $eku);
                }
            }
            $this->ctx->debug('CertGen', 'Cert PEM length=' . strlen($certOut) . ' sha256=' . $fpSha);
            $matches = @openssl_x509_check_private_key($certOut, $pkPrivate);
            $this->ctx->debug('CertGen', 'Cert matches private key: ' . ($matches ? 'yes' : 'no'));
        }

        // Create ZIP
        $zip    = new \ZipArchive();
        $tmpZip = tempnam(sys_get_temp_dir(), 'lgtq_mqtt_' . $this->instanceId . '_' . bin2hex(random_bytes(4)) . '.cnf');
        if ($tmpZip === false) {
            throw new \RuntimeException($this->t('Failed to create temporary ZIP file'));
        }
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);
            throw new \RuntimeException($this->t('Failed to open ZIP'));
        }

        $meta = [
            'module'          => 'LG ThinQ Bridge',
            'purpose'         => 'MQTT Client Zertifikate',
            'instanceId'      => $this->instanceId,
            'alias'           => @IPS_GetName($this->instanceId),
            'clientId'        => $clientId,
            'subjectCN'       => $subjectCN,
            'lgSigned'        => ($lgCertOut !== ''),
            'lgSubscriptions' => $lgSubscriptions,
            'timestamp'       => date('c'),
            'phpVersion'      => PHP_VERSION,
            'kernelVersion'   => function_exists('IPS_GetKernelVersion') ? @IPS_GetKernelVersion() : ''
        ];
        $zip->addFromString('00_meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

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
}
