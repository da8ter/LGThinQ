<?php

declare(strict_types=1);

// Loaded before the Bridge: the class_exists guard in "LG ThinQ Bridge/libs/ThinQHttpTransport.php"
// then keeps this class, so the real ThinQHttpClient (headers, logging, status handling) talks to
// FakeThinQCloud instead of the network. The real transport has its own test (transport_test.php).
final class ThinQHttpTransport
{
    /** @return array{status: int, statusLine: string, body: string|false, error: string} */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSec): array
    {
        $cloud = FakeThinQCloud::$current;
        if ($cloud === null) {
            return ['status' => 0, 'statusLine' => '', 'body' => false, 'error' => 'Prüfstand: keine LG-Cloud angelegt'];
        }
        return $cloud->handle($method, $url, $headers, $body);
    }

    public function fetchText(string $url, int $timeoutSec, callable $accept): string
    {
        $text = FakeThinQCloud::$current?->download($url) ?? '';
        return $text !== '' && $accept($text) ? $text : '';
    }
}
