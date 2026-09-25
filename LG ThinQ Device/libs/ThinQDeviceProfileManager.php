<?php

declare(strict_types=1);

/**
 * ThinQDeviceProfileManager
 *
 * Extracted from LG ThinQ Device/module.php.
 * Handles device profile fetching, device-type resolution, profile storage helpers,
 * and last-status helpers.
 */
class ThinQDeviceProfileManager
{
    /** @var callable */
    private $sendActionCallback;
    /** @var callable */
    private $flattenCallback;

    public function __construct(
        private ThinQModuleContext $ctx,
        callable $sendActionCallback,
        callable $flattenCallback
    ) {
        $this->sendActionCallback = $sendActionCallback;
        $this->flattenCallback    = $flattenCallback;
    }

    /** The device profile in the stored form (ThinQShape::wrapProfile), [] when LG gave none. */
    public function fetchDeviceProfile(string $deviceId): array
    {
        try {
            $raw = ($this->sendActionCallback)('GetProfile', ['DeviceID' => $deviceId]);
            return ThinQShape::wrapProfile(json_decode((string)$raw, true)) ?? [];
        } catch (\Throwable $e) {
            $this->ctx->debug('FetchProfile', $e->getMessage());
            return [];
        }
    }

    public function resolveDeviceType(string $deviceId, array $profile): string
    {
        // Prefer fresh information from the device list each time
        $did = trim($deviceId);
        $this->ctx->debug('ResolveDeviceType', 'Begin: deviceId=' . $did);
        try {
            $listRaw = ($this->sendActionCallback)('GetDevices');
            $list    = json_decode((string)$listRaw, true);
            if (!is_array($list)) {
                $this->ctx->debug('ResolveDeviceType', 'GetDevices returned non-array payload');
            } else {
                $this->ctx->debug('ResolveDeviceType', 'Devices count=' . count($list));
                foreach ($list as $idx => $entry) {
                    if (!is_array($entry)) {
                        $this->ctx->debug('ResolveDeviceType', 'Entry #' . $idx . ' not an object');
                        continue;
                    }
                    $keys   = implode(',', array_keys($entry));
                    // Accept multiple id field variants
                    $candId = (string)($entry['deviceId'] ?? ($entry['device_id'] ?? ($entry['id'] ?? '')));
                    if ($candId === '') {
                        $this->ctx->debug('ResolveDeviceType', 'Entry #' . $idx . ' missing id fields; keys=' . $keys);
                        continue;
                    }
                    $match = (strcasecmp($candId, $did) === 0);
                    if (!$match) {
                        // Also allow substring match when IDs include prefixes/suffixes (rare)
                        $match = (strpos($candId, $did) !== false) || (strpos($did, $candId) !== false);
                    }
                    if (!$match) {
                        continue;
                    }

                    // Found matching device entry: try different type fields
                    $typeDirect = (string)($entry['deviceType'] ?? '');
                    $typeInfo   = '';
                    if (isset($entry['deviceInfo']) && is_array($entry['deviceInfo'])) {
                        $typeInfo = (string)($entry['deviceInfo']['deviceType'] ?? '');
                    }
                    $type = $typeDirect !== '' ? $typeDirect : $typeInfo;
                    $this->ctx->debug('ResolveDeviceType', sprintf(
                        'Match at #%d: id=%s typeDirect=%s typeInfo=%s',
                        $idx,
                        substr($candId, 0, 8) . '…',
                        $typeDirect,
                        $typeInfo
                    ));
                    if ($type !== '') {
                        return $type;
                    }
                    $this->ctx->debug('ResolveDeviceType', 'Matching entry has no deviceType fields; keys=' . $keys);
                }
            }
        } catch (\Throwable $e) {
            $this->ctx->debug('ResolveDeviceType', $e->getMessage());
        }

        // No fallback to profile or AC by request — return empty to surface the issue upstream
        $this->ctx->debug('ResolveDeviceType', 'FAILED to resolve device type from device list; returning empty');
        return '';
    }

    /** The stored profile; copies an earlier version stored without the property wrapper are repaired. */
    public function readStoredProfile(): array
    {
        return ThinQShape::wrapProfile(json_decode((string)$this->ctx->attributeString('LastProfile'), true)) ?? [];
    }

    /** Fresh profile from the API in the stored form, [] on failure. */
    public function fetchProfileFromAPI(): array
    {
        $deviceId = trim($this->ctx->propertyString('DeviceID'));
        return $deviceId === '' ? [] : $this->fetchDeviceProfile($deviceId);
    }

    public function readLastStatus(): array
    {
        $raw    = (string)$this->ctx->attributeString('LastStatus');
        $status = json_decode($raw, true);
        return is_array($status) ? $status : [];
    }
}
