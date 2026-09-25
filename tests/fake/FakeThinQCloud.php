<?php

declare(strict_types=1);

/*
 * The LG ThinQ Connect cloud for the test bench: REST API and the MQTT messages it would
 * publish. Three kinds of behaviour, marked in the code:
 *   [Spez]     from the current LG OpenAPI (tests/fixtures/lg_examples.json, lg_spec_holen.php)
 *   [gemessen] from the live Bridge in the Docker test system (log files 20.–25.09.2026)
 *   [Annahme]  not documented and not measured; errors of this kind carry code 9999 and
 *              "(Prüfstand)" in the message, so nobody mistakes them for real LG codes.
 */
final class FakeThinQCloud
{
    public static ?self $current = null;

    public string $pat = 'thinqpat_pruefstand_0123456789abcdef0123456789abcdef01';
    /** Expected x-api-key header ('' = not checked) */
    public string $apiKey = '';
    /** Regional API server of the account: requests to another region fail [Annahme] */
    public string $region = 'EIC';
    /** deviceId => [info, profile, state, energy, commands] */
    public array $devices = [];
    /** deviceId => [expiresAt, client] — DEVICE_STATUS only while not expired [Spez] */
    public array $eventSubs = [];
    /** deviceId => [client] — DEVICE_PUSH messages [Spez] */
    public array $pushSubs = [];
    /** clientId => true — registered for DEVICE_REGISTERED/UNREGISTERED/ALIAS_CHANGED [Spez] */
    public array $pushClients = [];
    /** clientId => true (POST client) */
    public array $clients = [];
    /** [method, host, path, query, headers, body, status] */
    public array $requests = [];
    /** Queued MQTT messages [topic, message] */
    public array $mqttOut = [];
    public bool $down = false;
    /** [pattern, status, body, times] — pattern like "GET devices/{id}/profile" or "GET devices/* /state" */
    public array $faults = [];
    public bool $strictControl = true;
    /** Last validation error of a rejected control command */
    public string $lastRejection = '';
    public string $caPem = '';
    private mixed $caKey = null;
    private int $seq = 0;
    private static ?array $examples = null;

