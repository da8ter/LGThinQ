# Testing

## Test Infrastructure
- **No test files found** in the repository
- No PHPUnit configuration (`phpunit.xml`, `phpunit.xml.dist`)
- No `composer.json` with test dependencies
- No CI configuration files (`.github/`, `.travis.yml`, etc.)

## Test Coverage
- **Coverage: 0%** — no automated tests exist
- All testing appears to be manual / runtime testing within IP-Symcon

## Test Patterns
- N/A — no test patterns established

## Test Organization
- No `/tests` directory exists
- No test helper classes

## Running Tests
- No test runner configured
- Manual testing via IP-Symcon instance required

## Gaps & Risks
- **Critical gap**: Zero automated test coverage across all modules
- No unit tests for business logic in `CapabilityEngine`, `ThinQHttpClient`, `ThinQMqttRouter`
- No integration tests for LG ThinQ API communication
- No regression safety net for refactoring
- Complex logic in `CapabilityEngine` (capability mapping, plan building) is entirely untested
- Certificate management (`ThinQCertificateManager`) has no test coverage despite security criticality
- Event pipeline logic (`ThinQEventPipeline`) untested
- All error paths and edge cases must be discovered in production
