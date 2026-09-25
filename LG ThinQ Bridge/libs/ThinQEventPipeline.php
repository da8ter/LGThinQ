<?php

declare(strict_types=1);

final class ThinQEventPipeline
{
    /** @var array<int, callable> */
    private array $eventHandlers = [];
    /** @var array<int, callable> */
    private array $metaHandlers = [];

    public function onEvent(callable $handler): void
    {
        $this->eventHandlers[] = $handler;
    }

    public function onMeta(callable $handler): void
    {
        $this->metaHandlers[] = $handler;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchEvent(string $deviceId, array $payload): void
    {
        foreach ($this->eventHandlers as $handler) {
            $handler($deviceId, $payload);
        }
    }

    public function dispatchMeta(string $type, string $deviceId, array $payload): void
    {
        foreach ($this->metaHandlers as $handler) {
            $handler($type, $deviceId, $payload);
        }
    }
}
