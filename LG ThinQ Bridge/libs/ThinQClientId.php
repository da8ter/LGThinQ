<?php

declare(strict_types=1);

/**
 * The client ID LG knows this Bridge by: x-client-id of every request, certificate CN and the
 * MQTT topic app/clients/<client id>/push. It must be the ClientID of the connected MQTT Client.
 */
final class ThinQClientId
{
    public static function generate(): string
    {
        try {
            $digits = random_int(0, 99999);
        } catch (\Throwable $e) {
            $digits = mt_rand(0, 99999);
        }
        return 'Symcon' . str_pad((string)$digits, 5, '0', STR_PAD_LEFT);
    }

    /** Characters a certificate CN and an MQTT client ID can carry. */
    public static function sanitize(string $clientId): string
    {
        return (string)preg_replace('/[^A-Za-z0-9._-]/', '_', trim($clientId));
    }

    /** ClientID property of the instance's parent (the MQTT Client), '' if there is none. */
    public static function ofParent(int $instanceId): string
    {
        $info = @IPS_GetInstance($instanceId);
        $parentId = is_array($info) ? (int)($info['ConnectionID'] ?? 0) : 0;
        if ($parentId <= 0) {
            return '';
        }
        $config = json_decode((string)@IPS_GetConfiguration($parentId), true);
        return is_array($config) ? trim((string)($config['ClientID'] ?? '')) : '';
    }
}
