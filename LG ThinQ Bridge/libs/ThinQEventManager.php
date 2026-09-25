<?php

declare(strict_types=1);

final class ThinQEventManager
{
    /** Seconds between two renewal checks of the EventRenewTimer. */
    public const CHECK_PERIOD = 600;

    private ThinQHttpClient $httpClient;
    private ThinQEventSubscriptionRepository $repository;
    private IPSModule $module;
    private ThinQBridgeConfig $config;

    public function __construct(
        IPSModule $module,
        ThinQBridgeConfig $config,
        ThinQHttpClient $httpClient,
        ThinQEventSubscriptionRepository $repository
    ) {
        $this->module = $module;
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->repository = $repository;
    }

    public function subscribe(string $deviceId, bool $force = false): bool
    {
        try {
            if (!$force && !$this->isDue($this->repository->getAll()[$deviceId] ?? null)) {
                return true;
            }
            $ttl = $this->config->normalizedEventTtlHours();
            $body = ['expire' => ['unit' => 'HOUR', 'timer' => $ttl]];
            $this->httpClient->request('POST', 'event/' . rawurlencode($deviceId) . '/subscribe', $body);
            $this->repository->updateExpiry($deviceId, ThinQClock::now() + ($ttl * 3600), $this->config->clientId);
            return true;
        } catch (Throwable $e) {
            @IPS_LogMessage('LG ThinQ Event', 'Subscribe error: ' . $e->getMessage());
            return false;
        }
    }

    public function unsubscribe(string $deviceId): bool
    {
        $ok = true;
        try {
            $this->httpClient->request('DELETE', 'event/' . rawurlencode($deviceId) . '/unsubscribe');
        } catch (Throwable $e) {
            @IPS_LogMessage('LG ThinQ Event', 'Unsubscribe error: ' . $e->getMessage());
            $ok = false;
        }
        $this->repository->remove($deviceId);
        return $ok;
    }

    public function renewExpiring(): void
    {
        foreach ($this->repository->getAll() as $deviceId => $entry) {
            $deviceId = (string)$deviceId;
            if ($deviceId !== '' && $this->isDue(is_array($entry) ? $entry : null)) {
                $this->subscribe($deviceId, true);
            }
        }
    }

    /**
     * Due once the remaining time falls into the renewal window, which always reaches past the next
     * check, or when the subscription was made under another client ID (LG publishes the events to
     * that client's topic). Depends only on absolute times, so re-arming the timer cannot cause a gap.
     *
     * @param array<string, mixed>|null $entry
     */
    private function isDue(?array $entry): bool
    {
        if ($entry === null) {
            return true;
        }
        $window = min($this->config->normalizedEventRenewLeadMinutes() * 60 + self::CHECK_PERIOD, intdiv($this->config->normalizedEventTtlHours() * 3600, 2));
        return (int)($entry['expiresAt'] ?? 0) - ThinQClock::now() <= $window || (string)($entry['clientId'] ?? '') !== $this->config->clientId;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listSubscriptions(): array
    {
        return $this->repository->getAll();
    }

    public function clear(): void
    {
        $this->repository->saveAll([]);
    }
}
