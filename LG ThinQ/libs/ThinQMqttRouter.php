<?php

declare(strict_types=1);

final class ThinQMqttRouter
{
    private IPSModule $module;
    private ThinQBridgeConfig $config;
    private ThinQEventPipeline $pipeline;

    public function __construct(IPSModule $module, ThinQBridgeConfig $config, ThinQEventPipeline $pipeline)
    {
        $this->module = $module;
        $this->config = $config;
        $this->pipeline = $pipeline;
    }

    public function handle(string $json): void
    {
        $this->module->SendDebug('ReceiveData', $json, 0);
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return;
        }

        $env = $this->extractEnvelope($raw);
        $topic = (string)($env['Topic'] ?? ($env['topic'] ?? ''));
        $retain = (bool)($env['Retain'] ?? ($env['retain'] ?? false));
        if ($this->config->ignoreRetained && $retain) {
            $this->module->SendDebug('MQTT', 'Ignore retained: ' . $topic, 0);
            return;
        }

        $filter = (string)$this->config->mqttTopicFilter;
        if ($filter !== '' && !$this->topicMatches($topic, $filter)) {
            return;
        }

        $payloadRaw = $env['Payload'] ?? ($env['payload'] ?? null);
        $payload = [];
        if (is_string($payloadRaw)) {
            $payload = json_decode($payloadRaw, true) ?? [];
        } elseif (is_array($payloadRaw)) {
            $payload = $payloadRaw;
        }
        if (!is_array($payload)) {
            $this->module->SendDebug('MQTT', 'Invalid payload on topic ' . $topic, 0);
            return;
        }

        $eventNode = isset($payload['event']) && is_array($payload['event']) ? $payload['event'] : null;
        $pushNode = isset($payload['push']) && is_array($payload['push']) ? $payload['push'] : null;
        $topType = strtoupper((string)($payload['pushType'] ?? ($payload['type'] ?? '')));

        if ($eventNode || $topType === 'DEVICE_STATUS') {
            $node = $eventNode ?: $payload;
            $deviceId = (string)($node['deviceId'] ?? ($node['device_id'] ?? ''));
            $report = $node['report'] ?? null;
            if ($deviceId !== '' && is_array($report)) {
                $this->pipeline->dispatchEvent($deviceId, $report);
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

        $this->module->SendDebug('MQTT', 'Unknown message type on topic ' . $topic, 0);
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

    private function topicMatches(string $topic, string $filter): bool
    {
        $filter = trim($filter);
        if ($filter === '') {
            return true;
        }
        $escaped = preg_quote($filter, '/');
        $pattern = '/^' . str_replace(['\\*', '\\#'], '.*', $escaped) . '$/i';
        return (bool)preg_match($pattern, $topic);
    }
}
