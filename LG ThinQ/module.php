<?php

declare(strict_types=1);
	class LGThinQ extends IPSModule
	{
		// API Key aus LG ThinQ Connect SDK (öffentlich dokumentiert)
		private const API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3';
		// Data-Flow Interface GUID (muss mit module.json->implemented übereinstimmen)
		private const DATA_FLOW_GUID = '{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}';
		// Child interface GUID (muss mit Device/module.json->implemented übereinstimmen)
		private const CHILD_INTERFACE_GUID = '{5E9D1B64-0F44-4F21-9D74-09C5BB90FB2F}';
		// MQTT Client Module GUID (Symcon MQTT Client)
		private const MQTT_MODULE_GUID = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';

		public function Create()
		{
			//Never delete this line!
			parent::Create();

			// Konfigurations-Properties
			$this->RegisterPropertyString('AccessToken', '');
			$this->RegisterPropertyString('CountryCode', 'DE');
			$this->RegisterPropertyString('ClientID', '');
			$this->RegisterPropertyBoolean('Debug', false);
			// MQTT Push
			$this->RegisterPropertyBoolean('UseMQTT', true);
			$this->RegisterPropertyInteger('MQTTClientID', 0);
			$this->RegisterPropertyString('MQTTTopicFilter', 'app/clients/*/push');
			$this->RegisterPropertyBoolean('IgnoreRetained', true);

			// Event API subscription config
			$this->RegisterPropertyInteger('EventTTLHrs', 24); // 1..24
			$this->RegisterPropertyInteger('EventRenewLeadMin', 5); // renew N minutes before expiry

			// Attribute zur Laufzeit
			$this->RegisterAttributeString('ClientID', '');
			$this->RegisterAttributeString('Devices', '[]');
			$this->RegisterAttributeString('EventSubscriptions', '{}'); // { deviceId: { "expiresAt": ts } }

			// (Polling entfernt)

			// Renew timer for Event API
			$this->RegisterTimer('EventRenewTimer', 0, 'LGTQ_RenewEvents($_IPS[\'TARGET\']);');
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
			//Never delete this line!
			parent::ApplyChanges();

			// ClientID bereitstellen (Property hat Vorrang, sonst Attribut generieren)
			$propClient = trim($this->ReadPropertyString('ClientID'));
			$attrClient = trim($this->ReadAttributeString('ClientID'));
			if ($propClient !== '') {
				$this->WriteAttributeString('ClientID', $propClient);
			} elseif ($attrClient === '') {
				$this->WriteAttributeString('ClientID', $this->generateUUIDv4());
			}

			// (Polling entfernt)

			// Status setzen
			$ok = $this->validateConfig();
			$this->SetStatus($ok ? 102 : 104);

			// Legacy: Sicherstellen, dass ein evtl. vorhandener UpdateTimer deaktiviert ist
			@ $this->SetTimerInterval('UpdateTimer', 0);

			// MQTT Parent verbinden (optional) – respektiere manuelle Parent-Wahl ("Schnittstelle ändern")
			if ((bool)$this->ReadPropertyBoolean('UseMQTT')) {
				$inst = @IPS_GetInstance($this->InstanceID);
				$parentID = is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
				if ($parentID <= 0) {
					// Nur wenn kein Parent gesetzt ist, an einen MQTT-Parent verbinden/erstellen
					if (method_exists($this, 'ConnectParent')) {
						$this->ConnectParent(self::MQTT_MODULE_GUID);
					}
				}
			}

			// Event Renew Timer konfigurieren
			$ttlH = max(1, min(24, (int)$this->ReadPropertyInteger('EventTTLHrs')));
			$leadM = max(1, min(59, (int)$this->ReadPropertyInteger('EventRenewLeadMin')));
			$nextSec = max(60, $ttlH * 3600 - $leadM * 60);
			$this->SetTimerInterval('EventRenewTimer', $ok ? ($nextSec * 1000) : 0);
		}

		public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
		{
			parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
		}

		// -------------------- Öffentliche Funktionen (Form-Buttons) --------------------

		// Exportiert als LGTQ_TestConnection($id)
		public function TestConnection(): void
		{
			try {
				$devices = $this->fetchDevices();
				$count = is_array($devices) ? count($devices) : 0;
				$this->SendDebug('TestConnection', 'Geräte gefunden: ' . $count, 0);
				$this->SetStatus(102);
				$message = 'Verbindung OK. Geräte: ' . $count;
				echo $message; // Zeigt ein Meldungsfenster in der Konsole
				$this->NotifyUser($message);
			} catch (Exception $e) {
				$this->SetStatus(104);
				$this->SendDebug('TestConnection Error', $e->getMessage(), 0);
				$message = 'Verbindung fehlgeschlagen: ' . $e->getMessage();
				echo $message; // Zeigt ein Meldungsfenster in der Konsole
				$this->NotifyUser($message);
			}
		}

		// Exportiert als LGTQ_SyncDevices($id)
		public function SyncDevices(): void
		{
			try {
				$devices = $this->fetchDevices();
				$this->WriteAttributeString('Devices', json_encode($devices));
				$this->NotifyUser('Geräteliste aktualisiert: ' . count($devices) . ' Geräte.');
			} catch (Exception $e) {
				$this->SendDebug('SyncDevices Error', $e->getMessage(), 0);
				$this->NotifyUser('Sync fehlgeschlagen: ' . $e->getMessage());
			}
		}

		// Exportiert als LGTQ_Update($id)
		public function Update(): void
		{
			try {
				// Ab Symcon-Architektur: Bridge aktualisiert nur interne Daten, keine Geräte unter der Bridge
				$devices = $this->fetchDevices();
				$this->WriteAttributeString('Devices', json_encode($devices));
				$this->SetStatus(102);
			} catch (Exception $e) {
				$this->SendDebug('Update Error', $e->getMessage(), 0);
			}
		}

		// Exportiert als LGTQ_SubscribeAll($id)
		public function SubscribeAll(): void
		{
			try {
				// Ensure client-level push subscription for meta events (add/delete/nickname)
				try { $this->request('POST', 'push/devices'); } catch (Exception $e) { $this->SendDebug('Push Clients Subscribe', $e->getMessage(), 0); }
				// Fetch devices and persist
				$devices = $this->fetchDevices();
				$this->WriteAttributeString('Devices', json_encode($devices));
				$okCnt = 0; $total = 0;
				foreach ($devices as $d) {
					$deviceId = (string)($d['deviceId'] ?? ($d['device_id'] ?? ''));
					if ($deviceId === '') continue;
					$total++;
					if ($this->SubscribeDevice($deviceId, true, true)) { $okCnt++; }
				}
				$this->NotifyUser(sprintf('SubscribeAll: %d/%d Geräte abonniert', $okCnt, $total));
			} catch (Exception $e) {
				$this->SendDebug('SubscribeAll Error', $e->getMessage(), 0);
				$this->NotifyUser('SubscribeAll fehlgeschlagen: ' . $e->getMessage());
			}
		}

		// Exportiert als LGTQ_UnsubscribeAll($id)
		public function UnsubscribeAll(): void
		{
			try {
				$ids = [];
				$subs = $this->readEventSubscriptions();
				foreach (array_keys($subs) as $id) { if ($id !== '') { $ids[$id] = true; } }
				$devs = json_decode((string)$this->ReadAttributeString('Devices'), true);
				if (is_array($devs)) {
					foreach ($devs as $d) { $id = (string)($d['deviceId'] ?? ($d['device_id'] ?? '')); if ($id !== '') { $ids[$id] = true; } }
				}
				$okCnt = 0; $total = count($ids);
				foreach (array_keys($ids) as $deviceId) { if ($this->UnsubscribeDevice($deviceId, true, true)) { $okCnt++; } }
				$this->writeEventSubscriptions([]);
				// Optionally unsubscribe client-level push
				try { $this->request('DELETE', 'push/devices'); } catch (Exception $e) { $this->SendDebug('Push Clients Unsubscribe', $e->getMessage(), 0); }
				$this->NotifyUser(sprintf('UnsubscribeAll: %d/%d Geräte abgemeldet', $okCnt, $total));
			} catch (Exception $e) {
				$this->SendDebug('UnsubscribeAll Error', $e->getMessage(), 0);
				$this->NotifyUser('UnsubscribeAll fehlgeschlagen: ' . $e->getMessage());
			}
		}

		// Exportiert als LGTQ_RenewAll($id)
		public function RenewAll(): void
		{
			try {
				$subs = $this->readEventSubscriptions();
				$okCnt = 0; $total = count($subs);
				foreach (array_keys($subs) as $deviceId) { if ($deviceId !== '' && $this->eventSubscribeDevice((string)$deviceId)) { $okCnt++; } }
				$this->NotifyUser(sprintf('RenewAll: %d/%d Event-Abos erneuert', $okCnt, $total));
			} catch (Exception $e) {
				$this->SendDebug('RenewAll Error', $e->getMessage(), 0);
				$this->NotifyUser('RenewAll fehlgeschlagen: ' . $e->getMessage());
			}
		}

		// -------------------- Interne Helfer --------------------

		private function validateConfig(): bool
		{
			$token = trim($this->ReadPropertyString('AccessToken'));
			$country = strtoupper(trim($this->ReadPropertyString('CountryCode')));
			return $token !== '' && $country !== '';
		}

		private function getBaseUrl(string $country): string
		{
			$region = $this->getRegionFromCountry($country);
			return 'https://api-' . strtolower($region) . '.lgthinq.com/';
		}

		private function getRegionFromCountry(string $country): string
		{
			$country = strtoupper($country);
			// Minimales Mapping entsprechend ThinQ SDK (EIC/AIC/KIC)
			$EIC = ['DE','AT','CH','FR','IT','ES','GB','IE','NL','BE','DK','SE','NO','FI','PL','PT','GR','CZ','HU','RO'];
			$AIC = ['US','CA','AR','BR','CL','CO','MX','PE','UY','VE','PR'];
			$KIC = ['JP','KR','AU','NZ','CN','HK','TW','SG','TH','VN','MY','ID','PH'];
			if (in_array($country, $EIC, true)) return 'EIC';
			if (in_array($country, $AIC, true)) return 'AIC';
			return 'KIC';
		}

		private function generateMessageId(): string
		{
			$bytes = random_bytes(16);
			$base = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
			return $base;
		}

		private function generateUUIDv4(): string
		{
			$data = random_bytes(16);
			$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
			$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
			return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		}

		private function request(string $method, string $endpoint, ?array $payload = null, array $extraHeaders = []): array
		{
			if (!$this->validateConfig()) {
				throw new Exception('AccessToken oder CountryCode nicht konfiguriert.');
			}
			$token = trim($this->ReadPropertyString('AccessToken'));
			$country = strtoupper(trim($this->ReadPropertyString('CountryCode')));
			$clientId = trim($this->ReadAttributeString('ClientID'));
			$baseUrl = $this->getBaseUrl($country);
			$url = $baseUrl . ltrim($endpoint, '/');

			$headers = [
				'Authorization: Bearer ' . $token,
				'x-country: ' . $country,
				'x-message-id: ' . $this->generateMessageId(),
				'x-client-id: ' . $clientId,
				'x-api-key: ' . self::API_KEY,
				'x-service-phase: OP',
				'Content-Type: application/json'
			];
			foreach ($extraHeaders as $h) {
				$headers[] = $h;
			}

			$method = strtoupper($method);
			$body = ($method !== 'GET' && $payload !== null) ? json_encode($payload) : '';

			$this->SendDebug('HTTP Request', $method . ' ' . $url, 0);
			if ($this->ReadPropertyBoolean('Debug')) {
				$this->SendDebug('HTTP Headers', json_encode($headers), 0);
				if ($method !== 'GET') $this->SendDebug('HTTP Body', $body, 0);
			}

			// Stream-Context für HTTP Request aufbauen
			$headersStr = implode("\r\n", $headers);
			$httpOptions = [
				'method' => $method,
				'header' => $headersStr,
				'ignore_errors' => true,
				'timeout' => 15,
				'protocol_version' => 1.1
			];
			if ($method !== 'GET') {
				$httpOptions['content'] = $body;
			}
			$context = stream_context_create(['http' => $httpOptions]);

			$result = @file_get_contents($url, false, $context);
			if ($result === false || $result === null) {
				$error = error_get_last();
				throw new Exception('HTTP Fehler beim Aufruf von ' . $url . ': ' . ($error['message'] ?? 'unbekannt'));
			}

			// HTTP Status prüfen
			$statusHeader = $http_response_header[0] ?? '';
			$statusCode = 0;
			if (preg_match('/HTTP\/[0-9.]+\s+(\d+)/', $statusHeader, $m)) {
				$statusCode = (int)$m[1];
			}

			// Einige Endpunkte (z.B. Event-Subscription/Renew) liefern ggf. keinen JSON-Body (204/leer/null)
			if ($statusCode === 204 || trim((string)$result) === '') {
				return [];
			}
			$decoded = json_decode($result, true);
			if ($statusCode >= 400) {
				if (is_array($decoded) && isset($decoded['error'])) {
					$code = $decoded['error']['code'] ?? 'unknown';
					$message = $decoded['error']['message'] ?? 'unknown';
					throw new Exception('HTTP ' . $statusCode . ' API Fehler ' . $code . ': ' . $message . ' (' . $url . ')');
				}
				// Nicht-JSON-Fehlerantwort: Rohtext anfügen (gekürzt)
				$snippet = substr(preg_replace('/\s+/', ' ', (string)$result), 0, 300);
				throw new Exception('HTTP ' . $statusCode . ' Fehler von ' . $url . ': ' . $snippet);
			}
			if (!is_array($decoded)) {
				// Erfolgreiche, aber nicht-JSON Antwort → als leer interpretieren
				return [];
			}
			// ThinQ Connect liefert typischerweise { response: ... }
			return $decoded['response'] ?? $decoded;
		}

		// High-Level API Wrapper
		public function GetDevices(): string
		{
			$devices = $this->fetchDevices();
			return json_encode($devices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		public function GetDeviceStatus(string $DeviceID): string
		{
			$status = $this->fetchDeviceStatus($DeviceID);
			return json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		public function GetDeviceProfile(string $DeviceID): string
		{
			$profile = $this->request('GET', 'devices/' . rawurlencode($DeviceID) . '/profile');
			return json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		public function ControlDevice(string $DeviceID, string $JSONPayload): bool
		{
			$payload = json_decode($JSONPayload, true);
			if (!is_array($payload)) {
				throw new Exception('ControlDevice: Ungültiges JSONPayload');
			}
			$this->request('POST', 'devices/' . rawurlencode($DeviceID) . '/control', $payload, ['x-conditional-control: false']);
			return true;
		}

		public function GetEnergyUsage(string $DeviceID, string $Property, string $Period, string $StartDate, string $EndDate): string
		{
			$endpoint = sprintf('devices/energy/%s/usage?property=%s&period=%s&startDate=%s&endDate=%s',
				rawurlencode($DeviceID), rawurlencode($Property), rawurlencode($Period), rawurlencode($StartDate), rawurlencode($EndDate)
			);
			$data = $this->request('GET', $endpoint);
			return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		private function fetchDevices(): array
		{
			$data = $this->request('GET', 'devices');
			// Normalisieren auf Liste
			if (isset($data['devices']) && is_array($data['devices'])) {
				return $data['devices'];
			}
			if (isset($data[0]) || empty($data)) {
				return $data; // bereits Liste oder leer
			}
			return [$data];
		}

		private function fetchDeviceStatus(string $deviceId): array
		{
			return $this->request('GET', 'devices/' . rawurlencode($deviceId) . '/state');
		}

		// Bridge erstellt KEINEN Gerätebaum mehr

		private function ensureCategory(int $parentId, string $ident, string $name): int
		{
			$existing = @IPS_GetObjectIDByIdent($ident, $parentId);
			if ($existing && IPS_ObjectExists($existing)) {
				if (IPS_GetName($existing) !== $name) {
					IPS_SetName($existing, $name);
				}
				return $existing;
			}
			$cid = IPS_CreateCategory();
			IPS_SetParent($cid, $parentId);
			IPS_SetIdent($cid, $ident);
			IPS_SetName($cid, $name);
			return $cid;
		}

		private function ensureVariable(int $parentId, string $ident, string $name, int $type, string $profile = ''): int
		{
			$vid = @IPS_GetObjectIDByIdent($ident, $parentId);
			if ($vid && IPS_ObjectExists($vid)) {
				if (IPS_GetName($vid) !== $name) IPS_SetName($vid, $name);
				if ($profile !== '' && IPS_GetVariable($vid)['VariableCustomProfile'] !== $profile) {
					IPS_SetVariableCustomProfile($vid, $profile);
				}
				return $vid;
			}
			$vid = IPS_CreateVariable($type);
			IPS_SetParent($vid, $parentId);
			IPS_SetIdent($vid, $ident);
			IPS_SetName($vid, $name);
			if ($profile !== '') IPS_SetVariableCustomProfile($vid, $profile);
			return $vid;
		}

		private function getObjectIdByIdent(int $parentId, string $ident): int
		{
			return @IPS_GetObjectIDByIdent($ident, $parentId);
		}

		private function NotifyUser(string $message): void
		{
			// Einfache Log-Ausgabe. Optional: Nachrichtensystem integrieren.
			$this->LogMessage($message, KL_MESSAGE);
		}

		// -------------------- Datenfluss (Splitter) --------------------
		public function ForwardData($JSONString)
		{
			$this->SendDebug('ForwardData', (string)$JSONString, 0);
			$data = json_decode((string)$JSONString, true);
			if (!is_array($data)) {
				return json_encode(['success' => false, 'error' => 'invalid json']);
			}
			if (!isset($data['DataID']) || strtoupper((string)$data['DataID']) !== strtoupper(self::DATA_FLOW_GUID)) {
				return json_encode(['success' => false, 'error' => 'unexpected data id']);
			}
			$buffer = $data['Buffer'] ?? [];
			if (is_string($buffer)) {
				$decoded = json_decode($buffer, true);
				if (is_array($decoded)) {
					$buffer = $decoded;
				} else {
					$buffer = [];
				}
			}
			$action = (string)($buffer['Action'] ?? '');
			try {
				switch ($action) {
					case 'GetDevices':
						$devices = $this->fetchDevices();
						return json_encode(['success' => true, 'devices' => $devices], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					case 'GetProfile':
						$deviceId = (string)($buffer['DeviceID'] ?? '');
						if ($deviceId === '') {
							return json_encode(['success' => false, 'error' => 'DeviceID missing']);
						}
						$profile = $this->request('GET', 'devices/' . rawurlencode($deviceId) . '/profile');
						return json_encode(['success' => true, 'profile' => $profile], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					case 'GetStatus':
						$deviceId = (string)($buffer['DeviceID'] ?? '');
						if ($deviceId === '') {
							return json_encode(['success' => false, 'error' => 'DeviceID missing']);
						}
						$status = $this->fetchDeviceStatus($deviceId);
						return json_encode(['success' => true, 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					case 'Control':
						$deviceId = (string)($buffer['DeviceID'] ?? '');
						$payload = $buffer['Payload'] ?? null;
						if ($deviceId === '' || !is_array($payload)) {
							return json_encode(['success' => false, 'error' => 'invalid control parameters']);
						}
						$res = $this->request('POST', 'devices/' . rawurlencode($deviceId) . '/control', $payload, ['x-conditional-control: false']);
						return json_encode(['success' => true, 'response' => $res], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					case 'SubscribeDevice':
						$deviceId = (string)($buffer['DeviceID'] ?? '');
						$withPush = (bool)($buffer['Push'] ?? true);
						$withEvent = (bool)($buffer['Event'] ?? true);
						if ($deviceId === '') return json_encode(['success' => false, 'error' => 'DeviceID missing']);
						$ok = $this->SubscribeDevice($deviceId, $withPush, $withEvent);
						return json_encode(['success' => $ok]);
					case 'UnsubscribeDevice':
						$deviceId = (string)($buffer['DeviceID'] ?? '');
						$fromPush = (bool)($buffer['Push'] ?? true);
						$fromEvent = (bool)($buffer['Event'] ?? true);
						if ($deviceId === '') return json_encode(['success' => false, 'error' => 'DeviceID missing']);
						$ok = $this->UnsubscribeDevice($deviceId, $fromPush, $fromEvent);
						return json_encode(['success' => $ok]);
					case 'RenewEventForDevice':
						$deviceId = (string)($buffer['DeviceID'] ?? '');
						if ($deviceId === '') return json_encode(['success' => false, 'error' => 'DeviceID missing']);
						$ok = $this->eventSubscribeDevice($deviceId);
						return json_encode(['success' => $ok]);
					default:
						return json_encode(['success' => false, 'error' => 'unknown action']);
				}
			} catch (\Exception $e) {
				$this->SendDebug('ForwardData Error', $e->getMessage(), 0);
				return json_encode(['success' => false, 'error' => $e->getMessage()]);
			}
		}

		public function ReceiveData($JSONString)
		{
			$this->SendDebug('ReceiveData', (string)$JSONString, 0);
			$raw = json_decode((string)$JSONString, true);
			if (!is_array($raw)) {
				return '';
			}
			// MQTT Envelope extrahieren
			$env = $raw;
			if (isset($raw['Buffer'])) {
				$buf = $raw['Buffer'];
				if (is_string($buf)) {
					$dec = json_decode($buf, true);
					if (is_array($dec)) { $env = array_merge($env, $dec); }
				} elseif (is_array($buf)) {
					$env = array_merge($env, $buf);
				}
			}
			$topic = (string)($env['Topic'] ?? ($env['topic'] ?? ''));
			$payloadRaw = $env['Payload'] ?? ($env['payload'] ?? null);
			$retain = (bool)($env['Retain'] ?? ($env['retain'] ?? false));

			if ((bool)$this->ReadPropertyBoolean('IgnoreRetained') && $retain) {
				$this->SendDebug('MQTT', 'Ignore retained: ' . $topic, 0);
				return '';
			}

			$filter = (string)$this->ReadPropertyString('MQTTTopicFilter');
			if ($filter !== '' && !$this->topicMatches($topic, $filter)) {
				return '';
			}

			$payload = [];
			if (is_string($payloadRaw)) {
				$payload = json_decode((string)$payloadRaw, true);
			} elseif (is_array($payloadRaw)) {
				$payload = $payloadRaw;
			}
			if (!is_array($payload)) {
				$this->SendDebug('MQTT', 'Invalid payload on topic ' . $topic, 0);
				return '';
			}

			// Normalize payload nodes
			$eventNode = isset($payload['event']) && is_array($payload['event']) ? $payload['event'] : null;
			$pushNode  = isset($payload['push'])  && is_array($payload['push'])  ? $payload['push']  : null;
			$topType   = strtoupper((string)($payload['pushType'] ?? ($payload['type'] ?? '')));

			// Handle Event message (DEVICE_STATUS)
			if ($eventNode || $topType === 'DEVICE_STATUS') {
				$node = $eventNode ?: $payload;
				$deviceId = (string)($node['deviceId'] ?? ($node['device_id'] ?? ''));
				$report = $node['report'] ?? null;
				if ($deviceId !== '' && is_array($report)) {
					$this->SendDataToChildren(json_encode([
						'DataID' => self::CHILD_INTERFACE_GUID,
						'Buffer' => json_encode([
							'Action' => 'Event',
							'DeviceID' => $deviceId,
							'Event' => $report
						], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
					]));
				}
				return '';
			}

			// Handle Push meta messages (DEVICE_REGISTERED/UNREGISTERED/...)
			if ($pushNode || in_array($topType, ['DEVICE_REGISTERED', 'DEVICE_UNREGISTERED', 'DEVICE_ALIAS_CHANGED', 'DEVICE_PUSH'], true)) {
				$node = $pushNode ?: $payload;
				$pType = strtoupper((string)($node['pushType'] ?? ''));
				$deviceId = (string)($node['deviceId'] ?? ($node['device_id'] ?? ''));
				if ($deviceId !== '') {
					if ($pType === 'DEVICE_REGISTERED') {
						try { $this->SubscribeDevice($deviceId, true, true); } catch (\Throwable $e) { $this->SendDebug('PushAutoSubscribe Error', $e->getMessage(), 0); }
					} elseif ($pType === 'DEVICE_UNREGISTERED') {
						try { $this->UnsubscribeDevice($deviceId, true, true); } catch (\Throwable $e) { $this->SendDebug('PushAutoUnsubscribe Error', $e->getMessage(), 0); }
					}
				}
				return '';
			}

			// Unknown payload type
			$this->SendDebug('MQTT', 'Unknown message type on topic ' . $topic, 0);
			return '';
		}

		private function topicMatches(string $topic, string $filter): bool
		{
			$filter = trim($filter);
			if ($filter === '') return true;
			$escaped = preg_quote($filter, '/');
			$pattern = '/^' . str_replace(['\\*', '\\#'], '.*', $escaped) . '$/i';
			return (bool)preg_match($pattern, $topic);
		}

		// -------------------- Event/Push Subscription Management --------------------

		public function SubscribeDevice(string $DeviceID, bool $Push = true, bool $Event = true): bool
		{
			$ok = true;
			if ($Event) {
				$ok = $this->eventSubscribeDevice($DeviceID) && $ok;
			}
			if ($Push) {
				try { $this->request('POST', 'push/' . rawurlencode($DeviceID) . '/subscribe'); } catch (\Exception $e) { $this->SendDebug('Push Subscribe Error', $e->getMessage(), 0); $ok = false; }
			}
			return $ok;
		}

		public function UnsubscribeDevice(string $DeviceID, bool $Push = true, bool $Event = true): bool
		{
			$ok = true;
			if ($Event) {
				try { $this->request('DELETE', 'event/' . rawurlencode($DeviceID) . '/unsubscribe'); } catch (\Exception $e) { $this->SendDebug('Event Unsubscribe Error', $e->getMessage(), 0); $ok = false; }
				$this->removeEventSubscription($DeviceID);
			}
			if ($Push) {
				try { $this->request('DELETE', 'push/' . rawurlencode($DeviceID) . '/unsubscribe'); } catch (\Exception $e) { $this->SendDebug('Push Unsubscribe Error', $e->getMessage(), 0); $ok = false; }
			}
			return $ok;
		}

		public function RenewEvents(): void
		{
			try {
				$now = time();
				$ttlH = max(1, min(24, (int)$this->ReadPropertyInteger('EventTTLHrs')));
				$leadM = max(1, min(59, (int)$this->ReadPropertyInteger('EventRenewLeadMin')));
				$leadSec = $leadM * 60;
				$expiresAtDefault = $now + ($ttlH * 3600);
				$subs = $this->readEventSubscriptions();
				foreach (array_keys($subs) as $deviceId) {
					$deviceId = (string)$deviceId;
					if ($deviceId === '') continue;
					$exp = (int)($subs[$deviceId]['expiresAt'] ?? 0);
					if ($exp === 0 || ($exp - $leadSec) <= $now) {
						if ($this->eventSubscribeDevice($deviceId)) {
							$subs[$deviceId]['expiresAt'] = $expiresAtDefault;
						}
					}
				}
				$this->writeEventSubscriptions($subs);
			} catch (\Throwable $e) {
				$this->SendDebug('RenewEvents Error', $e->getMessage(), 0);
			}
		}

		private function eventSubscribeDevice(string $deviceId): bool
		{
			$ttlH = max(1, min(24, (int)$this->ReadPropertyInteger('EventTTLHrs')));
			$body = ['expire' => ['unit' => 'HOUR', 'timer' => $ttlH]];
			try {
				$this->request('POST', 'event/' . rawurlencode($deviceId) . '/subscribe', $body);
				$subs = $this->readEventSubscriptions();
				$subs[$deviceId]['expiresAt'] = time() + ($ttlH * 3600);
				$this->writeEventSubscriptions($subs);
				return true;
			} catch (\Exception $e) {
				$this->SendDebug('Event Subscribe Error', $e->getMessage(), 0);
				return false;
			}
		}

		private function readEventSubscriptions(): array
		{
			$raw = (string)$this->ReadAttributeString('EventSubscriptions');
			$dec = json_decode($raw, true);
			return is_array($dec) ? $dec : [];
		}

		private function writeEventSubscriptions(array $subs): void
		{
			$this->WriteAttributeString('EventSubscriptions', json_encode($subs));
		}

		private function removeEventSubscription(string $deviceId): void
		{
			$subs = $this->readEventSubscriptions();
			if (isset($subs[$deviceId])) { unset($subs[$deviceId]); $this->writeEventSubscriptions($subs); }
		}
	}