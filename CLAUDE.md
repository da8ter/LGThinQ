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
Three Symcon modules plus shared code in `libs/`: `ThinQModuleTrait` (translation, kernel check, module context), `ThinQModuleContext`, `ThinQJsonAttribute`, `ThinQClock`. Helper classes reach their module only through a `ThinQModuleContext` built from closures, so no internal function becomes an `LGTQ_`/`LGTQD_` script function. Every PHP file stays at or below 500 lines (checked by `tests/review_device_test.php`).
## Module Hierarchy (IP-Symcon)
```
Client Socket (TLS, LG-signed client certificate)
  └─ MQTT Client (Symcon)
       └─ LG ThinQ Bridge (Splitter, LGTQ) ── HTTPS ── LG ThinQ Connect API
            ├─ LG ThinQ Device (LGTQD), one per LG device
            └─ LG ThinQ Configurator (LGTQC)
```
## Component Breakdown
### LG ThinQ Bridge (`type: 2` — Splitter)
- PAT authentication; region from the country code (table of LG's SDK), unknown country → status 104
- Device list (attribute `Devices`), event subscriptions (renewed before the TTL runs out) and push subscriptions (at most daily)
- One-click MQTT setup: broker from LG's route, LG-signed certificate, MQTT Client and Client Socket
- MQTT messages to the children as `Event` (device state) or `Push` (notification)
- `SendDebug` is overridden and redacts secrets centrally
| Class | Responsibility |
|-------|---------------|
| `ThinQBridgeConfig` (`ThinQConfig.php`) | Bridge configuration, region, topic filter with `{ClientID}` |
| `ThinQHttpClient`, `ThinQHttpTransport` | HTTP to LG; an error answer is a `ThinQApiException` (HTTP status and LG code) |
| `ThinQApi` | The LG endpoints: devices, state, profile, control, energy, event/push subscriptions, client, certificate |
| `ThinQSubscriptionService` | Event and push subscriptions: when to renew, retries, cooldown, subscriptions without a device |
| `ThinQForwardHandler` | ForwardData actions of Device and Configurator |
| `ThinQMqttRouter`, `ThinQEventPipeline` | Topic filter level by level (`+`/`*` one level, `#` the rest), device ID, dispatch |
| `ThinQClientId` | The client ID (x-client-id, certificate CN, topic) |
| `ThinQCertificateManager`, `ThinQMqttCertBuilder` | CSR and LG-signed certificate; ZIP export for an external MQTT client |
| `ThinQMqttSetupWizard`, `ThinQMqttInstances` | The setup steps; reading and configuring MQTT Client and Client Socket |
| `ThinQRedactor` | Keeps the PAT, API key, PEM blocks and bearer tokens out of debug output |
### LG ThinQ Device (`type: 3` — Device)
- Variables, presentations and actions from the LG profile (capability plan)
- `ReceiveData`: `Event` is merged into the stored status, `Push` sets PUSH_LAST
- `RequestAction`: command through the Bridge
- Setup repeated with backoff until profile and device type are there; self-heal; energy
| Class | Responsibility |
|-------|---------------|
| `ThinQShape` | LG's data shapes: profile (zones, element lists by selector, WashTower parts), status, merge, ranges |
| `ThinQValue` | Variable type from the profile, values written by the actual variable type, command values |
| `ThinQNaming`, `ThinQGenericProperties`, `ThinQEnumTranslator` | Names from English sources (German via `locale.json`); enum captions per property in Symcon's language |
| `ThinQProfileParser` | Profile → plan entries (ident, name, type, path, selector, presentation) |
| `CapabilityEngine` with `CapabilityPlanBuilder`, `CapabilityProfileExtractor`, `CapabilityVarManager`, `CapabilityControlBuilder` | Plan, variable creation, reading the status, building commands |
| `ThinQDeviceSetup` | Setup run with backoff; variables, presentations, actions; renaming of legacy idents |
| `ThinQDeviceStatus` | Status, event and push into `LastStatus`, STATUS, LASTUPDATE and the variables; self-heal |
| `ThinQDeviceProfileManager` | Profile and device type from the Bridge, stored copies |
| `ThinQEnergyManager` | Energy profile and usage (ENERGY_*) |
| `ThinQPresentationBuilder` | Presentation arrays |
| `ThinQCleanup`, `ThinQSupportBundle`, `ThinQDeviceUtil` | Removing variables outside the plan; support package; the Bridge's answer, anonymizing |
### LG ThinQ Configurator (`type: 4` — Configurator)
- Lists the Bridge's devices and creates Device instances
## Data Flow
```
LG cloud ─MQTT─► MQTT Client ─► Bridge::ReceiveData ─► ThinQMqttRouter ─► SendDataToChildren {Action: Event | Push}
                                                                        └► Device::ReceiveData ─► ThinQDeviceStatus
Device::RequestAction ─► CapabilityEngine::buildControlPayload ─► ForwardData {Action: Control} ─► ThinQForwardHandler ─► ThinQApi::control
```
## Design Patterns
- **Splitter pattern** (IP-Symcon): the Bridge sits between MQTT/HTTPS and the Device instances
- **Module context**: helpers get a `ThinQModuleContext` of closures instead of public module methods
- **Attribute-backed state**: `ThinQJsonAttribute` for the device list and the subscriptions
- **Capability-driven variable creation**: profile → `ThinQShape` → `ThinQProfileParser` → `CapabilityEngine` plan → `MaintainVariable`
- **Errors as exceptions**: `ThinQApiException`; a failed call is never stored as data (profile, type, status)
## Key Abstractions
- `ThinQBridgeConfig` — typed configuration passed to the Bridge's classes
- `ThinQShape` — the one place that knows how LG shapes profiles, states and commands
- `CapabilityEngine::buildPlan()` — device type + profile + status → variable descriptors
- Data flow GUID `{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}` — interface contract between Bridge and Device/Configurator
## Entry Points
- `LGThinQBridge::Create()` / `ApplyChanges()` — lifecycle, timers, subscriptions
- `LGThinQBridge::ReceiveData()` — MQTT messages; `ForwardData()` — requests of the children
- `LGThinQDevice::ApplyChanges()` — setup; `ReceiveData()` — events and pushes; `RequestAction()` — commands
- `tests/run.sh` — bench without Symcon: kernel in memory, fake LG cloud with MQTT, 59 LG example devices and a live air conditioner
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
