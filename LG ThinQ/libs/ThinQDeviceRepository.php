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
        $raw = (string)$this->module->ReadAttributeString('Devices');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int, array<string, mixed>> $devices
     */
    public function saveAll(array $devices): void
    {
        $this->module->WriteAttributeString('Devices', json_encode($devices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
