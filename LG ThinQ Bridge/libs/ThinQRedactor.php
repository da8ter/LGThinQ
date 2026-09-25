<?php

declare(strict_types=1);

/**
 * Keeps secrets out of debug output: the PAT and the API key, PEM blocks (also cut off, with
 * escaped line breaks, or base64-encoded the way the Client Socket stores them) and bearer tokens.
 * snippet() redacts before it truncates; a PEM block cut first would lose its END line and survive.
 */
final class ThinQRedactor
{
    /**
     * @param array<int, string> $headers "Name: value" lines
     * @return array<int, string>
     */
    public static function headers(array $headers): array
    {
        return array_map(static function (string $header): string {
            return preg_match('/^(authorization|x-api-key)\s*:/i', $header, $m) === 1 ? $m[1] . ': ***' : $header;
        }, $headers);
    }

    /** @param array<int, string> $secrets exact values to hide, e.g. the PAT */
    public static function text(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 8) {
                $text = str_replace($secret, self::mask($secret), $text);
            }
        }
        $text = (string)preg_replace_callback('/-----BEGIN ([A-Z0-9 ]+)-----(?:(?!-----END).)*(?:-----END \1-----)?/s',
            static fn(array $m): string => '[' . $m[1] . ', ' . strlen($m[0]) . ' B]', $text);
        // "LS0tLS1CRUdJTi" is base64 for "-----BEGIN "
        $text = (string)preg_replace_callback('/LS0tLS1CRUdJTi[A-Za-z0-9+\/=]*/',
            static fn(array $m): string => '[base64 PEM, ' . strlen($m[0]) . ' B]', $text);
        $text = (string)preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/', 'Bearer ***', $text);
        return (string)preg_replace('/thinqpat_[A-Za-z0-9_-]+/', 'thinqpat_***', $text);
    }

    /** @param array<int, string> $secrets */
    public static function snippet(string $text, int $max = 1000, array $secrets = []): string
    {
        $text = self::text((string)preg_replace('/\s+/', ' ', $text), $secrets);
        return strlen($text) > $max ? substr($text, 0, $max) . ' ...[truncated]' : $text;
    }

    public static function mask(string $secret): string
    {
        return strlen($secret) <= 4 ? '***' : substr($secret, 0, 2) . '…' . substr($secret, -2);
    }
}
