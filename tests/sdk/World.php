<?php

declare(strict_types=1);

const BRIDGE_GUID = '{FCD02091-9189-0B0A-0C70-D607F1941C05}';
const DEVICE_GUID = '{B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}';
const CONFIGURATOR_GUID = '{7C4B0F16-7E13-4B44-9E2C-1B9C2C6B3B0F}';
const CLIENT_SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
const MQTT_SERVER_GUID = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

/*
 * A standard test world: kernel, LG cloud, the MQTT Client as the setup wizard leaves it and
 * a configured Bridge. Devices are created the way the configurator does it.
 */
final class World
{
    public static FakeThinQCloud $cloud;
    public static int $mqtt = 0;
    public static int $bridge = 0;
    public static string $clientId = 'Symcon12345';

    /** Built-in Symcon modules the LG modules talk to. Property names and interfaces measured in 9.1. */
    public static function registerSymconModules(): void
    {
        Kernel::registerModule(['ModuleID' => FakeMqttClient::MODULE_ID, 'ModuleName' => 'MQTT Client', 'ModuleType' => 2,
            'Implemented' => ['{018EF6B5-AB94-40C6-AA53-46943E824ACF}', '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'],
            'ParentRequirements' => ['{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'], 'ChildRequirements' => [FakeMqttClient::RX],
            'defaults' => ['ClientID' => '', 'KeepAliveInterval' => 60, 'Password' => '', 'Subscriptions' => '[{"Topic":"#", "QoS": 0}]', 'UserName' => '']]);
        Kernel::registerModule(['ModuleID' => MQTT_SERVER_GUID, 'ModuleName' => 'MQTT Server', 'ModuleType' => 2,
            'Implemented' => ['{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}', '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'],
            'ParentRequirements' => ['{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}'], 'ChildRequirements' => [FakeMqttClient::RX]]);
        Kernel::registerModule(['ModuleID' => CLIENT_SOCKET_GUID, 'ModuleName' => 'Client Socket', 'ModuleType' => 1,
            'Implemented' => ['{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'],
            'defaults' => ['Certificate' => '', 'CertificateAuthority' => '', 'Host' => '', 'Open' => false, 'Password' => '',
                'Port' => 0, 'PrivateKey' => '', 'UseCertificate' => false, 'UseSSL' => false, 'VerifyHost' => true, 'VerifyPeer' => true]]);
    }

    /**
     * @param array<string, mixed> $bridgeProps overrides for the Bridge configuration
     * @param bool $concreteTopic true: MQTTTopicFilter holds the concrete topic like the live Bridge;
     *                            false: the registered default 'app/clients/{ClientID}/push' of a fresh install
     */
    public static function start(array $bridgeProps = [], bool $concreteTopic = true): void
    {
        Kernel::reset();
        self::registerSymconModules();
        Kernel::loadLibrary(dirname(__DIR__, 2));
        self::$cloud = new FakeThinQCloud();
        self::$cloud->apiKey = LGThinQBridge::API_KEY;
        self::$mqtt = self::mqttClient(self::$clientId);
        self::$bridge = IPS_CreateInstance(BRIDGE_GUID);
        IPS_ConnectInstance(self::$bridge, self::$mqtt);
        $props = $bridgeProps + ['AccessToken' => self::$cloud->pat, 'CountryCode' => 'DE', 'ClientID' => self::$clientId,
            'MQTTClientID' => self::$mqtt];
        if ($concreteTopic) {
            $props += ['MQTTTopicFilter' => 'app/clients/' . self::$clientId . '/push'];
        }
        foreach ($props as $name => $value) {
            IPS_SetProperty(self::$bridge, $name, $value);
        }
        IPS_ApplyChanges(self::$bridge);
    }

    public static function mqttClient(string $clientId): int
    {
        $id = IPS_CreateInstance(FakeMqttClient::MODULE_ID);
        Kernel::$instances[$id]['handler'] = new FakeMqttClient($id);
        IPS_SetProperty($id, 'ClientID', $clientId);
        IPS_SetProperty($id, 'Subscriptions', (string)json_encode([['Topic' => 'app/clients/' . $clientId . '/push', 'QoS' => 0]]));
        IPS_ApplyChanges($id);
        return $id;
    }

