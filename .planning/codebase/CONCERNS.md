# Concerns & Risks

## Security Concerns
- **Hardcoded API key**: `public const API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3'` in `LG ThinQ Bridge/module.php:18` — a secret should never be a class constant in committed source code
- **PAT plain-text backup**: Personal Access Token stored as unencrypted attribute (`AccessTokenBackup`)
- **Private key unencrypted**: Certificate private key written into ZIP without encryption (`ThinQCertificateManager`)

## Symcon Standards Violations
- **Forbidden variable profile on module-managed variable**: `MaintainVariable('LASTUPDATE', ..., '~UnixTimestamp', ...)` in `LG ThinQ Device/module.php:148` — CLAUDE.md explicitly forbids using old-style profiles (`~...`) on variables the module creates itself; must use a Presentation array instead

## Maintainability Issues (God Classes)
- `CapabilityEngine.php`: **2,585 lines** — 5× over the 500-line guideline; handles capability mapping, plan building, variable registration all in one class
- `LG ThinQ Bridge/module.php`: **~1,751 lines** — should be split into focused classes
- `LG ThinQ Device/module.php`: **~1,889 lines** — same issue

## Dead Code
- `updateFromStatus()` is an empty placeholder method
- `flattenKeys()` / `flattenKeysRecursive()` are defined but never called
- `ThinQApiErrorCodes` class defines retry/auth-error classification but is never used at runtime

## Duplication
- Helper methods `t()`, `isKernelReady()`, `findModuleGUIDByName()` duplicated identically in both Bridge and Device module files — should be in a shared trait or base class
- OpenSSL config string duplicated between `module.php` and `ThinQCertificateManager`

## Performance Risks
- O(n×m) profile-vs-status comparison on every MQTT event received
- Synchronous API profile fetch inside `ReceiveData()` — blocks the data pipeline
- `file_get_contents` HTTP client with no connection reuse or keep-alive
- Two separate `GetDevices` API calls per device setup

## Missing Features / Incomplete Implementations
- No PAT expiry detection or proactive refresh
- No retry/backoff on transient API errors (despite `ThinQApiErrorCodes` defining the classification)
- `CleanupVariables(false)` claims to hide variables but never calls `IPS_SetHidden()` — effectively a no-op
- No online/offline connectivity status variable exposed to the user

## Technical Debt
- `UpdateEnergy` timer registered inside `setupDevice()` at runtime instead of in `Create()` as required by Symcon conventions
- Some public methods lack return types (IPS compatibility workarounds)
- Mixed German/English in UI labels and comments

## Improvement Opportunities
- Extract shared helpers (`t()`, `isKernelReady()`) into a `ThinQModuleTrait`
- Break `CapabilityEngine` into device-type-specific handlers
- Replace hardcoded `API_KEY` with a Symcon property or environment-level config
- Add Presentation array for `LASTUPDATE` variable (fix the `~UnixTimestamp` profile violation)
- Implement `ThinQApiErrorCodes` retry logic in `ThinQHttpClient`
