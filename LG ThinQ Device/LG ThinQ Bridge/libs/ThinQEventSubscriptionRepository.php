<?php

declare(strict_types=1);

final class ThinQEventSubscriptionRepository
{
    private IPSModule $module;

    public function __construct(IPSModule $module)
    {
        $this->module = $module;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAll(): array
    {
        if (method_exists($this->module, 'GetEventSubscriptionsCache')) {
            /** @var array<string, array<string, mixed>> $subs */
            $subs = $this->module->GetEventSubscriptionsCache();
            return $subs;
        }
        return [];
    }

    /**
     * @param array<string, array<string, mixed>> $subs
     */
    public function saveAll(array $subs): void
    {
        if (method_exists($this->module, 'SaveEventSubscriptionsCache')) {
            $this->module->SaveEventSubscriptionsCache($subs);
        }
    }

    public function updateExpiry(string $deviceId, int $expiresAt): void
    {
        $subs = $this->getAll();
        $subs[$deviceId]['expiresAt'] = $expiresAt;
        $this->saveAll($subs);
    }

    public function remove(string $deviceId): void
    {
        $subs = $this->getAll();
        if (isset($subs[$deviceId])) {
            unset($subs[$deviceId]);
            $this->saveAll($subs);
        }
    }
}
