<?php

declare(strict_types=1);

final class ThinQDeviceRepository
{
    private IPSModule $module;

    public function __construct(IPSModule $module)
    {
        $this->module = $module;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        if (method_exists($this->module, 'GetDevicesCache')) {
            /** @var array<int, array<string, mixed>> $list */
            $list = $this->module->GetDevicesCache();
            return $list;
        }
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $devices
     */
    public function saveAll(array $devices): void
    {
        if (method_exists($this->module, 'SaveDevicesCache')) {
            $this->module->SaveDevicesCache($devices);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $deviceId): ?array
    {
        foreach ($this->getAll() as $device) {
            $id = (string)($device['deviceId'] ?? ($device['device_id'] ?? ''));
            if ($id === $deviceId) {
                return $device;
            }
        }
        return null;
    }
}
