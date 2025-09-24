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
            $ttl = $this->config->normalizedEventTtlHours();
            $body = ['expire' => ['unit' => 'HOUR', 'timer' => $ttl]];
            $this->httpClient->request('POST', 'event/' . rawurlencode($deviceId) . '/subscribe', $body);
            $expiresAt = time() + ($ttl * 3600);
            $this->repository->updateExpiry($deviceId, $expiresAt);
            return true;
        } catch (Throwable $e) {
            $this->module->SendDebug('Event Subscribe', $e->getMessage(), 0);
            return false;
        }
    }

    public function unsubscribe(string $deviceId): bool
    {
        $ok = true;
        try {
            $this->httpClient->request('DELETE', 'event/' . rawurlencode($deviceId) . '/unsubscribe');
        } catch (Throwable $e) {
            $this->module->SendDebug('Event Unsubscribe', $e->getMessage(), 0);
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
