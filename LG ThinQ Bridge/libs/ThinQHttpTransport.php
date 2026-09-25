<?php

declare(strict_types=1);

// The one place where the Bridge talks to the network (LG API and the Amazon root CA).
// The test bench loads its own ThinQHttpTransport first; the class_exists guard then
// skips this one, so every request of the real ThinQHttpClient reaches the fake LG cloud.
if (!class_exists('ThinQHttpTransport')) {
    final class ThinQHttpTransport
    {
        /**
         * @param array<int, string> $headers
         * @return array{status: int, statusLine: string, body: string|false, error: string}
         */
        public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSec): array
        {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'ignore_errors' => true,
                    'timeout' => $timeoutSec,
                    'protocol_version' => 1.1,
                    'content' => $body
                ]
            ]);

            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                $error = error_get_last();
                return ['status' => 0, 'statusLine' => '', 'body' => false, 'error' => (string)($error['message'] ?? 'unknown')];
            }

            $responseHeaders = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : ($http_response_header ?? []);
            $statusLine = (string)($responseHeaders[0] ?? '');
            $status = 0;
            if (preg_match('/HTTP\/[0-9.]+\s+(\d+)/', $statusLine, $match)) {
                $status = (int)$match[1];
            }
            return ['status' => $status, 'statusLine' => $statusLine, 'body' => $result, 'error' => ''];
        }

        /**
         * Plain GET of a public text resource (curl preferred, stream fallback).
         * Returns '' unless $accept approves the body.
         */
        public function fetchText(string $url, int $timeoutSec, callable $accept): string
        {
            if (function_exists('curl_init')) {
                $ch = @curl_init($url);
                if ($ch !== false) {
                    @curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT => $timeoutSec,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_USERAGENT => 'LGThinQBridge/1.0 (+Symcon)',
                    ]);
                    $resp = @curl_exec($ch);
                    $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    @curl_close($ch);
                    if (is_string($resp) && $code === 200 && $accept($resp)) {
                        return $resp;
                    }
                }
            }
            $ctx = @stream_context_create([
                'http'  => ['timeout' => $timeoutSec, 'method' => 'GET', 'header' => "User-Agent: LGThinQBridge/1.0\r\n"],
                'https' => ['timeout' => $timeoutSec],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            if (is_string($resp) && $accept($resp)) {
                return $resp;
            }
            return '';
        }
    }
}
