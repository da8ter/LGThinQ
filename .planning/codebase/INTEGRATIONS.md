# External Integrations

**Analysis Date:** 2026-03-27

## APIs & Services

**LG ThinQ Connect API (primary):**
- Base URL pattern: `https://api-{REGION}.lgthinq.com/` where `{REGION}` is resolved from country code
  - `EIC` — Europe, Middle East, Africa: DE, AT, CH, FR, IT, ES, GB, IE, NL, BE, DK, SE, NO, FI, PL, PT, GR, CZ, HU, RO, BG, HR, SK, SI, LT, LV, EE, LU, MT, CY, IS, RU, UA, TR, ZA, EG, SA, AE, IL, IN
  - `AIC` — Americas: US, CA, AR, BR, CL, CO, MX, PE, UY, VE, PR, EC, PA, CR, DO, GT, HN, SV, NI, BO, PY
  - `KIC` — Asia-Pacific: JP, KR, AU, NZ, CN, HK, TW, SG, TH, VN, MY, ID, PH, MM, KH, LA, BD, LK, PK, NP (also default fallback)
- Client: `ThinQHttpClient` in `LG ThinQ Bridge/libs/ThinQHttpClient.php` using PHP `file_get_contents` + `stream_context_create`

**Endpoints used:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `devices` | Fetch all registered devices |
| `GET` | `devices/{deviceId}/state` | Fetch current device status |
| `GET` | `devices/{deviceId}/profile` | Fetch device capability profile |
| `POST` | `devices/{deviceId}/control` | Send control commands to device |
| `GET` | `devices/energy/{deviceId}/profile` | Fetch energy capability profile |
| `GET` | `devices/energy/{deviceId}/usage` | Fetch energy usage data (with query params: property, period, startDate, endDate) |
| `POST` | `event/{deviceId}/subscribe` | Subscribe to device state change events (with TTL body) |
| `DELETE` | `event/{deviceId}/unsubscribe` | Unsubscribe from device events |
| `POST` | `push/devices` | Register client as push recipient (idempotent) |
| `DELETE` | `push/devices` | Deregister client as push recipient |
| `POST` | `push/{deviceId}/subscribe` | Subscribe to push notifications for a device |
| `DELETE` | `push/{deviceId}/unsubscribe` | Unsubscribe from push notifications |
| `POST` | `client` | Register MQTT client (type: MQTT, service-code: SVC202, device-type: 607) |
| `POST` | `client/certificate` | Request LG-signed client certificate (submits CSR, receives certificatePem) |

**Amazon Trust Services:**
- URL: `https://www.amazontrust.com/repository/AmazonRootCA1.pem`
- Purpose: Download Amazon Root CA 1 for validating AWS IoT MQTT broker TLS certificate
- Client: `ThinQCertificateManager::downloadAmazonRootCA1()` in `LG ThinQ Bridge/libs/ThinQCertificateManager.php`
- Used when setting up MQTT TLS connection to LG's AWS IoT endpoint

## Authentication

**LG ThinQ Personal Access Token (PAT):**
- Type: Bearer token in `Authorization: Bearer {token}` HTTP header
- Stored in IP-Symcon module property `AccessToken`
- Persisted to attribute `AccessTokenBackup` to survive module reloads
- Error codes for auth failures: `TOKEN_EXPIRED` (0200), `TOKEN_INVALID` (0201), `PAT_EXPIRED` (0202), `PAT_INVALID` (0203)

**API Key (hardcoded):**
- Constant `LGThinQBridge::API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3'`
- Sent as `x-api-key` HTTP header on every request
- Source: `LG ThinQ Bridge/module.php` line 18

**MQTT mTLS (client certificate):**
- Flow: Module generates EC P-256 keypair (RSA 2048 fallback) + CSR via PHP OpenSSL → submits CSR to `POST client/certificate` → LG returns signed `certificatePem` → stored in IP-Symcon MQTT Client module configuration
- Certificate CN: IP-Symcon ClientID (e.g., `Symcon12345`)
- CA: Amazon Root CA 1 fetched from `amazontrust.com` or extracted from API `subscriptions` response metadata

