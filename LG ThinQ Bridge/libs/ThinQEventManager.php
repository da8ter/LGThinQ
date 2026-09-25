<?php

declare(strict_types=1);

final class ThinQEventManager
{
    /** Seconds between two renewal checks of the EventRenewTimer. */
    public const CHECK_PERIOD = 600;

    private ThinQHttpClient $httpClient;
    private ThinQEventSubscriptionRepository $repository;
    private ThinQBridgeConfig $config;

    public function __construct(
        ThinQBridgeConfig $config,
        ThinQHttpClient $httpClient,
        ThinQEventSubscriptionRepository $repository
    ) {
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
            // Retry after an hour; only the first failure of a series goes to the message log.
            $entry = $this->repository->getAll()[$deviceId] ?? [];
            if (!isset($entry['failedSince'])) {
                @IPS_LogMessage('LG ThinQ Event', 'Subscribe error: ' . $e->getMessage());
            }
            $this->repository->update($deviceId, ['failedSince' => (int)($entry['failedSince'] ?? ThinQClock::now()), 'retryAt' => ThinQClock::now() + 3600]);
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

    /**
     * Renews what is due for the given devices (the Device instances of this Bridge). Entries of
     * other devices are no longer renewed and are dropped once they have expired.
     *
     * @param array<int, string> $deviceIds
     */
    public function renewExpiring(array $deviceIds): void
    {
        foreach ($this->repository->getAll() as $deviceId => $entry) {
            $deviceId = (string)$deviceId;
            $entry = is_array($entry) ? $entry : [];
            if (!in_array($deviceId, $deviceIds, true)) {
                if ((int)($entry['expiresAt'] ?? 0) <= ThinQClock::now()) {
                    $this->repository->remove($deviceId);
                }
                continue;
            }
            if ($this->isDue($entry)) {
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
        if ((int)($entry['retryAt'] ?? 0) > ThinQClock::now()) {
            return false;
        }
        $window = min($this->config->normalizedEventRenewLeadMinutes() * 60 + self::CHECK_PERIOD, intdiv($this->config->normalizedEventTtlHours() * 3600, 2));
        return (int)($entry['expiresAt'] ?? 0) - ThinQClock::now() <= $window || (string)($entry['clientId'] ?? '') !== $this->config->clientId;
    }
}
