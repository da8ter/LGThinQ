<?php

declare(strict_types=1);

/*
 * Symcon's MQTT Client for the test bench (a plain instance without PHP module). Only topics
 * the client subscribed to (property Subscriptions, as the setup wizard writes it) reach its
 * children, in the envelope Symcon uses: DataID, PacketType, QualityOfService, Retain, Topic,
 * Payload. How the payload travels depends on the child, as in Symcon 9.1: an IPSModule child
 * gets it as text (the Bridge up to 0.1.7 decoded it with json_decode, log 20.–25.09.2026), an
 * IPSModuleStrict child gets it hex-encoded (measured live on 25.09.2026 after the switch; the
 * zigbee2mqtt module: "utf8_decode bei IPSModule, und hex2bin ab IPSModuleStrict").
 */
final class FakeMqttClient
{
    public const MODULE_ID = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';
    public const RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    public array $published = [];

    public function __construct(public int $id)
    {
    }

    public function ForwardData(string $json): string
    {
        $this->published[] = json_decode($json, true);
        return '';
    }

    public function deliver(string $topic, string $payload, bool $retain = false): bool
    {
        if (!$this->subscribedTo($topic)) {
            return false;
        }
        $envelope = static fn(string $encoded): string => (string)json_encode(['DataID' => self::RX, 'PacketType' => 3, 'QualityOfService' => 0,
            'Retain' => $retain, 'Topic' => $topic, 'Payload' => $encoded], JSON_UNESCAPED_SLASHES);
        Kernel::sendToChildren($this->id, $envelope($payload),
            static fn(object $child): string => $envelope($child instanceof IPSModuleStrict ? bin2hex($payload) : $payload));
        return true;
    }

    public function subscribedTo(string $topic): bool
    {
        $subs = json_decode((string)(Kernel::$instances[$this->id]['properties']['Subscriptions'] ?? '[]'), true);
        foreach ((array)$subs as $s) {
            if (self::topicMatch((string)($s['Topic'] ?? ''), $topic)) {
                return true;
            }
        }
        return false;
    }

    /** MQTT 3.1.1 topic filter: + one level, # the rest. */
    public static function topicMatch(string $filter, string $topic): bool
    {
        $f = explode('/', $filter);
        $t = explode('/', $topic);
        foreach ($f as $i => $part) {
            if ($part === '#') {
                return true;
            }
            if (!array_key_exists($i, $t) || ($part !== '+' && $part !== $t[$i])) {
                return false;
            }
        }
        return count($f) === count($t);
    }
}
