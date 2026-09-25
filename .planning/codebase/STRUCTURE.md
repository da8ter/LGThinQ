# Directory Structure

## Root Layout
```
LGThinQ/                          ← Library root
├── library.json                  ← Library metadata (id, name, version, author)
├── README.md                     ← Library overview
├── LG ThinQ Bridge/              ← Splitter module (type 2)
├── LG ThinQ Device/              ← Device module (type 3)
├── LG ThinQ Configurator/        ← Configurator module (type 4)
├── .API References/              ← LG ThinQ OpenAPI specs (reference only, not loaded at runtime)
└── .claude/                      ← Claude Code / GSD tooling configuration
```

## Module Directory Layout (each module follows this pattern)
```
LG ThinQ Bridge/
├── module.php            ← Main module class (IPSModule subclass)
├── module.json           ← Symcon module manifest (GUID, type, prefix, parent/child GUIDs)
├── form.json             ← IP-Symcon configuration UI definition
├── locale.json           ← Translation strings
├── README.md             ← Module-specific documentation
└── libs/                 ← Supporting PHP classes (all require_once'd by module.php)
    ├── ThinQConfig.php
    ├── ThinQHttpClient.php
    ├── ThinQDeviceRepository.php
    ├── ThinQEventSubscriptionRepository.php
    ├── ThinQEventManager.php
    ├── ThinQEventPipeline.php
    ├── ThinQMqttRouter.php
    ├── ThinQCertificateManager.php
    ├── ThinQApiErrorCodes.php
    └── ThinQHelpers.php

LG ThinQ Device/
├── module.php
├── module.json
├── form.json
├── locale.json
├── README.md
└── libs/
    ├── CapabilityEngine.php      ← Core capability→variable mapping (2,585 lines)
    ├── ThinQProfileParser.php
    ├── ThinQGenericProperties.php
    └── ThinQEnumTranslator.php

LG ThinQ Configurator/
├── module.php
├── module.json
└── locale.json
```

## Key Files
| File | Purpose |
|------|---------|
| `library.json` | Library GUID, version (`0.1.7`), IP-Symcon compatibility (`7.1`) |
| `LG ThinQ Bridge/module.php` | Bridge entry point; ~1,751 lines |
| `LG ThinQ Bridge/libs/ThinQHttpClient.php` | All HTTP calls to LG ThinQ Connect API |
| `LG ThinQ Bridge/libs/ThinQCertificateManager.php` | TLS client cert generation for MQTT |
| `LG ThinQ Device/module.php` | Device entry point; ~1,889 lines |
| `LG ThinQ Device/libs/CapabilityEngine.php` | Maps LG profiles to IPS variables; ~2,585 lines |
| `.API References/thinq_connect_openapi.json` | LG ThinQ Connect REST API spec |
| `.API References/thinq_device_profiles_openapi.json` | LG device profile schema spec |

## Naming Conventions
- **Module class names**: `LGThinQ{Role}` (e.g. `LGThinQBridge`, `LGThinQDevice`)
- **Library class names**: `ThinQ{Responsibility}` (e.g. `ThinQHttpClient`, `ThinQEventManager`)
- **Public method prefix**: matches `module.json` prefix — `LGTQ_` (Bridge), `LGTQD_` (Device), `LGTQC_` (Configurator)
- **Variable idents**: `SCREAMING_SNAKE_CASE` (e.g. `ENERGY_YESTERDAY`, `LAST_UPDATE`)
- **PHP constants**: `SCREAMING_SNAKE_CASE` private const
- **Files**: `ThinQPascalCase.php` for lib classes, `module.php` for main entry

## Module Boundaries
- Bridge ↔ Device communicate exclusively through the IP-Symcon data flow mechanism (GUIDs)
- Bridge sends JSON payloads to children via `SendDataToChildren()`
- Devices receive via `ReceiveData(string $JSONString)`
- No direct PHP class references cross module boundaries (Bridge libs are not accessible from Device)
- Configurator queries the Bridge's data flow interface to discover devices
