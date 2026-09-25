<?php

declare(strict_types=1);

/**
 * The LG ThinQ Connect endpoints the library uses, on top of ThinQHttpClient. Request bodies
 * follow LG's OpenAPI description; errors arrive as ThinQApiException from the client.
 */
final class ThinQApi
{
    public function __construct(private ThinQHttpClient $http)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function devices(): array
    {
        $data = $this->http->request('GET', 'devices');
        if (isset($data['devices']) && is_array($data['devices'])) {
            return $data['devices'];
        }
        if ($data === []) {
            return [];
        }
        return isset($data[0]) ? $data : [$data];
    }

    /** @return array<mixed> */
    public function deviceStatus(string $deviceId): array
    {
        return $this->http->request('GET', 'devices/' . rawurlencode($deviceId) . '/state');
    }

    /** @return array<string, mixed> */
    public function deviceProfile(string $deviceId): array
    {
        return $this->http->request('GET', 'devices/' . rawurlencode($deviceId) . '/profile');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed>
     */
    public function control(string $deviceId, array $payload): array
    {
        return $this->http->request('POST', 'devices/' . rawurlencode($deviceId) . '/control', $payload, ['x-conditional-control: false']);
    }

    /** @return array<string, mixed> */
    public function energyProfile(string $deviceId): array
    {
        return $this->http->request('GET', 'devices/energy/' . rawurlencode($deviceId) . '/profile');
    }

    /** @return array<string, mixed> */
    public function energyUsage(string $deviceId, string $property, string $period, string $startDate, string $endDate): array
    {
        $query = http_build_query(['property' => $property, 'period' => $period, 'startDate' => $startDate, 'endDate' => $endDate]);
        return $this->http->request('GET', 'devices/energy/' . rawurlencode($deviceId) . '/usage?' . $query);
    }

    public function subscribeEvents(string $deviceId, int $hours): void
    {
        $this->http->request('POST', 'event/' . rawurlencode($deviceId) . '/subscribe', ['expire' => ['unit' => 'HOUR', 'timer' => $hours]]);
    }

    public function unsubscribeEvents(string $deviceId): void
    {
        $this->http->request('DELETE', 'event/' . rawurlencode($deviceId) . '/unsubscribe');
    }

    /** Registers this client as push recipient (LG: 4001 when already registered). */
    public function registerPushClient(): void
    {
        $this->http->request('POST', 'push/devices');
    }

    public function unregisterPushClient(): void
    {
        $this->http->request('DELETE', 'push/devices');
    }

    /** LG: 1207 when already subscribed. */
    public function subscribePush(string $deviceId): void
    {
        $this->http->request('POST', 'push/' . rawurlencode($deviceId) . '/subscribe');
    }

    public function unsubscribePush(string $deviceId): void
    {
        $this->http->request('DELETE', 'push/' . rawurlencode($deviceId) . '/unsubscribe');
    }

    /** @return array<string, mixed> apiServer, mqttServer, webSocketServer */
    public function route(): array
    {
        return $this->http->request('GET', 'route');
    }

    /** Registers the client ID for MQTT (idempotent on LG's side). */
    public function registerClient(): void
    {
        $this->http->request('POST', 'client', ['body' => ['type' => 'MQTT', 'service-code' => 'SVC202', 'device-type' => '607']]);
    }

    /** @return array<string, mixed> result node with certificatePem and subscriptions */
    public function requestCertificate(string $csrPem): array
    {
        $resp = $this->http->request('POST', 'client/certificate', ['body' => ['service-code' => 'SVC202', 'csr' => $csrPem]]);
        return isset($resp['result']) && is_array($resp['result']) ? $resp['result'] : $resp;
    }
}
