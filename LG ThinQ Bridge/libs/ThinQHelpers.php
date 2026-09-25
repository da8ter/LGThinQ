<?php

declare(strict_types=1);

final class ThinQHelpers
{
    public static function generateMessageId(): string
    {
        $bytes = random_bytes(16);
        $base = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return $base;
    }

    public static function generateUUIDv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
