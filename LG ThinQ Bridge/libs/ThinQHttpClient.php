<?php

declare(strict_types=1);

final class ThinQHttpClient
{
    private IPSModule $module;
    private ThinQBridgeConfig $config;
    private string $apiKey;

    public function __construct(IPSModule $module, ThinQBridgeConfig $config, string $apiKey)
    {
        $this->module = $module;
        $this->config = $config;
        $this->apiKey = $apiKey;
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
            throw new Exception('ThinQ Konfiguration unvollständig: ' . implode(' ', $errors));
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
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 15,
                'protocol_version' => 1.1,
                'content' => $method !== 'GET' ? $body : null
            ]
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false || $result === null) {
            $error = error_get_last();
            throw new Exception('HTTP Fehler beim Aufruf von ' . $url . ': ' . ($error['message'] ?? 'unbekannt'));
        }

        $statusHeader = $http_response_header[0] ?? '';
        $statusCode = 0;
        if (preg_match('/HTTP\/[0-9.]+\s+(\d+)/', $statusHeader, $match)) {
            $statusCode = (int)$match[1];
        }

        if ($statusCode === 204 || trim($result) === '') {
            return [];
        }

        $decoded = json_decode($result, true);
        if ($statusCode >= 400) {
            if (is_array($decoded) && isset($decoded['error'])) {
                $code = $decoded['error']['code'] ?? 'unknown';
                $message = $decoded['error']['message'] ?? 'unknown';
                throw new Exception('HTTP ' . $statusCode . ' API Fehler ' . $code . ': ' . $message . ' (' . $url . ')');
            }
            $snippet = substr(preg_replace('/\s+/', ' ', (string)$result), 0, 300);
            throw new Exception('HTTP ' . $statusCode . ' Fehler von ' . $url . ': ' . $snippet);
        }

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded['response'] ?? $decoded;
    }
}
