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

    public function fetchDeviceProfile(string $deviceId): array
    {
        try {
            $raw  = ($this->sendActionCallback)('GetProfile', ['DeviceID' => $deviceId]);
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                return [];
            }
            // Normalize to a profile object that preserves property + error + notification if present
            // sendAction('GetProfile') may already return the inner 'profile' JSON or wrap it in 'response'
            $profile = [];
            // Case A: wrapper contains 'response'
            if (isset($data['response']) && is_array($data['response'])) {
                $profile = $data['response'];
            } elseif (isset($data['property']) || isset($data['error']) || isset($data['notification'])) {
                // Case B: data looks like a full profile object with keys like 'property', 'error', 'notification'
                $profile = $data;
            } elseif (isset($data['profile']) && is_array($data['profile'])) {
                // Case C: wrapper contains 'profile'
                $profile = $data['profile'];
            } else {
                // Case D: legacy: treat entire payload as property content
                $profile = ['property' => $data];
            }
            // Ensure structure types are arrays
            if (!isset($profile['property']) || !is_array($profile['property'])) {
                // some devices might return 'property' wrapped in index 0
                if (isset($profile[0]) && is_array($profile[0])) {
                    $profile['property'] = $profile[0];
                }
            }
            return $profile;
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

    public function readStoredProfile(): array
    {
        $raw     = (string)$this->ctx->attributeString('LastProfile');
        $profile = json_decode($raw, true);
        return is_array($profile) ? $profile : [];
    }

    /**
     * Check if status contains properties that are not in the cached profile.
     *
     * @param array<string, mixed> $status
     * @param array<string, mixed> $profile
     */
    public function statusHasNewProperties(array $status, array $profile): bool
    {
        // Flatten both to compare full paths
        $statusFlat  = ($this->flattenCallback)($status);
        $profileFlat = ($this->flattenCallback)($profile['property'] ?? $profile);

        foreach ($statusFlat as $statusKey => $statusValue) {
            if ($statusValue === null) {
                continue;
            }
            $found = false;
            foreach ($profileFlat as $profileKey => $_) {
                if (
                    strpos($profileKey, $statusKey) !== false ||
                    strpos($statusKey, str_replace('property.', '', explode('.type', $profileKey)[0])) !== false
                ) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->ctx->debug('statusHasNewProperties', sprintf('New property found in status: %s', $statusKey));
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch fresh profile from API.
     *
     * @return array<string, mixed>
     */
    public function fetchProfileFromAPI(): array
    {
        try {
            $deviceId = trim((string)$this->ctx->propertyString('DeviceID'));
            if ($deviceId === '') {
                return [];
            }
            $response = ($this->sendActionCallback)('GetProfile', ['DeviceID' => $deviceId]);
            $data     = json_decode($response, true);
            if (isset($data['profile']) && is_array($data['profile'])) {
                $this->ctx->debug('fetchProfileFromAPI', 'Profile successfully fetched from API (profile)');
                return $data['profile'];
            }
            if (isset($data['property']) && is_array($data['property'])) {
                $this->ctx->debug('fetchProfileFromAPI', 'Profile successfully fetched from API (property)');
                return $data['property'];
            }
            if (is_array($data)) {
                $this->ctx->debug('fetchProfileFromAPI', 'Profile fetched from API (raw array)');
                return $data;
            }
            return [];
        } catch (\Throwable $e) {
            $this->ctx->debug('fetchProfileFromAPI', 'Failed: ' . $e->getMessage());
            return [];
        }
    }

    public function readLastStatus(): array
    {
        $raw    = (string)$this->ctx->attributeString('LastStatus');
        $status = json_decode($raw, true);
        return is_array($status) ? $status : [];
    }
}
