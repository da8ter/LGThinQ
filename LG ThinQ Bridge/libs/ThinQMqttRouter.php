<?php

declare(strict_types=1);

final class ThinQMqttRouter
{
    private ThinQModuleContext $ctx;
    private ThinQBridgeConfig $config;
    private ThinQEventPipeline $pipeline;

    public function __construct(ThinQModuleContext $ctx, ThinQBridgeConfig $config, ThinQEventPipeline $pipeline)
    {
        $this->ctx = $ctx;
        $this->config = $config;
        $this->pipeline = $pipeline;
    }

    public function handle(string $json): void
    {
        if ($this->config->debug) {
            $this->ctx->debug('ReceiveData', $json);
        }
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return;
        }

        $env = $this->extractEnvelope($raw);
        $topic = (string)($env['Topic'] ?? ($env['topic'] ?? ''));
        $retain = (bool)($env['Retain'] ?? ($env['retain'] ?? false));
        if ($this->config->ignoreRetained && $retain) {
            if ($this->config->debug) {
                $this->ctx->debug('MQTT', 'Ignore retained: ' . $topic);
            }
            return;
        }

        $filter = $this->config->topicFilter();
        if ($filter !== '' && !self::topicMatches($filter, $topic)) {
            if ($this->config->debug) {
                $this->ctx->debug('MQTT', 'Filter miss: topic=' . $topic . ' filter=' . $filter);
            }
            return;
        }

        $payloadRaw = $env['Payload'] ?? ($env['payload'] ?? null);
        if (is_string($payloadRaw) && $payloadRaw !== '' && strlen($payloadRaw) % 2 === 0 && ctype_xdigit($payloadRaw)) {
            // An IPSModuleStrict child gets the payload from Symcon's MQTT Client hex-encoded (9.1),
            // an IPSModule child as text; JSON itself is never pure hex
            $payloadRaw = (string)hex2bin($payloadRaw);
        }
        $payload = [];
        if (is_string($payloadRaw)) {
            $payload = json_decode($payloadRaw, true) ?? [];
        } elseif (is_array($payloadRaw)) {
            $payload = $payloadRaw;
        }
        if (!is_array($payload)) {
            $this->ctx->debug('MQTT', 'Invalid payload on topic ' . $topic);
            return;
        }

        $eventNode = isset($payload['event']) && is_array($payload['event']) ? $payload['event'] : null;
        $pushNode = isset($payload['push']) && is_array($payload['push']) ? $payload['push'] : null;
        $topType = strtoupper((string)($payload['pushType'] ?? ($payload['type'] ?? '')));

        // Primary path: explicit event node, or DEVICE_STATUS payloads
        if ($eventNode || $topType === 'DEVICE_STATUS') {
            $node = $eventNode ?: $payload;
            $deviceId = (string)($node['deviceId'] ?? ($node['device_id'] ?? ''));
            // Extract report/state flexibly
            $report = null;
            if (isset($node['report']) && is_array($node['report'])) {
                $report = $node['report'];
            } elseif (isset($node['state']) && is_array($node['state'])) {
                $report = $node['state'];
            } elseif (isset($node['data']) && is_array($node['data'])) {
                $data = $node['data'];
                if (isset($data['report']) && is_array($data['report'])) {
                    $report = $data['report'];
                } elseif (isset($data['state']) && is_array($data['state'])) {
                    $report = $data['state'];
                }
            }

            if ($deviceId !== '' && is_array($report)) {
                $this->pipeline->dispatchEvent($deviceId, $report);
                return;
            }
            if ($this->config->debug) {
                $haveKeys = implode(',', array_keys($node));
                $this->ctx->debug('MQTT', 'DEVICE_STATUS/event without usable report/state for deviceId=' . $deviceId . ' keys=[' . $haveKeys . ']');
            }
            return;
        }

        if ($pushNode || in_array($topType, ['DEVICE_REGISTERED', 'DEVICE_UNREGISTERED', 'DEVICE_ALIAS_CHANGED', 'DEVICE_PUSH'], true)) {
            $node = $pushNode ?: $payload;
            $type = strtoupper((string)($node['pushType'] ?? $topType));
            $deviceId = (string)($node['deviceId'] ?? ($node['device_id'] ?? ''));
            if ($deviceId !== '') {
                $this->pipeline->dispatchMeta($type, $deviceId, is_array($node) ? $node : []);
            }
            return;
        }

        $this->ctx->debug('MQTT', 'Unknown message type on topic ' . $topic);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function extractEnvelope(array $raw): array
    {
        if (!isset($raw['Buffer'])) {
            return $raw;
        }
        $env = $raw;
        $buffer = $raw['Buffer'];
        if (is_string($buffer)) {
            $decoded = json_decode($buffer, true);
            if (is_array($decoded)) {
                $env = array_merge($env, $decoded);
            }
        } elseif (is_array($buffer)) {
            $env = array_merge($env, $buffer);
        }
        return $env;
    }

    /**
     * MQTT filter match, level by level: "+" (and the older "*") stands for one level, "#" for the
     * rest including the parent level. Levels compare case-insensitively, as before.
     */
    public static function topicMatches(string $filter, string $topic): bool
    {
        $levels = explode('/', $filter);
        $parts = explode('/', $topic);
        foreach ($levels as $i => $level) {
            if ($level === '#') {
                return $i === count($levels) - 1;
            }
            if (!array_key_exists($i, $parts)) {
                return false;
            }
            if ($level !== '+' && $level !== '*' && strcasecmp($level, $parts[$i]) !== 0) {
                return false;
            }
        }
        return count($levels) === count($parts);
    }
}
