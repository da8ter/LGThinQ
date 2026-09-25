<?php

declare(strict_types=1);

/**
 * Event and push subscriptions of the Bridge's devices. Event subscriptions expire after the TTL
 * and are renewed before that (tick() runs every CHECK_PERIOD seconds); push subscriptions do not
 * expire and are re-asserted once a day. Both follow the Device instances of the Bridge.
 */
final class ThinQSubscriptionService
{
    /** Seconds between two renewal checks of the EventRenewTimer. */
    public const CHECK_PERIOD = 600;
    private const DEVICE_MODULE_GUID = '{B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}';

    /** deviceId => {expiresAt, clientId} or, after a failure, {failedSince, retryAt} */
    private ThinQJsonAttribute $events;
    /** deviceId => {at, clientId} of the last successful push subscribe */
    private ThinQJsonAttribute $pushes;

    public function __construct(private ThinQModuleContext $ctx, private ThinQBridgeConfig $config, private ThinQApi $api)
    {
        $this->events = new ThinQJsonAttribute($ctx, 'EventSubscriptions');
        $this->pushes = new ThinQJsonAttribute($ctx, 'PushDeviceSubs');
    }

    /** @return array{ok: bool, errors: array<int, string>} */
    public function subscribe(string $deviceId, bool $push = true, bool $event = true, bool $force = false): array
    {
        $errors = [];
        if ($event) {
            $success = $this->subscribeEvent($deviceId, $force);
            $this->ctx->debug('Event Subscribe', ($success ? 'OK' : 'FAILED') . ' for ' . $deviceId);
            if (!$success) {
                $errors[] = 'Event subscription failed';
            }
        }
        if ($push) {
            $this->registerPushClient($force);
            try {
                $this->subscribePush($deviceId, $force);
            } catch (Throwable $e) {
                $this->ctx->debug('Push Subscribe', $e->getMessage());
                $errors[] = 'Push subscribe failed: ' . $e->getMessage();
            }
        }
        return ['ok' => $errors === [], 'errors' => $errors];
    }

