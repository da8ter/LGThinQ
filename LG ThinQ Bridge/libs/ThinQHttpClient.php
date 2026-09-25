<?php

declare(strict_types=1);

require_once __DIR__ . '/ThinQHttpTransport.php';
require_once __DIR__ . '/ThinQRedactor.php';

final class ThinQHttpClient
{
    private ThinQModuleContext $ctx;
    private ThinQBridgeConfig $config;
    private string $apiKey;
    private ThinQHttpTransport $transport;

    public function __construct(ThinQModuleContext $ctx, ThinQBridgeConfig $config, string $apiKey)
    {
        $this->ctx = $ctx;
        $this->config = $config;
        $this->apiKey = $apiKey;
        $this->transport = new ThinQHttpTransport();
    }

    /** HTTP traces go to the instance debug only (never to the message log), with secrets redacted. */
    private function dbg(string $tag, string $message): void
    {
        if ($this->config->debug) {
            $this->ctx->debug('HTTP', $tag . ': ' . ThinQRedactor::text($message, [$this->config->accessToken, $this->apiKey]));
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
            'x-message-id: ' . self::messageId(),
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

        $this->dbg('Request', $method . ' ' . $url);
        $this->dbg('Headers', (string)json_encode(ThinQRedactor::headers($headers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($method !== 'GET') {
            $this->dbg('Body', ThinQRedactor::snippet((string)$body));
        }

        $reply = $this->transport->send($method, $url, $headers, $method !== 'GET' ? $body : null, 15);
        $result = $reply['body'];
        if ($result === false) {
            throw new Exception('HTTP error calling ' . $url . ': ' . ($reply['error'] !== '' ? $reply['error'] : 'unknown'));
        }

        $statusHeader = $reply['statusLine'];
        $statusCode = $reply['status'];

        $this->dbg('ResponseStatus', $statusCode . ' HeaderLine: ' . $statusHeader);
        $this->dbg('ResponseBody', ThinQRedactor::snippet((string)$result));

        // The status decides first: an error without a body is still an error, not an empty result.
        $decoded = json_decode($result, true);
        if ($statusCode >= 400) {
            $this->dbg('Error', 'HTTP error status ' . $statusCode . ' for URL: ' . $url);
            if (is_array($decoded) && isset($decoded['error'])) {
                $code = (string)($decoded['error']['code'] ?? 'unknown');
                $message = $decoded['error']['message'] ?? 'unknown';
                throw new ThinQApiException('HTTP ' . $statusCode . ' API error ' . $code . ': ' . $message . ' (' . $url . ')', $statusCode, $code);
            }
            $snippet = trim($result) === '' ? '(empty body)' : substr((string)preg_replace('/\s+/', ' ', $result), 0, 300);
            throw new ThinQApiException('HTTP ' . $statusCode . ' error from ' . $url . ': ' . $snippet, $statusCode);
        }
        if ($statusCode === 204 || trim($result) === '') {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $this->dbg('DecodedKeys', implode(',', array_keys($decoded)));

        return $decoded['response'] ?? $decoded;
    }

    /** x-message-id: 16 random bytes, base64url without padding */
    private static function messageId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}

/** An HTTP error answer of the LG API: HTTP status plus LG's own error code (e.g. "1207"), if any. */
final class ThinQApiException extends RuntimeException
{
    public int $httpStatus;
    public string $apiCode;

    public function __construct(string $message, int $httpStatus, string $apiCode = '')
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->apiCode = $apiCode;
    }
}
