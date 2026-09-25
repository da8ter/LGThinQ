<?php

declare(strict_types=1);

/*
 * Symcon's MQTT Client for the test bench (a plain instance without PHP module). Only topics
 * the client subscribed to (property Subscriptions, as the setup wizard writes it) reach its
 * children, in the envelope Symcon uses: DataID, PacketType, QualityOfService, Retain, Topic,
 * Payload. The payload travels as text: the live Bridge decodes it with json_decode and gets
 * values (log 20.–25.09.2026). The MQTT Server sends hex according to sibling modules
 * (evccMQTT, HaSync) — $hexPayload replays that.
 */
final class FakeMqttClient
{
    public const MODULE_ID = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';
    public const RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    public array $published = [];
    public bool $hexPayload = false;

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
        Kernel::sendToChildren($this->id, (string)json_encode(['DataID' => self::RX, 'PacketType' => 3, 'QualityOfService' => 0,
            'Retain' => $retain, 'Topic' => $topic, 'Payload' => $this->hexPayload ? bin2hex($payload) : $payload], JSON_UNESCAPED_SLASHES));
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
