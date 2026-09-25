<?php

declare(strict_types=1);

/**
 * Packs an LG-signed MQTT client certificate with its key as ZIP, for an MQTT client outside
 * Symcon. Key, CSR and certificate come from ThinQCertificateManager like in the MQTT setup.
 */
final class ThinQMqttCertBuilder
{
    /** $api must send x-client-id = the CN that build() gets. */
    public function __construct(private ThinQModuleContext $ctx, private ThinQApi $api)
    {
    }

    public function build(string $subjectCN): string
    {
        $cert = (new ThinQCertificateManager($this->ctx->instanceId))->requestLGSignedCert($this->api, $subjectCN);
        if ($this->ctx->debugEnabled()) {
            $this->debugCertificate($cert);
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'lgtq_zip_');
        if ($tmpZip === false) {
            throw new \RuntimeException($this->ctx->t('Failed to create temporary ZIP file'));
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException($this->ctx->t('Failed to open ZIP'));
            }
            $zip->addFromString('00_meta.json', (string)json_encode([
                'module'          => 'LG ThinQ Bridge',
                'purpose'         => 'MQTT client certificate',
                'instanceId'      => $this->ctx->instanceId,
                'alias'           => @IPS_GetName($this->ctx->instanceId),
                'subjectCN'       => $subjectCN,
                'lgSigned'        => true,
                'lgSubscriptions' => $cert['subscriptions'],
                'timestamp'       => date('c'),
                'phpVersion'      => PHP_VERSION,
                'kernelVersion'   => function_exists('IPS_GetKernelVersion') ? @IPS_GetKernelVersion() : ''
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->addFromString('client_cert.pem', $cert['cert']);
            $zip->addFromString('client_private_key.pem', $cert['key']);
            $zip->addFromString('client_public_key.pem', $cert['public']);
            if ($cert['csr'] !== '') {
                $zip->addFromString('client_csr.pem', $cert['csr']);
            }
            $zip->addFromString('README.txt', $this->readme($cert['csr'] !== ''));
            $zip->close();
            $data = file_get_contents($tmpZip);
        } finally {
            @unlink($tmpZip);
        }
        if (!is_string($data) || $data === '') {
            throw new \RuntimeException($this->ctx->t('Failed to read ZIP content'));
        }
        return $data;
    }

    private function readme(bool $withCsr): string
    {
        $lines = [
            $this->ctx->t('This ZIP file contains a client certificate for the MQTT client, signed via the LG ThinQ API.'),
            '',
            $this->ctx->t('Files:'),
            '- client_cert.pem: ' . $this->ctx->t('X.509 client certificate (signed by LG)'),
            '- client_private_key.pem: ' . $this->ctx->t('private key (PEM, not encrypted)'),
            '- client_public_key.pem: ' . $this->ctx->t('public key'),
        ];
        if ($withCsr) {
            $lines[] = '- client_csr.pem: ' . $this->ctx->t('certificate signing request (CSR)');
        }
        $lines[] = '';
        $lines[] = $this->ctx->t('Notes:');
        $lines[] = '- ' . $this->ctx->t('Import certificate and private key where your MQTT client needs them.');
        $lines[] = '- ' . $this->ctx->t('The common name (CN) must match the MQTT client ID exactly.');
        return implode("\n", $lines) . "\n";
    }

    /** @param array{cn:string, cert:string, key:string} $cert lengths, names and the key match only */
    private function debugCertificate(array $cert): void
    {
        $parsed = @openssl_x509_parse($cert['cert']);
        if (is_array($parsed)) {
            $this->ctx->debug('CertGen', 'Cert subjectCN=' . ($parsed['subject']['CN'] ?? '') . ' issuer=' . ($parsed['issuer']['CN'] ?? '')
                . ' KU=' . ($parsed['extensions']['keyUsage'] ?? '') . ' EKU=' . ($parsed['extensions']['extendedKeyUsage'] ?? ''));
        }
        $this->ctx->debug('CertGen', 'Cert PEM length=' . strlen($cert['cert']) . ' sha256=' . hash('sha256', $cert['cert'])
            . ' matches key: ' . (@openssl_x509_check_private_key($cert['cert'], $cert['key']) ? 'yes' : 'no'));
    }
}
