# Architecture

## System Overview
LG ThinQ Sync is an IP-Symcon module library (v0.1.7) that integrates LG ThinQ smart home devices into IP-Symcon. It connects to the LG ThinQ Connect cloud API via HTTPS and receives real-time device events via MQTT push subscriptions. The system translates LG device capability profiles into IP-Symcon variables with appropriate presentations.

## Module Hierarchy (IP-Symcon)

```
[MQTT Client Module]  ←  parentRequirement of Bridge
        │
[LG ThinQ Bridge]  (type: 2 = Splitter, prefix: LGTQ)
        │   GUID: {FCD02091-9189-0B0A-0C70-D607F1941C05}
        │   DataFlow GUID: {A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}
        ├── [LG ThinQ Device]       (type: 3 = Device, prefix: LGTQD)
        │       GUID: {B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}
        └── [LG ThinQ Configurator] (type: 4 = Configurator, prefix: LGTQC)
                GUID: {7C4B0F16-7E13-4B44-9E2C-1B9C2C6B3B0F}
```

## Component Breakdown

### LG ThinQ Bridge (`type: 2` — Splitter)
The central hub. Manages authentication, device discovery, MQTT subscription lifecycle, and routes incoming events to child Device instances.

Key responsibilities:
- OAuth/PAT authentication with LG ThinQ Connect API
- Device list management (fetched and cached as attribute JSON)
- MQTT topic subscription and certificate provisioning
- Push event TTL management and renewal timer
- Routing `ReceiveData` payloads to child modules via `SendDataToChildren`

Supporting library classes:
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
Represents a single LG appliance. Receives status payloads from Bridge and maps them to IP-Symcon variables using a capability-driven approach.

Key responsibilities:
- Maintaining IP-Symcon variables that represent device state
- `ReceiveData`: processing status updates from Bridge
- `RequestAction`: forwarding user commands to Bridge → API
- Building variable/presentation plan from device capability profile
- Energy usage variable management

Supporting library classes:
| Class | Responsibility |
|-------|---------------|
| `CapabilityEngine` | Core: maps LG capability profiles → IPS variable plan + presentations |
| `ThinQProfileParser` | Parses LG device profile JSON into structured capability objects |
| `ThinQGenericProperties` | Defines generic properties common to all device types |
| `ThinQEnumTranslator` | Translates LG enum values to human-readable strings |

### LG ThinQ Configurator (`type: 4` — Configurator)
Discovers available LG devices from the Bridge and allows creating Device instances via the IP-Symcon UI.

## Data Flow

```
LG ThinQ Cloud API
    │  HTTPS (REST)
    ▼
ThinQHttpClient ──► ThinQEventManager (subscribe / renew push events)
    │
    │  MQTT push events
    ▼
[MQTT Client Module] ──► Bridge.ReceiveData()
                              │
                              ▼
                         ThinQEventPipeline
                              │  routes by DeviceID
                              ▼
                         Device.ReceiveData()
                              │
                              ▼
                         CapabilityEngine.buildPlan()
                              │
                              ▼
                         MaintainVariable() / SetValue()
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
