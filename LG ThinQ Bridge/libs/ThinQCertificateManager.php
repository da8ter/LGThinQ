<?php

declare(strict_types=1);

final class ThinQCertificateManager
{
    private int $instanceId;

    public function __construct(int $instanceId)
    {
        $this->instanceId = $instanceId;
    }

    /**
     * Generate a keypair and CSR for a given subject CN.
     * Returns ['cn' => string, 'privateKey' => string, 'publicKey' => string, 'csrPem' => string].
     *
     * @throws \RuntimeException
     */
    public function generateKeyAndCSR(string $subjectCN): array
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('OpenSSL is not supported (openssl_* functions missing)');
        }

        $cfgPath = $this->writeTempConfig($subjectCN);

        try {
            // Generate key (EC P-256 preferred, RSA 2048 fallback)
            $configKey = ['config' => $cfgPath, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
            $pkGenerate = @openssl_pkey_new($configKey);
            if ($pkGenerate === false) {
                $configKey = ['config' => $cfgPath, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
                $pkGenerate = openssl_pkey_new($configKey);
            }
            if ($pkGenerate === false) {
                throw new \RuntimeException('openssl_pkey_new failed');
            }

            $pkPrivate = '';
            if (!openssl_pkey_export($pkGenerate, $pkPrivate, null, ['config' => $cfgPath, 'digest_alg' => 'sha256'])) {
                throw new \RuntimeException('openssl_pkey_export failed');
            }
            $pkDetails = openssl_pkey_get_details($pkGenerate);
            if ($pkDetails === false || !isset($pkDetails['key'])) {
                throw new \RuntimeException('openssl_pkey_get_details failed');
            }
            $pkPublic = (string)$pkDetails['key'];

            // CSR (with fallback minimal config)
            $dn = ['commonName' => $subjectCN];
            $config = ['config' => $cfgPath, 'digest_alg' => 'sha256'];
            $csr = openssl_csr_new($dn, $pkGenerate, $config);
            if ($csr === false) {
                // Rewrite config with minimal settings and retry
                $this->writeTempConfig($subjectCN, $cfgPath);
                $config = ['config' => $cfgPath, 'digest_alg' => 'sha256'];
                $csr = openssl_csr_new($dn, $pkGenerate, $config);
                if ($csr === false) {
                    throw new \RuntimeException('openssl_csr_new failed');
                }
            }

            $csrPem = '';
            @openssl_csr_export($csr, $csrPem);

            return [
                'cn'         => $subjectCN,
                'privateKey' => $pkPrivate,
                'publicKey'  => $pkPublic,
                'csrPem'     => $csrPem,
            ];
        } finally {
            @unlink($cfgPath);
        }
    }

    /**
     * Request an LG-signed client certificate for $subjectCN; $api must send x-client-id = $subjectCN.
     * LG's OpenAPI wraps both requests in {"body": ...}; the client registration is idempotent.
     *
     * @return array{cn:string, cert:string, key:string, public:string, csr:string, subscriptions:mixed}
     * @throws \RuntimeException
     */
    public function requestLGSignedCert(ThinQApi $api, string $subjectCN): array
    {
        $material = $this->generateKeyAndCSR($subjectCN);
        try {
            $api->registerClient();
        } catch (\Throwable $e) {
            // already registered, or registration not needed; the certificate request decides
        }
        $result = $api->requestCertificate($material['csrPem']);
        $certOut = (string)($result['certificatePem'] ?? '');
        if ($certOut === '') {
            throw new \RuntimeException('LG certificate request returned no certificatePem');
        }
        return [
            'cn'            => $subjectCN,
            'cert'          => $certOut,
            'key'           => $material['privateKey'],
            'public'        => $material['publicKey'],
            'csr'           => $material['csrPem'],
            'subscriptions' => $result['subscriptions'] ?? null,
        ];
    }

    /**
     * Ensure a valid single CERTIFICATE PEM with proper headers and 64-char wrapping.
     */
    public function ensureCertificatePEM(string $input): string
    {
        $norm = str_replace(["\r\n", "\r"], "\n", trim($input));
        if (preg_match('/-----BEGIN CERTIFICATE-----([A-Za-z0-9+\/=`\n\r\s]+)-----END CERTIFICATE-----/m', $norm, $m)) {
            $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', (string)($m[1] ?? ''));
            $bin = base64_decode((string)$b64, true);
            if ($bin === false) {
                return $this->formatPemContent($norm) ?: $norm . "\n";
            }
            $wrapped = chunk_split(base64_encode($bin), 64, "\n");
            return "-----BEGIN CERTIFICATE-----\n" . rtrim($wrapped, "\n") . "\n-----END CERTIFICATE-----\n";
        }
        $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $norm);
        $bin = base64_decode((string)$b64, true);
        if ($bin === false) {
            return $this->formatPemContent($norm) ?: $norm . "\n";
        }
        $wrapped = chunk_split(base64_encode($bin), 64, "\n");
        return "-----BEGIN CERTIFICATE-----\n" . rtrim($wrapped, "\n") . "\n-----END CERTIFICATE-----\n";
    }

    /**
     * Ensure a valid PRIVATE KEY PEM (EC/RSA/PKCS#8) with proper headers and 64-char wrapping.
     */
    public function ensurePrivateKeyPEM(string $input): string
    {
        $norm = str_replace(["\r\n", "\r"], "\n", trim($input));
        if ($norm === '') {
            return '';
        }
        if (preg_match('/-----BEGIN ([A-Z ]*?)PRIVATE KEY-----([A-Za-z0-9+\/=`\n\r\s]+)-----END \1PRIVATE KEY-----/m', $norm, $m)) {
            $type = trim((string)($m[1] ?? ''));
            $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', (string)($m[2] ?? ''));
            $bin = base64_decode($b64, true);
            if ($bin === false) {
                return $this->formatPemContent($norm) ?: $norm . "\n";
            }
            $wrapped = chunk_split(base64_encode($bin), 64, "\n");
            $hdr = '-----BEGIN ' . ($type !== '' ? ($type . ' ') : '') . 'PRIVATE KEY-----';
            $ftr = '-----END '   . ($type !== '' ? ($type . ' ') : '') . 'PRIVATE KEY-----';
            return $hdr . "\n" . rtrim($wrapped, "\n") . "\n" . $ftr . "\n";
        }
        $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $norm);
        $bin = base64_decode($b64, true);
        if ($bin === false) {
            return $this->formatPemContent($norm) ?: $norm . "\n";
        }
        $wrapped = chunk_split(base64_encode($bin), 64, "\n");
        return "-----BEGIN PRIVATE KEY-----\n" . rtrim($wrapped, "\n") . "\n-----END PRIVATE KEY-----\n";
    }

    /**
     * Download Amazon Root CA 1 from Amazon's repository for AWS IoT ATS endpoints.
     */
    public function downloadAmazonRootCA1(): string
    {
        return (new ThinQHttpTransport())->fetchText(
            'https://www.amazontrust.com/repository/AmazonRootCA1.pem',
            10,
            static fn(string $body): bool => strpos($body, '-----BEGIN CERTIFICATE-----') !== false
        );
    }

    /**
     * Normalize PEM content: remove blank lines, ensure 64-char line wrapping.
     */
    public function formatPemContent(string $pem): string
    {
        $pem = str_replace(["\r\n", "\r"], "\n", trim($pem));
        $cleanLines = [];
        foreach (explode("\n", $pem) as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $cleanLines[] = $trimmed;
            }
        }
        return implode("\n", $cleanLines) . "\n";
    }

    /**
     * Write a temporary OpenSSL config file for key/CSR generation.
     */
    private function writeTempConfig(string $subjectCN, ?string $cfgPath = null): string
    {
        if ($cfgPath === null) {
            $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lgtq_mqtt_' . $this->instanceId . '_' . bin2hex(random_bytes(4)) . '.cnf';
        }
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

        $handle = fopen($cfgPath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Failed to create temporary OpenSSL configuration');
        }
        fwrite($handle, $strCONFIG);
        fclose($handle);

        return $cfgPath;
    }
}
