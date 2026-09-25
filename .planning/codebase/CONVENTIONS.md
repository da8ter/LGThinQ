# Code Conventions

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
