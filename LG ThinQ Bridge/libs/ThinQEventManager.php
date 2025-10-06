<?php

declare(strict_types=1);

final class ThinQEventManager
{
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

    public function subscribe(string $deviceId): bool
    {
        try {
            // Idempotency guard: if a current subscription exists and is not close to expiry, skip re-subscribing
            $leadSeconds = $this->config->normalizedEventRenewLeadMinutes() * 60;
            $subs = $this->repository->getAll();
            $current = $subs[$deviceId] ?? null;
            if (is_array($current)) {
                $expiresAt = (int)($current['expiresAt'] ?? 0);
                if ($expiresAt > 0 && $expiresAt > (time() + $leadSeconds)) {
                    // Still valid beyond renew lead window; no API call needed
                    return true;
                }
            }

            $ttl = $this->config->normalizedEventTtlHours();
            $body = ['expire' => ['unit' => 'HOUR', 'timer' => $ttl]];
            $this->httpClient->request('POST', 'event/' . rawurlencode($deviceId) . '/subscribe', $body);
            $expiresAt = time() + ($ttl * 3600);
            $this->repository->updateExpiry($deviceId, $expiresAt);
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
        $subs = $this->repository->getAll();
        $leadSeconds = $this->config->normalizedEventRenewLeadMinutes() * 60;
        $now = time();
        foreach (array_keys($subs) as $deviceId) {
            $deviceId = (string)$deviceId;
            if ($deviceId === '') {
                continue;
            }
            $expiresAt = (int)($subs[$deviceId]['expiresAt'] ?? 0);
            if ($expiresAt === 0 || ($expiresAt - $leadSeconds) <= $now) {
                $this->subscribe($deviceId);
            }
        }
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
