<?php

declare(strict_types=1);

/** Reading and configuring the Symcon instances of the MQTT connection (MQTT Client, Client Socket). */
final class ThinQMqttInstances
{
    public const MQTT_CLIENT_GUID = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';
    /** Configuration keys whose values are never secret; all others are shown by name only. */
    private const SHOWN = ['ClientID', 'Host', 'Port', 'Open', 'UseSSL', 'KeepAliveInterval', 'Subscriptions'];

    /** @return array<string, mixed> */
    public static function config(int $id): array
    {
        $data = json_decode((string)@IPS_GetConfiguration($id), true);
        return is_array($data) ? $data : [];
    }

    /** Sets a property only if the instance has it (property names differ between Symcon versions). */
    public static function set(int $id, string $property, mixed $value): bool
    {
        if (!array_key_exists($property, self::config($id))) {
            return false;
        }
        @IPS_SetProperty($id, $property, $value);
        return true;
    }

    /** @param array<int, string> $properties the first one the instance has gets the value */
    public static function setFirst(int $id, array $properties, mixed $value): void
    {
        foreach ($properties as $property) {
            if (self::set($id, $property, $value)) {
                return;
            }
        }
    }

    /** Sets a list property; as JSON text if the instance keeps it as text. */
    public static function setList(int $id, string $property, array $value): bool
    {
        $config = self::config($id);
        if (!array_key_exists($property, $config)) {
            return false;
        }
        @IPS_SetProperty($id, $property, is_string($config[$property]) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value);
        return true;
    }

    public static function connectionOf(int $id): int
    {
        $info = @IPS_GetInstance($id);
        return is_array($info) ? (int)($info['ConnectionID'] ?? 0) : 0;
    }

    public static function moduleGuidByName(string $name): ?string
    {
        foreach (@IPS_GetModuleList() as $guid) {
            $module = @IPS_GetModule($guid);
            if (!is_array($module)) {
                continue;
            }
            foreach (array_merge([$module['ModuleName'] ?? ''], $module['Aliases'] ?? []) as $candidate) {
                if (mb_strtolower((string)$candidate) === mb_strtolower($name)) {
                    return (string)$guid;
                }
            }
        }
        return null;
    }

    /** @return array<int, int> */
    public static function instancesOf(string $moduleName): array
    {
        $guid = self::moduleGuidByName($moduleName);
        return $guid !== null ? @IPS_GetInstanceListByModuleID($guid) : [];
    }

    public static function isInstanceOf(int $id, string $moduleName): bool
    {
        return in_array($id, self::instancesOf($moduleName), true);
    }

    /** First instance of the module whose object ident is one of $idents, 0 if none. */
    public static function byIdent(array $instanceIds, array $idents): int
    {
        foreach ($instanceIds as $id) {
            $object = @IPS_GetObject($id);
            if (is_array($object) && in_array((string)($object['ObjectIdent'] ?? ''), $idents, true)) {
                return $id;
            }
        }
        return 0;
    }

    /** Module, status and configuration keys of an instance; values only for keys that never hold a secret. */
    public static function describe(int $id): string
    {
        $info = @IPS_GetInstance($id);
        $keys = [];
        foreach (self::config($id) as $key => $value) {
            $keys[] = in_array($key, self::SHOWN, true) ? $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$key;
        }
        return sprintf('#%d module=%s status=%s config: %s', $id, is_array($info) ? (string)($info['ModuleInfo']['ModuleID'] ?? '') : '',
            is_array($info) ? (string)($info['InstanceStatus'] ?? '') : '', implode(', ', $keys));
    }
}
