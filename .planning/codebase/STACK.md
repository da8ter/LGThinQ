# Technology Stack

**Analysis Date:** 2026-03-27

## Languages

**Primary:**
- PHP (strict_types=1 enforced in all files) — all module business logic, API communication, MQTT routing
- JSON — configuration forms (`form.json`), locale strings (`locale.json`), module metadata (`module.json`, `library.json`)

**Secondary:**
- JavaScript (CommonJS) — GSD tooling in `.claude/get-shit-done/bin/` (development workflow tooling only, not part of module runtime)

## Runtime

**Environment:**
- IP-Symcon home automation platform, version compatibility: `7.1` (declared in `library.json`)
- PHP is embedded in the IP-Symcon runtime; no standalone PHP installation is managed by this module

**Package Manager:**
- No composer.json or package.json for module runtime
- `.claude/package.json` exists for GSD dev tooling only (not module dependencies)

## Frameworks

**Core:**
- IP-Symcon PHP Module SDK — base class `IPSModule`, all IPS_* global functions, KR_READY kernel runlevel, timer registration
- Module Strict pattern — `declare(strict_types=1)`, typed function signatures throughout

**Module Types:**
- `LGThinQBridge` — type 2 (Splitter), prefix `LGTQ`, GUID `{FCD02091-9189-0B0A-0C70-D607F1941C05}`
- `LGThinQDevice` — type 3 (Device), prefix `LGTQD`, GUID `{B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}`
- `LGThinQConfigurator` — type 4 (Configurator), prefix `LGTQC`, GUID `{7C4B0F16-7E13-4B44-9E2C-1B9C2C6B3B0F}`

**Build/Dev:**
- No build step required for module runtime
- GSD workflow tooling in `.claude/get-shit-done/` (Claude Code planning system)

## Key Dependencies

**Critical (runtime PHP extensions):**
- `openssl` — required for MQTT client certificate generation (EC P-256 / RSA 2048 keypair, CSR signing); `ThinQCertificateManager` checks `function_exists('openssl_pkey_new')` at runtime
- `curl` (optional, with `file_get_contents` fallback) — used in `ThinQCertificateManager::downloadAmazonRootCA1()` to fetch Amazon Root CA 1

**HTTP client:**
- Native PHP `file_get_contents` with `stream_context_create` — used in `ThinQHttpClient::request()` for all LG ThinQ Connect API calls (no third-party HTTP library)
- Timeout: 15 seconds per request
- Protocol: HTTP/1.1

**MQTT integration:**
- IP-Symcon built-in MQTT Client module, GUID `{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}` — Bridge connects as child of this Symcon MQTT module
- Topic filter pattern: `app/clients/{ClientID}/push` (configurable)

## Configuration

**Environment:**
- No `.env` files — all configuration stored in IP-Symcon module properties and attributes
- Required properties: `AccessToken` (LG PAT), `CountryCode` (ISO 2-letter, default `DE`)
- Optional properties: `ClientID`, `Debug`, `UseMQTT`, `MQTTClientID`, `MQTTTopicFilter`, `IgnoreRetained`, `EventTTLHrs` (1–24), `EventRenewLeadMin` (1–59), `PushCooldownMin` (default 30)

**Build:**
- No build configuration files

## Platform Requirements

**Development:**
- IP-Symcon 7.1+
- PHP with `openssl` extension enabled

**Production:**
- IP-Symcon 7.1+ runtime
- Outbound HTTPS access to `api-eic.lgthinq.com`, `api-aic.lgthinq.com`, or `api-kic.lgthinq.com` (region-resolved from country code)
- Outbound HTTPS access to `https://www.amazontrust.com/repository/AmazonRootCA1.pem` (for MQTT TLS CA download)
- IP-Symcon MQTT Client module configured for LG ThinQ AWS IoT MQTT broker (mTLS with LG-signed client certificate)

---

*Stack analysis: 2026-03-27*