    /** Like the configurator: IPS_CreateInstance (Create() connects to the Bridge), then DeviceID and Alias. */
    public static function addDevice(string $deviceId, ?string $alias = null): int
    {
        $id = IPS_CreateInstance(DEVICE_GUID);
        IPS_SetProperty($id, 'DeviceID', $deviceId);
        IPS_SetProperty($id, 'Alias', $alias ?? (string)(self::$cloud->devices[$deviceId]['info']['alias'] ?? ''));
        IPS_ApplyChanges($id);
        return $id;
    }

    /** @return array{0: int, 1: string} instance and deviceId of an LG example device */
    public static function example(string $type): array
    {
        $deviceId = self::$cloud->addExampleDevice($type);
        return [self::addDevice($deviceId), $deviceId];
    }

    /** @return array{0: int, 1: string} the live air conditioner */
    public static function liveAc(): array
    {
        $deviceId = self::$cloud->addLiveAc();
        return [self::addDevice($deviceId), $deviceId];
    }

    /** Hands every queued LG MQTT message to the MQTT clients; returns how many reached a client. */
    public static function flushMqtt(): int
    {
        $n = 0;
        foreach (self::$cloud->drainMqtt() as $msg) {
            foreach (IPS_GetInstanceListByModuleID(FakeMqttClient::MODULE_ID) as $mid) {
                $h = Kernel::$instances[$mid]['handler'] ?? null;
                if ($h instanceof FakeMqttClient && $h->deliver($msg['topic'], (string)json_encode($msg['message'], JSON_UNESCAPED_SLASHES))) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /** Sends a raw MQTT message (topic, payload array) through the Bridge's MQTT client. */
    public static function mqtt(string $topic, array $payload, bool $retain = false): bool
    {
        return Kernel::$instances[self::$mqtt]['handler']->deliver($topic, (string)json_encode($payload, JSON_UNESCAPED_SLASHES), $retain);
    }

    public static function topic(): string
    {
        return 'app/clients/' . self::$clientId . '/push';
    }

    // ------------------------------------------------------------------ inspection

    public static function varId(int $instance, string $ident): int
    {
        return Kernel::findIdent($instance, $ident);
    }

    public static function value(int $instance, string $ident): mixed
    {
        $vid = Kernel::findIdent($instance, $ident);
        return $vid > 0 ? Kernel::$variables[$vid]['value'] : null;
    }

    public static function variable(int $instance, string $ident): ?array
    {
        $vid = Kernel::findIdent($instance, $ident);
        return $vid > 0 ? Kernel::$variables[$vid] + ['name' => Kernel::$objects[$vid]['name'], 'id' => $vid] : null;
    }

    /** @return array<int, string> idents of the instance's variables */
    public static function idents(int $instance): array
    {
        $out = [];
        foreach (Kernel::children($instance) as $cid) {
            if (isset(Kernel::$variables[$cid])) {
                $out[] = Kernel::$objects[$cid]['ident'];
            }
        }
        sort($out);
        return $out;
    }

    public static function attr(int $instance, string $name): mixed
    {
        return Kernel::$instances[$instance]['attributes'][$name] ?? null;
    }

    public static function prop(int $instance, string $name): mixed
    {
        return Kernel::$instances[$instance]['properties'][$name] ?? null;
    }

    public static function timer(int $instance, string $name): ?array
    {
        return Kernel::$instances[$instance]['timers'][$name] ?? null;
    }

    public static function debugText(int $instance): string
    {
        return implode("\n", array_map(static fn(array $l): string => $l[0] . ': ' . $l[1], Kernel::$debug[$instance] ?? []));
    }

    /** @return array<int, array> log lines matching a regex on sender|text */
    public static function logLines(string $regex): array
    {
        return array_values(array_filter(Kernel::$log, static fn(array $l): bool => preg_match($regex, $l['sender'] . ' | ' . $l['text']) === 1));
    }

    public static function warningsLike(string $regex): array
    {
        return array_values(array_filter(Kernel::$warnings, static fn(string $w): bool => preg_match($regex, $w) === 1));
    }

    /** Clears log, debug output, warnings and the request log (e.g. after the setup phase). */
    public static function quiet(): void
    {
        Kernel::$log = Kernel::$debug = Kernel::$warnings = [];
        self::$cloud->requests = [];
    }
}