    public function unsubscribe(string $deviceId, bool $push = true, bool $event = true): bool
    {
        $ok = true;
        if ($event) {
            try {
                $this->api->unsubscribeEvents($deviceId);
            } catch (Throwable $e) {
                $this->ctx->debug('Event', 'Unsubscribe error: ' . $e->getMessage());
                $ok = false;
            }
            $this->events->remove($deviceId);
        }
        if ($push) {
            $this->pushes->remove($deviceId);
            try {
                $this->api->unsubscribePush($deviceId);
            } catch (Throwable $e) {
                $this->ctx->debug('Push Unsubscribe', $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * @param array<int, string> $deviceIds
     * @return array{ok: int, total: int}
     */
    public function subscribeAll(array $deviceIds): array
    {
        $ok = 0;
        foreach ($deviceIds as $deviceId) {
            $ok += $this->subscribe($deviceId, true, true, true)['ok'] ? 1 : 0;
        }
        return ['ok' => $ok, 'total' => count($deviceIds)];
    }

    /**
     * Unsubscribes the given devices plus every device with a stored subscription, then the client.
     * @param array<int, string> $deviceIds
     * @return array{ok: int, total: int}
     */
    public function unsubscribeAll(array $deviceIds): array
    {
        $ids = array_values(array_unique(array_merge($deviceIds, $this->knownDeviceIds())));
        $ok = 0;
        foreach ($ids as $deviceId) {
            $ok += $this->unsubscribe($deviceId) ? 1 : 0;
        }
        try {
            $this->api->unregisterPushClient();
        } catch (Throwable $e) {
            $this->ctx->debug('UnsubscribeAll Push', $e->getMessage());
        }
        $this->events->replace([]);
        $this->pushes->replace([]);
        $this->ctx->writeAttributeInteger('PushRegisteredAt', 0);
        return ['ok' => $ok, 'total' => count($ids)];
    }

    /** @return array{ok: int, total: int} all devices of the Bridge (and with a stored subscription), subscribed anew */
    public function subscribeDevicesOfBridge(): array
    {
        return $this->subscribeAll(array_values(array_unique(array_merge($this->childDeviceIds(), $this->knownDeviceIds()))));
    }

    /** @return array{ok: int, total: int} event subscriptions renewed now */
    public function renewAll(): array
    {
        $ids = $this->knownDeviceIds();
        $ok = 0;
        foreach ($ids as $deviceId) {
            $ok += $this->subscribeEvent($deviceId, true) ? 1 : 0;
        }
        return ['ok' => $ok, 'total' => count($ids)];
    }

    public function renewEvent(string $deviceId): bool
    {
        return $this->subscribeEvent($deviceId, true);
    }

    /** The timer: renew what is due, subscribe devices without subscription, let the others expire. */
    public function tick(): void
    {
        $deviceIds = $this->childDeviceIds();
        foreach ($this->events->all() as $deviceId => $entry) {
            $deviceId = (string)$deviceId;
            $entry = is_array($entry) ? $entry : [];
            if (!in_array($deviceId, $deviceIds, true)) {
                if ((int)($entry['expiresAt'] ?? 0) <= ThinQClock::now()) {
                    $this->events->remove($deviceId);
                }
                continue;
            }
            if ($this->isDue($entry)) {
                $this->subscribeEvent($deviceId, true);
            }
        }
        // A device whose own subscription never succeeded gets one now.
        foreach (array_diff($deviceIds, $this->knownDeviceIds()) as $deviceId) {
            $this->subscribe($deviceId);
        }
        // Push subscriptions do not expire; re-assert them once a day as a safety net.
        if ($deviceIds !== [] && ThinQClock::now() - $this->ctx->attributeInteger('PushRegisteredAt') >= 86400) {
            $this->registerPushClient(true);
            foreach ($deviceIds as $deviceId) {
                try {
                    $this->subscribePush($deviceId, true);
                } catch (Throwable $e) {
                    $this->ctx->debug('RenewEvents', 'Push renew failed for ' . $deviceId . ': ' . $e->getMessage());
                }
            }
        }
    }

    /** @return array<int, string> devices with a stored event subscription (or a failed attempt) */
    public function knownDeviceIds(): array
    {
        return array_values(array_filter(array_map('strval', array_keys($this->events->all())), static fn(string $id): bool => $id !== ''));
    }

    private function subscribeEvent(string $deviceId, bool $force): bool
    {
        try {
            if (!$force && !$this->isDue($this->events->get($deviceId))) {
                return true;
            }
            $ttl = $this->config->normalizedEventTtlHours();
            $this->api->subscribeEvents($deviceId, $ttl);
            $this->events->put($deviceId, ['expiresAt' => ThinQClock::now() + ($ttl * 3600), 'clientId' => $this->config->clientId]);
            return true;
        } catch (Throwable $e) {
            // Retry after an hour; only the first failure of a series goes to the message log.
            $entry = $this->events->get($deviceId);
            $entry = is_array($entry) ? $entry : [];
            if (!isset($entry['failedSince'])) {
                $this->ctx->log('Subscribe error: ' . $e->getMessage(), KL_WARNING);
            }
            $this->events->merge($deviceId, ['failedSince' => (int)($entry['failedSince'] ?? ThinQClock::now()), 'retryAt' => ThinQClock::now() + 3600]);
            return false;
        }
    }

    /**
     * Due once the remaining time falls into the renewal window, which always reaches past the next
     * check, or when the subscription was made under another client ID (LG publishes the events to
     * that client's topic). Depends only on absolute times, so re-arming the timer cannot cause a gap.
     *
     * @param array<string, mixed>|null $entry
     */
    private function isDue(mixed $entry): bool
    {
        if (!is_array($entry)) {
            return true;
        }
        if ((int)($entry['retryAt'] ?? 0) > ThinQClock::now()) {
            return false;
        }
        $window = min($this->config->normalizedEventRenewLeadMinutes() * 60 + self::CHECK_PERIOD, intdiv($this->config->normalizedEventTtlHours() * 3600, 2));
        return (int)($entry['expiresAt'] ?? 0) - ThinQClock::now() <= $window || (string)($entry['clientId'] ?? '') !== $this->config->clientId;
    }

    /** POST push/devices registers this client as push recipient; repeated at most once per cooldown unless forced. */
    private function registerPushClient(bool $force): void
    {
        $at = $this->ctx->attributeInteger('PushRegisteredAt');
        if (!$force && $at > 0 && ThinQClock::now() - $at < $this->pushCooldownSeconds()) {
            return;
        }
        try {
            $this->api->registerPushClient();
            $this->ctx->debug('Push Subscribe', 'push/devices OK');
        } catch (Throwable $e) {
            if (!self::isAlreadySubscribed($e)) {
                $this->ctx->debug('Push Subscribe', 'push/devices error: ' . $e->getMessage());
                return;
            }
            $this->ctx->debug('Push Subscribe', 'push/devices already registered (OK)');
        }
        $this->ctx->writeAttributeInteger('PushRegisteredAt', ThinQClock::now());
    }

    /** POST push/{id}/subscribe, at most once per cooldown and client ID unless forced; throws on real errors. */
    private function subscribePush(string $deviceId, bool $force): void
    {
        $entry = $this->pushes->get($deviceId);
        if (!$force && is_array($entry) && (string)($entry['clientId'] ?? '') === $this->config->clientId
            && ThinQClock::now() - (int)($entry['at'] ?? 0) < $this->pushCooldownSeconds()) {
            return;
        }
        if (is_array($entry) && (string)($entry['clientId'] ?? '') !== $this->config->clientId) {
            // Made under another client ID; LG would keep delivering it there.
            try {
                $this->api->unsubscribePush($deviceId);
            } catch (Throwable $e) {
                $this->ctx->debug('Push Subscribe', 'Unsubscribe of the old client failed: ' . $e->getMessage());
            }
        }
        try {
            $this->api->subscribePush($deviceId);
            $this->ctx->debug('Push Subscribe', 'OK for ' . $deviceId);
        } catch (Throwable $e) {
            if (!self::isAlreadySubscribed($e)) {
                throw $e;
            }
            $this->ctx->debug('Push Subscribe', 'Already subscribed (OK) for ' . $deviceId);
        }
        $this->pushes->put($deviceId, ['at' => ThinQClock::now(), 'clientId' => $this->config->clientId]);
    }

    private function pushCooldownSeconds(): int
    {
        return max(1, $this->ctx->propertyInteger('PushCooldownMin')) * 60;
    }

    /** LG answers a repeated push registration with 4001 (push/devices, spelled "Subscirbed") or 1207 (push/{id}/subscribe). */
    private static function isAlreadySubscribed(Throwable $e): bool
    {
        return ($e instanceof ThinQApiException && in_array($e->apiCode, ['4001', '1207'], true))
            || stripos($e->getMessage(), 'already subscribed') !== false;
    }

    /** @return array<int, string> DeviceIDs of the LG ThinQ Device instances connected to this Bridge */
    private function childDeviceIds(): array
    {
        $ids = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_GUID) as $id) {
            $info = @IPS_GetInstance($id);
            if (is_array($info) && (int)($info['ConnectionID'] ?? 0) === $this->ctx->instanceId) {
                $deviceId = trim((string)@IPS_GetProperty($id, 'DeviceID'));
                if ($deviceId !== '') {
                    $ids[] = $deviceId;
                }
            }
        }
        return array_values(array_unique($ids));
    }
}
