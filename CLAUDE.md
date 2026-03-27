<!-- GSD:project-start source:PROJECT.md -->
## Project

**LG ThinQ Sync — Optimierung**

IP-Symcon Modulbibliothek (v0.1.7) zur Integration von LG ThinQ Geräten. Sie verbindet IP-Symcon mit der LG ThinQ Connect Cloud API über HTTPS und empfängt Echtzeit-Geräteereignisse via MQTT. Ein capability-getriebenes System übersetzt LG-Gerätprofile in IP-Symcon Variablen mit passenden Darstellungen.

Ziel dieser Optimierung: Code-Qualität und Wartbarkeit verbessern — God-Classes aufteilen, gemeinsamen Code in einen Trait extrahieren und toten Code entfernen. Ohne Änderung des öffentlichen Verhaltens.

**Core Value:** Bestehende Symcon-Instanzen laufen nach dem Refactoring genauso wie vorher — null Regressions, bessere Wartbarkeit.

### Constraints

- **Kompatibilität:** Öffentliche Methoden (LGTQ_, LGTQD_, LGTQC_), Module-GUIDs, Properties und Variablen-Idents bleiben identisch — kleinere interne Umstrukturierungen sind erlaubt
- **Symcon-Standards:** CLAUDE.md Regeln sind verbindlich (strict_types, Presentation-Arrays, parent::Calls, Kernel-Guard, Zeilenlimit 500)
- **PHP:** Kein Composer, kein Autoloader — alle Klassen per require_once eingebunden
- **Dateigröße:** Jede Datei unter 500 Zeilen (CLAUDE.md Vorgabe)
<!-- GSD:project-end -->

<!-- GSD:stack-start source:codebase/STACK.md -->
## Technology Stack

## Languages
- PHP (strict_types=1 enforced in all files) — all module business logic, API communication, MQTT routing
- JSON — configuration forms (`form.json`), locale strings (`locale.json`), module metadata (`module.json`, `library.json`)
- JavaScript (CommonJS) — GSD tooling in `.claude/get-shit-done/bin/` (development workflow tooling only, not part of module runtime)
## Runtime
- IP-Symcon home automation platform, version compatibility: `7.1` (declared in `library.json`)
- PHP is embedded in the IP-Symcon runtime; no standalone PHP installation is managed by this module
- No composer.json or package.json for module runtime
- `.claude/package.json` exists for GSD dev tooling only (not module dependencies)
## Frameworks
- IP-Symcon PHP Module SDK — base class `IPSModule`, all IPS_* global functions, KR_READY kernel runlevel, timer registration
- Module Strict pattern — `declare(strict_types=1)`, typed function signatures throughout
- `LGThinQBridge` — type 2 (Splitter), prefix `LGTQ`, GUID `{FCD02091-9189-0B0A-0C70-D607F1941C05}`
- `LGThinQDevice` — type 3 (Device), prefix `LGTQD`, GUID `{B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}`
- `LGThinQConfigurator` — type 4 (Configurator), prefix `LGTQC`, GUID `{7C4B0F16-7E13-4B44-9E2C-1B9C2C6B3B0F}`
- No build step required for module runtime
- GSD workflow tooling in `.claude/get-shit-done/` (Claude Code planning system)
## Key Dependencies
- `openssl` — required for MQTT client certificate generation (EC P-256 / RSA 2048 keypair, CSR signing); `ThinQCertificateManager` checks `function_exists('openssl_pkey_new')` at runtime
- `curl` (optional, with `file_get_contents` fallback) — used in `ThinQCertificateManager::downloadAmazonRootCA1()` to fetch Amazon Root CA 1
- Native PHP `file_get_contents` with `stream_context_create` — used in `ThinQHttpClient::request()` for all LG ThinQ Connect API calls (no third-party HTTP library)
- Timeout: 15 seconds per request
- Protocol: HTTP/1.1
- IP-Symcon built-in MQTT Client module, GUID `{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}` — Bridge connects as child of this Symcon MQTT module
- Topic filter pattern: `app/clients/{ClientID}/push` (configurable)
## Configuration
- No `.env` files — all configuration stored in IP-Symcon module properties and attributes
- Required properties: `AccessToken` (LG PAT), `CountryCode` (ISO 2-letter, default `DE`)
- Optional properties: `ClientID`, `Debug`, `UseMQTT`, `MQTTClientID`, `MQTTTopicFilter`, `IgnoreRetained`, `EventTTLHrs` (1–24), `EventRenewLeadMin` (1–59), `PushCooldownMin` (default 30)
- No build configuration files
## Platform Requirements
- IP-Symcon 7.1+
- PHP with `openssl` extension enabled
- IP-Symcon 7.1+ runtime
- Outbound HTTPS access to `api-eic.lgthinq.com`, `api-aic.lgthinq.com`, or `api-kic.lgthinq.com` (region-resolved from country code)
- Outbound HTTPS access to `https://www.amazontrust.com/repository/AmazonRootCA1.pem` (for MQTT TLS CA download)
- IP-Symcon MQTT Client module configured for LG ThinQ AWS IoT MQTT broker (mTLS with LG-signed client certificate)
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

