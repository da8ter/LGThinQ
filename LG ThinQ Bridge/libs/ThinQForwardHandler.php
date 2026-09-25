<?php

declare(strict_types=1);

/** The actions a Device (or the Configurator) sends to the Bridge via ForwardData. */
final class ThinQForwardHandler
{
    public function __construct(private ThinQApi $api, private ThinQSubscriptionService $subscriptions)
    {
    }

    /**
     * @param array<string, mixed> $buffer {Action, DeviceID, …}
     * @return array<string, mixed> {success, …} — errors arrive as exceptions
     */
    public function handle(array $buffer): array
    {
        $deviceId = (string)($buffer['DeviceID'] ?? '');
        $id = static fn(): string => $deviceId !== '' ? $deviceId : throw new Exception('DeviceID missing');
        switch ((string)($buffer['Action'] ?? '')) {
            case 'GetDevices':
                return ['success' => true, 'devices' => $this->api->devices()];
            case 'GetStatus':
                return ['success' => true, 'status' => $this->api->deviceStatus($id())];
            case 'GetProfile':
                return ['success' => true, 'profile' => $this->api->deviceProfile($id())];
            case 'Control':
                if ($deviceId === '' || !is_array($buffer['Payload'] ?? null)) {
                    throw new Exception('Control payload invalid');
                }
                return ['success' => true, 'response' => $this->api->control($deviceId, $buffer['Payload'])];
            case 'SubscribeDevice':
                $result = $this->subscriptions->subscribe($id(), (bool)($buffer['Push'] ?? true), (bool)($buffer['Event'] ?? true));
                return ['success' => $result['ok']] + ($result['ok'] ? [] : ['error' => implode('; ', $result['errors'])]);
            case 'UnsubscribeDevice':
                return ['success' => $this->subscriptions->unsubscribe($id(), (bool)($buffer['Push'] ?? true), (bool)($buffer['Event'] ?? true))];
            case 'RenewEventForDevice':
                return ['success' => $this->subscriptions->renewEvent($id())];
            case 'GetEnergyProfile':
                return ['success' => true, 'energyProfile' => $this->api->energyProfile($id())];
            case 'GetEnergyUsage':
                $params = [];
                foreach (['Property', 'Period', 'StartDate', 'EndDate'] as $key) {
                    $params[] = (string)($buffer[$key] ?? '');
                }
                if ($deviceId === '' || in_array('', $params, true)) {
                    throw new Exception('GetEnergyUsage: missing parameters');
                }
                return ['success' => true, 'energyData' => $this->api->energyUsage($deviceId, ...$params)];
            default:
                return ['success' => false, 'error' => 'unknown action'];
        }
    }
}