**Required HTTP headers per request:**
- `Authorization: Bearer {accessToken}`
- `x-country: {countryCode}`
- `x-message-id: {base64url random 16 bytes}` (generated per-request via `ThinQHelpers::generateMessageId()`)
- `x-client-id: {clientId}`
- `x-api-key: {API_KEY}`
- `x-service-phase: OP`
- `Content-Type: application/json`

## Data Sources

**No external database.** All state is stored in IP-Symcon module attributes:
- `Devices` — JSON array of discovered devices (cached in Bridge module attribute)
- `EventSubscriptions` — JSON map of active event subscriptions with expiry timestamps
- `LastStatus` — last received device state JSON (stored per Device module instance)
- `LastProfile` — last fetched device capability profile JSON (per Device module)
- `LastPlan` — last computed variable creation plan (per Device module)
- `EnergyProfile` — energy capability profile (per Device module)

## Protocols

**HTTPS (REST):**
- All LG ThinQ Connect API calls use HTTPS with JSON request/response bodies
- HTTP method: GET for reads, POST for creates/subscriptions/control, DELETE for unsubscriptions
- Response envelope: API returns `{"response": {...}}` or direct object; `ThinQHttpClient` unwraps `response` key automatically
- Error format: `{"error": {"code": "XXXX", "message": "..."}}`

**MQTT (push events):**
- LG's AWS IoT-based MQTT broker (endpoint hostname provided during certificate provisioning)
- TLS with mutual authentication (mTLS): client presents LG-signed certificate; broker presents Amazon-CA-signed certificate
- IP-Symcon's built-in MQTT Client module (GUID `{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}`) handles the TCP/TLS MQTT connection
- Bridge subscribes to topic: `app/clients/{ClientID}/push` (configurable via `MQTTTopicFilter` property)
- Wildcard support: `*`, `+`, `#` in topic filter (`ThinQMqttRouter::topicMatches()`)
- Push message types handled: `DEVICE_STATUS`, `DEVICE_REGISTERED`, `DEVICE_UNREGISTERED`, `DEVICE_ALIAS_CHANGED`, `DEVICE_PUSH`
- Retained messages: skipped by default (configurable via `IgnoreRetained` property)

## Third-party Services

**AWS IoT Core (via LG):**
- LG ThinQ uses AWS IoT Core as its MQTT broker backend
- Amazon Root CA 1 is required for TLS verification of the broker endpoint
- The module does not communicate directly with AWS APIs; the connection is brokered through LG's infrastructure

**pythinqconnect (reference only):**
- Error code definitions in `ThinQApiErrorCodes.php` are modeled after the open-source Python SDK
- Reference: `https://github.com/thinq-connect/pythinqconnect`
- No runtime dependency; used only as API documentation reference

## Webhooks & Callbacks

**Incoming (MQTT push):**
- IP-Symcon MQTT Client delivers messages to `LGThinQBridge::ReceiveData()` which delegates to `ThinQMqttRouter::handle()`
- Routed to child Device instances via `SendDataToChildren` with DataID `{5E9D1B64-0F44-4F21-9D74-09C5BB90FB2F}`

**Outgoing:**
- None — no webhooks sent to external systems

## Event Subscription Lifecycle

- Subscribe: `POST event/{deviceId}/subscribe` with `{"expire": {"unit": "HOUR", "timer": N}}` (N = 1–24 hours)
- Auto-renewal: `EventRenewTimer` fires at interval = `(TTL hours × 3600) - (lead minutes × 60)` seconds; calls `ThinQEventManager::renewExpiring()`
- Cooldown: Push re-subscription guarded by `PushCooldownMin` property (default 30 min) to avoid rate limiting
- Unsubscribe: `DELETE event/{deviceId}/unsubscribe`

---

*Integration audit: 2026-03-27*