## Language Standards
- PHP with `declare(strict_types=1)` in every file
- Return types used on most public functions (some older methods without due to IPS compatibility)
- Nullable types (`?ClassName`) used for optional dependencies
- `\Throwable` catch blocks (not just `\Exception`)
## Naming Conventions
- Classes: `PascalCase` (e.g. `LGThinQBridge`, `ThinQHttpClient`)
- Methods: `camelCase` (e.g. `buildPlan`, `readLastStatus`)
- Constants: `SCREAMING_SNAKE_CASE` (e.g. `DATA_FLOW_GUID`, `PROFILE_PREFIX`)
- Properties (IPS): `PascalCase` strings (e.g. `'AccessToken'`, `'CountryCode'`)
- Variable identifiers: `SCREAMING_SNAKE_CASE` (e.g. `'ENERGY_YESTERDAY'`, `'LAST_UPDATE'`)
- File names: `ThinQPascalCase.php` for libs, `module.php` for main entry
## Code Style
- 4-space indentation
- Allman-style braces for classes, K&R for control flow
- Private fields initialized as `null` with nullable types
- Dependency objects constructed lazily inside methods
## Documentation
- DocBlocks on public utility methods (`@return`, `@param` used selectively)
- Inline comments for non-obvious Symcon patterns (kernel ready checks, timer usage)
- German strings appear in some older UI labels; newer code uses `$this->Translate()`
## Error Handling
- `try/catch (\Throwable $e)` pattern used throughout
- Errors logged via `$this->LogMessage($e->getMessage(), KL_ERROR)` or `$this->SendDebug()`
- Graceful fallback with `@` suppression on IPS API calls that may return `false`
## Common Patterns
- Lazy dependency initialization: private fields set to `null`, constructed on first use via getter methods
- Kernel readiness guard in `ApplyChanges()`: check `IPS_GetKernelRunlevel() === KR_READY`, register `IPS_KERNELSTARTED` message if not ready
- `MessageSink()` calls `ApplyChanges()` on `IPS_KERNELSTARTED`
- Timers registered at interval `0` in `Create()`, interval set in `ApplyChanges()`
- `parent::Create()`, `parent::ApplyChanges()`, `parent::Destroy()` always called
- Presentation arrays (GUIDs as constants) used instead of variable profiles
- `$this->t()` wrapper method for translation (delegates to `$this->Translate()`)
- IPS child/parent GUID constants defined as `private const`
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

## System Overview
## Module Hierarchy (IP-Symcon)
```
```
## Component Breakdown
### LG ThinQ Bridge (`type: 2` — Splitter)
- OAuth/PAT authentication with LG ThinQ Connect API
- Device list management (fetched and cached as attribute JSON)
- MQTT topic subscription and certificate provisioning
- Push event TTL management and renewal timer
- Routing `ReceiveData` payloads to child modules via `SendDataToChildren`
| Class | Responsibility |
|-------|---------------|
| `ThinQConfig` | Typed value object for Bridge configuration |
| `ThinQHttpClient` | Wraps `file_get_contents` for LG API HTTP calls |
| `ThinQDeviceRepository` | Manages cached device list (stored as attribute JSON) |
| `ThinQEventSubscriptionRepository` | Manages push subscription state per device |
| `ThinQEventManager` | Orchestrates event subscription creation/renewal |
| `ThinQEventPipeline` | Processes incoming MQTT payloads, routes to devices |
| `ThinQMqttRouter` | Parses MQTT topics, matches device IDs |
| `ThinQCertificateManager` | Generates/stores TLS client certificates for MQTT |
| `ThinQApiErrorCodes` | Classifies API error codes (retry vs auth vs fatal) |
| `ThinQHelpers` | Shared utility functions |
### LG ThinQ Device (`type: 3` — Device)
- Maintaining IP-Symcon variables that represent device state
- `ReceiveData`: processing status updates from Bridge
- `RequestAction`: forwarding user commands to Bridge → API
- Building variable/presentation plan from device capability profile
- Energy usage variable management
| Class | Responsibility |
|-------|---------------|
| `CapabilityEngine` | Core: maps LG capability profiles → IPS variable plan + presentations |
| `ThinQProfileParser` | Parses LG device profile JSON into structured capability objects |
| `ThinQGenericProperties` | Defines generic properties common to all device types |
| `ThinQEnumTranslator` | Translates LG enum values to human-readable strings |
### LG ThinQ Configurator (`type: 4` — Configurator)
## Data Flow
```
```
## Design Patterns
- **Splitter pattern** (IP-Symcon): Bridge acts as protocol splitter between MQTT and child Device modules
- **Repository pattern**: `ThinQDeviceRepository`, `ThinQEventSubscriptionRepository` for attribute-backed state
- **Value object**: `ThinQConfig` encapsulates Bridge configuration
- **Capability-driven variable creation**: Device profile JSON → `CapabilityEngine` → variable plan → `MaintainVariable`
- **Lazy initialization**: private dependencies set to `null`, instantiated on first use
## Key Abstractions
- `ThinQBridgeConfig` — typed config object passed to lib classes
- `CapabilityEngine::buildPlan()` — central function mapping device type + profile + status → array of variable descriptors
- Data flow GUID `{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}` — interface contract between Bridge and Device/Configurator
## Entry Points
- `LGThinQBridge::Create()` / `ApplyChanges()` — module lifecycle, starts timers and MQTT subscription
- `LGThinQBridge::ReceiveData()` — MQTT event entry point from IP-Symcon
- `LGThinQDevice::ReceiveData()` — status update entry from Bridge
- `LGThinQDevice::RequestAction()` — user action entry (e.g. slider/switch change)
<!-- GSD:architecture-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd:quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd:debug` for investigation and bug fixing
- `/gsd:execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->



<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd:profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