    public function __construct()
    {
        self::$current = $this;
        $this->caKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => 'Pruefstand LG ThinQ CA'], $this->caKey, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $this->caKey, 3650, ['digest_alg' => 'sha256'], 1);
        openssl_x509_export($cert, $this->caPem);
    }

    public static function examples(): array
    {
        return self::$examples ??= json_decode((string)file_get_contents(__DIR__ . '/../fixtures/lg_examples.json'), true);
    }

    // ------------------------------------------------------------------ devices

    /** Adds one of LG's example devices ("washer", "refrigerator", ...) and returns its deviceId. */
    public function addExampleDevice(string $type, ?string $alias = null): string
    {
        $ex = self::examples()['devices'][$type] ?? null;
        if ($ex === null) {
            throw new InvalidArgumentException('Kein LG-Beispiel für ' . $type);
        }
        $n = count($this->devices) + 1;
        $id = hash('sha256', 'pruefstand-' . $type . '-' . $n);
        $this->devices[$id] = [
            'info' => ['deviceType' => $ex['deviceType'], 'modelName' => strtoupper($type) . '_PRUEFSTAND',
                'alias' => $alias ?? (string)$ex['title'], 'reportable' => true],
            'profile' => $ex['profile'], 'state' => $ex['status'], 'energy' => null, 'commands' => $ex['commands'],
        ];
        return $id;
    }

    /** The real air conditioner of the Docker test system (tests/fixtures/live_ac.json). */
    public function addLiveAc(): string
    {
        $f = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/live_ac.json'), true);
        $id = (string)$f['device']['deviceId'];
        $this->devices[$id] = ['info' => $f['device']['deviceInfo'], 'profile' => $f['profile'], 'state' => $f['status'],
            'energy' => $f['energyProfile']['result']['property'], 'commands' => []];
        return $id;
    }

    public function withEnergy(string $deviceId, array $properties = ['energyUsage']): void
    {
        $this->devices[$deviceId]['energy'] = $properties;
    }

    /** Fault injection: $times = -1 keeps the fault until cleared. */
    public function fail(string $pattern, int $status, string $body = '', int $times = 1): void
    {
        $this->faults[] = ['pattern' => $pattern, 'status' => $status, 'body' => $body, 'times' => $times];
    }

    /** Requests matching "METHOD path" with * wildcards (path without host and query). */
    public function calls(string $pattern): array
    {
        return array_values(array_filter($this->requests, fn(array $r): bool => $this->matches($pattern, $r['method'], $r['path'])));
    }

    private function matches(string $pattern, string $method, string $path): bool
    {
        [$pm, $pp] = explode(' ', $pattern, 2) + [1 => ''];
        $re = '#^' . str_replace(['\*', '\{id\}'], ['[^/]*', '[^/]+'], preg_quote($pp, '#')) . '$#';
        return strcasecmp($pm, $method) === 0 && preg_match($re, $path) === 1;
    }

    // ------------------------------------------------------------------ transport entry (ThinQHttpTransport replacement)

    /** @return array{status: int, statusLine: string, body: string|false, error: string} */
    public function handle(string $method, string $url, array $headers, ?string $body): array
    {
        $parts = parse_url($url);
        $host = (string)($parts['host'] ?? '');
        $path = ltrim((string)($parts['path'] ?? ''), '/');
        parse_str((string)($parts['query'] ?? ''), $query);
        $hdr = [];
        foreach ($headers as $line) {
            [$k, $v] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $hdr[strtolower($k)] = $v;
        }
        $decoded = ($body !== null && $body !== '') ? json_decode($body, true) : null;
        $entry = ['method' => $method, 'host' => $host, 'path' => $path, 'query' => $query, 'headers' => $hdr, 'body' => $decoded, 'status' => 0];

        if ($this->down) {
            $this->requests[] = $entry;
            return ['status' => 0, 'statusLine' => '', 'body' => false,
                'error' => 'file_get_contents(): php_network_getaddresses: getaddrinfo for ' . $host . ' failed: nodename nor servname provided, or not known'];
        }
        foreach ($this->faults as $i => $f) {
            if ($f['times'] !== 0 && $this->matches($f['pattern'], $method, $path)) {
                if ($f['times'] > 0) {
                    $this->faults[$i]['times']--;
                }
                $entry['status'] = $f['status'];
                $this->requests[] = $entry;
                return $this->raw($f['status'], $f['body']);
            }
        }
        [$status, $payload] = $this->route($method, $host, $path, $query, $hdr, $decoded);
        $entry['status'] = $status;
        $this->requests[] = $entry;
        $envelope = ['messageId' => (string)($hdr['x-message-id'] ?? ''), 'timestamp' => gmdate('Y-m-d\TH:i:s', ThinQClock::now()) . '.000000'];
        $envelope += $status >= 400 ? ['error' => $payload] : ['response' => $payload];
        return $this->raw($status, (string)json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Public text downloads (the Amazon root CA of the MQTT broker). */
    public function download(string $url): string
    {
        return str_contains($url, 'amazontrust.com') ? $this->caPem : '';
    }

    private function raw(int $status, string $body): array
    {
        $reason = [200 => 'OK', 204 => 'No Content', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found',
            500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable'][$status] ?? 'Status';
        return ['status' => $status, 'statusLine' => 'HTTP/1.1 ' . $status . ' ' . $reason, 'body' => $body, 'error' => ''];
    }

    private static function err(int $status, string $code, string $message): array
    {
        return [$status, ['message' => $message, 'code' => $code]];
    }

    private function route(string $method, string $host, string $path, array $query, array $hdr, mixed $body): array
    {
        if (!preg_match('/^api-(eic|aic|kic)\.lgthinq\.com$/', $host, $m)) {
            return self::err(404, '9999', '(Prüfstand) unbekannter Host ' . $host);
        }
        if (strtoupper($m[1]) !== $this->region) {
            return self::err(400, '9999', '(Prüfstand) Konto liegt in Region ' . $this->region . ', Anfrage ging an ' . strtoupper($m[1]));
        }
        if ($this->apiKey !== '' && ($hdr['x-api-key'] ?? '') !== $this->apiKey) {
            return self::err(401, '9999', '(Prüfstand) x-api-key fehlt oder falsch');
        }
        if ($path !== 'route' && ($hdr['authorization'] ?? '') !== 'Bearer ' . $this->pat) {
            return self::err(401, '9999', '(Prüfstand) ungültiger Token');
        }
        $client = (string)($hdr['x-client-id'] ?? '');
        $seg = explode('/', $path);
        $dev = fn(string $id): ?array => $this->devices[$id] ?? null;

        switch (true) {
            case $method === 'GET' && $path === 'route':
                return [200, ['apiServer' => 'https://' . $host, 'mqttServer' => 'mqtts://localhost:8883', 'webSocketServer' => 'wss://localhost:443/mqtt']];
            case $method === 'GET' && $path === 'devices':
                return [200, array_map(static fn(string $id, array $d): array => ['deviceId' => $id, 'deviceInfo' => $d['info']], array_keys($this->devices), $this->devices)];
            case $method === 'GET' && count($seg) === 3 && $seg[0] === 'devices' && $seg[2] === 'profile':
                return $dev($seg[1]) === null ? self::err(404, '9999', '(Prüfstand) Gerät unbekannt') : [200, $this->devices[$seg[1]]['profile']];
            case $method === 'GET' && count($seg) === 3 && $seg[0] === 'devices' && $seg[2] === 'state':
                return $dev($seg[1]) === null ? self::err(404, '9999', '(Prüfstand) Gerät unbekannt') : [200, $this->devices[$seg[1]]['state']];
            case $method === 'POST' && count($seg) === 3 && $seg[0] === 'devices' && $seg[2] === 'control':
                return $this->control($seg[1], is_array($body) ? $body : []);
            case $method === 'POST' && count($seg) === 3 && $seg[0] === 'event' && $seg[2] === 'subscribe':
                if ($dev($seg[1]) === null) {
                    return self::err(404, '9999', '(Prüfstand) Gerät unbekannt');
                }
                $hours = (int)($body['expire']['timer'] ?? 1); // [Spez] default 1, 1..24, renewal re-arms
                if ($hours < 1 || $hours > 24) {
                    return self::err(400, '9999', '(Prüfstand) expire.timer außerhalb 1..24');
                }
                $this->eventSubs[$seg[1]] = ['expiresAt' => ThinQClock::now() + $hours * 3600, 'client' => $client];
                return [200, []];
            case $method === 'DELETE' && count($seg) === 3 && $seg[0] === 'event' && $seg[2] === 'unsubscribe':
                unset($this->eventSubs[$seg[1]]);
                return [200, []];
            case $method === 'GET' && $path === 'event':
                return [200, array_map(static fn(string $id): array => ['deviceId' => $id], array_keys($this->activeEventSubs()))];
            case $method === 'POST' && count($seg) === 3 && $seg[0] === 'push' && $seg[2] === 'subscribe':
                if (isset($this->pushSubs[$seg[1]])) {
                    return self::err(404, '1207', 'Already subscribed push'); // [gemessen]
                }
                $this->pushSubs[$seg[1]] = ['client' => $client];
                return [200, []];
            case $method === 'DELETE' && count($seg) === 3 && $seg[0] === 'push' && $seg[2] === 'unsubscribe':
                unset($this->pushSubs[$seg[1]]);
                return [200, []];
            case $method === 'GET' && $path === 'push':
                return [200, array_map(static fn(string $id): array => ['deviceId' => $id], array_keys($this->pushSubs))];
            case $method === 'POST' && $path === 'push/devices':
                if (isset($this->pushClients[$client])) {
                    return self::err(400, '4001', 'Already Subscirbed Home Push'); // [gemessen], LG's spelling
                }
                $this->pushClients[$client] = true;
                return [200, []];
            case $method === 'DELETE' && $path === 'push/devices':
                unset($this->pushClients[$client]);
                return [200, []];
            case $method === 'POST' && $path === 'client':
                $this->clients[$client] = true; // [Annahme] idempotent
                return [200, []];
            case $method === 'POST' && $path === 'client/certificate':
                return $this->certificate($client, is_array($body) ? $body : []);
            case $method === 'GET' && count($seg) === 4 && $seg[0] === 'devices' && $seg[1] === 'energy' && $seg[3] === 'profile':
                $energy = $dev($seg[2])['energy'] ?? null;
                return $energy === null ? self::err(400, '1221', 'Not supported product') // pythinqconnect issue #57
                    : [200, ['resultCode' => '0000', 'result' => ['property' => $energy]]]; // [gemessen]
            case $method === 'GET' && count($seg) === 4 && $seg[0] === 'devices' && $seg[1] === 'energy' && $seg[3] === 'usage':
                return $this->usage($seg[2], $query);
        }
        return self::err(404, '9999', '(Prüfstand) unbekannter Endpunkt ' . $method . ' ' . $path);
    }

    public function activeEventSubs(): array
    {
        return array_filter($this->eventSubs, static fn(array $s): bool => $s['expiresAt'] > ThinQClock::now());
    }

    private function certificate(string $client, array $body): array
    {
        $csr = (string)($body['body']['csr'] ?? '');
        if ($csr === '' || ($body['body']['service-code'] ?? '') !== 'SVC202') {
            return self::err(400, '9999', '(Prüfstand) body.csr oder body.service-code fehlt');
        }
        $cert = @openssl_csr_sign($csr, $this->caPem, $this->caKey, 365, ['digest_alg' => 'sha256'], ++$this->seq);
        if ($cert === false) {
            return self::err(400, '9999', '(Prüfstand) CSR nicht lesbar');
        }
        openssl_x509_export($cert, $pem);
        return [200, ['resultCode' => '0000', 'result' => ['certificatePem' => $pem, 'subscriptions' => ['app/clients/' . $client . '/push']]]];
    }

    /** [Spez] result.dataList[{usedDate, useAmount}]; period DAILY|MONTHLY, dates YYYYMMDD / YYYYMM. */
    private function usage(string $deviceId, array $q): array
    {
        $energy = $this->devices[$deviceId]['energy'] ?? null;
        if ($energy === null) {
            return self::err(400, '1221', 'Not supported product');
        }
        $period = (string)($q['period'] ?? '');
        $format = ['DAILY' => '/^\d{8}$/', 'MONTHLY' => '/^\d{6}$/'][$period] ?? null;
        if ($format === null || !in_array($q['property'] ?? '', $energy, true)
            || !preg_match($format, (string)($q['startDate'] ?? '')) || !preg_match($format, (string)($q['endDate'] ?? ''))) {
            return self::err(400, '9999', '(Prüfstand) ungültige Abfrage: ' . http_build_query($q));
        }
        $amount = static fn(string $date): int => 500 + ((int)substr($date, -2)) * 10;
        return [200, ['resultCode' => '0000', 'result' => ['property' => [$q['property']],
            'dataList' => [['usedDate' => (string)$q['startDate'], 'useAmount' => $amount((string)$q['startDate'])]]]]];
    }

    // ------------------------------------------------------------------ control: validate against the profile, apply, report

    private function control(string $deviceId, array $cmd): array
    {
        $device = $this->devices[$deviceId] ?? null;
        if ($device === null) {
            return self::err(404, '9999', '(Prüfstand) Gerät unbekannt');
        }
        $error = ThinQCommandCheck::validate($device['profile'], $cmd);
        if ($error !== null && $this->strictControl) {
            $this->lastRejection = $error;
            return self::err(400, '9999', '(Prüfstand) Befehl abgelehnt: ' . $error);
        }
        $readable = ThinQCommandCheck::readablePart($device['profile'], $cmd);
        if ($readable !== []) {
            $this->devices[$deviceId]['state'] = ThinQCommandCheck::merge($device['state'], $readable);
            $this->emitStatus($deviceId, $readable);
        }
        return [200, []];
    }

    // ------------------------------------------------------------------ device side: changes, pushes, registration

    /** The appliance changed by itself (door opened, temperature moved ...): state merge plus DEVICE_STATUS. */
    public function deviceReports(string $deviceId, array $report): void
    {
        $this->devices[$deviceId]['state'] = ThinQCommandCheck::merge($this->devices[$deviceId]['state'], $report);
        $this->emitStatus($deviceId, $report);
    }

    public function emitStatus(string $deviceId, array $report): void
    {
        $sub = $this->activeEventSubs()[$deviceId] ?? null;
        if ($sub === null) {
            return; // [Spez] expired or missing event subscription: no DEVICE_STATUS
        }
        $this->publish($sub['client'], ['event' => ['pushType' => 'DEVICE_STATUS', 'serviceId' => 'pruefstand-service',
            'deviceId' => $deviceId, 'deviceType' => $this->devices[$deviceId]['info']['deviceType'], 'report' => $report,
            'userList' => ['PRUEFSTAND0001']]]);
    }

    public function devicePush(string $deviceId, string $pushCode): void
    {
        $sub = $this->pushSubs[$deviceId] ?? null;
        if ($sub === null) {
            return;
        }
        $this->publish($sub['client'], ['push' => ['pushType' => 'DEVICE_PUSH', 'serviceId' => 'pruefstand-service',
            'deviceId' => $deviceId, 'pushCode' => $pushCode, 'deviceType' => $this->devices[$deviceId]['info']['deviceType'],
            'userList' => ['PRUEFSTAND0001']]]);
    }

    /** DEVICE_REGISTERED / DEVICE_UNREGISTERED / DEVICE_ALIAS_CHANGED to every client registered via POST push/devices. */
    public function accountEvent(string $type, string $deviceId): void
    {
        $info = $this->devices[$deviceId]['info'] ?? ['deviceType' => '', 'modelName' => '', 'alias' => ''];
        $push = ['pushType' => $type, 'serviceId' => 'pruefstand-service', 'deviceId' => $deviceId, 'userNumber' => 'PRUEFSTAND0001',
            'deviceType' => $info['deviceType'], 'alias' => $info['alias']];
        if ($type === 'DEVICE_REGISTERED') {
            $push['modelName'] = $info['modelName'];
        }
        foreach (array_keys($this->pushClients) as $client) {
            $this->publish((string)$client, ['push' => $push]);
        }
    }

    private function publish(string $client, array $message): void
    {
        $message = ['messageId' => 'pruefstand-' . (++$this->seq), 'timestamp' => gmdate('Y-m-d\TH:i:s', ThinQClock::now()) . '.000000'] + $message;
        $this->mqttOut[] = ['topic' => 'app/clients/' . $client . '/push', 'message' => $message];
    }

    public function drainMqtt(): array
    {
        $out = $this->mqttOut;
        $this->mqttOut = [];
        return $out;
    }
}
