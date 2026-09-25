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
}
