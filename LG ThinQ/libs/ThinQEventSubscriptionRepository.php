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
        $raw = (string)$this->module->ReadAttributeString('EventSubscriptions');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array<string, mixed>> $subs
     */
    public function saveAll(array $subs): void
    {
        $this->module->WriteAttributeString('EventSubscriptions', json_encode($subs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
