<?php

declare(strict_types=1);

require_once __DIR__ . '/ThinQHttpTransport.php';

final class ThinQHttpClient
{
    private IPSModule $module;
    private ThinQBridgeConfig $config;
    private string $apiKey;
    private ThinQHttpTransport $transport;

    public function __construct(IPSModule $module, ThinQBridgeConfig $config, string $apiKey)
    {
        $this->module = $module;
        $this->config = $config;
        $this->apiKey = $apiKey;
        $this->transport = new ThinQHttpTransport();
    }

    private function dbg(string $tag, string $message): void
    {
        // Mirror HTTP logs to the Bridge instance debug if available; otherwise fall back to kernel log
        if (method_exists($this->module, 'DebugLog')) {
            $this->module->DebugLog('HTTP', $tag . ': ' . $message);
        } else {
            @IPS_LogMessage('LG ThinQ HTTP', $tag . ': ' . $message);
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<int, string> $extraHeaders
     * @return array<string, mixed>
     */
    public function request(string $method, string $endpoint, ?array $payload = null, array $extraHeaders = []): array
    {
        $errors = $this->config->validate();
        if (!empty($errors)) {
            throw new Exception('ThinQ configuration incomplete: ' . implode(' ', $errors));
        }

        $url = $this->config->baseUrl() . ltrim($endpoint, '/');
        $headers = [
            'Authorization: Bearer ' . $this->config->accessToken,
            'x-country: ' . $this->config->countryCode,
            'x-message-id: ' . ThinQHelpers::generateMessageId(),
            'x-client-id: ' . $this->config->clientId,
            'x-api-key: ' . $this->apiKey,
            'x-service-phase: OP',
            'Content-Type: application/json'
        ];
        foreach ($extraHeaders as $header) {
            if ($header !== '') {
                $headers[] = $header;
            }
        }

        $method = strtoupper($method);
        $body = ($method !== 'GET' && $payload !== null)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        if ($this->config->debug) {
            @IPS_LogMessage('LG ThinQ HTTP', 'Request: ' . $method . ' ' . $url);
            @IPS_LogMessage('LG ThinQ HTTP', 'Headers: ' . json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($method !== 'GET') {
                @IPS_LogMessage('LG ThinQ HTTP', 'Body: ' . $body);
            }
            $this->dbg('Request', $method . ' ' . $url);
            $this->dbg('Headers', json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($method !== 'GET') {
                $this->dbg('Body', (string)$body);
            }
        }

        $reply = $this->transport->send($method, $url, $headers, $method !== 'GET' ? $body : null, 15);
        $result = $reply['body'];
        if ($result === false) {
            throw new Exception('HTTP error calling ' . $url . ': ' . ($reply['error'] !== '' ? $reply['error'] : 'unknown'));
        }

        $statusHeader = $reply['statusLine'];
        $statusCode = $reply['status'];

        if ($this->config->debug) {
            @IPS_LogMessage('LG ThinQ HTTP', 'ResponseStatus: ' . $statusCode . ' HeaderLine: ' . $statusHeader);
            // Log a compact, truncated response body for diagnostics
            $snippet = substr(preg_replace('/\s+/', ' ', (string)$result), 0, 1000);
            @IPS_LogMessage('LG ThinQ HTTP', 'ResponseBody: ' . $snippet . (strlen($result) > 1000 ? ' ...[truncated]' : ''));
            $this->dbg('ResponseStatus', $statusCode . ' HeaderLine: ' . $statusHeader);
            $this->dbg('ResponseBody', $snippet . (strlen($result) > 1000 ? ' ...[truncated]' : ''));
        }

        if ($statusCode === 204 || trim($result) === '') {
            return [];
        }

        $decoded = json_decode($result, true);
        if ($statusCode >= 400) {
            if ($this->config->debug) {
                @IPS_LogMessage('LG ThinQ HTTP', 'HTTP error status ' . $statusCode . ' for URL: ' . $url);
                $this->dbg('Error', 'HTTP error status ' . $statusCode . ' for URL: ' . $url);
            }
            if (is_array($decoded) && isset($decoded['error'])) {
                $code = $decoded['error']['code'] ?? 'unknown';
                $message = $decoded['error']['message'] ?? 'unknown';
                throw new Exception('HTTP ' . $statusCode . ' API error ' . $code . ': ' . $message . ' (' . $url . ')');
            }
            $snippet = substr(preg_replace('/\s+/', ' ', (string)$result), 0, 300);
            throw new Exception('HTTP ' . $statusCode . ' error from ' . $url . ': ' . $snippet);
        }

        if (!is_array($decoded)) {
            return [];
        }

        if ($this->config->debug) {
            @IPS_LogMessage('LG ThinQ HTTP', 'DecodedKeys: ' . implode(',', array_keys($decoded)));
            $this->dbg('DecodedKeys', implode(',', array_keys($decoded)));
        }

        return $decoded['response'] ?? $decoded;
    }
}
