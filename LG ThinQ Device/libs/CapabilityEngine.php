<?php

declare(strict_types=1);

// Load auto-discovery classes
require_once __DIR__ . '/ThinQNaming.php';
require_once __DIR__ . '/ThinQShape.php';
require_once __DIR__ . '/ThinQValue.php';
require_once __DIR__ . '/ThinQProfileParser.php';
require_once __DIR__ . '/CapabilityProfileExtractor.php';
require_once __DIR__ . '/CapabilityPlanBuilder.php';
require_once __DIR__ . '/CapabilityVarManager.php';
require_once __DIR__ . '/CapabilityControlBuilder.php';
require_once __DIR__ . '/CapabilityOptionStates.php';

/**
 * CapabilityEngine
 *
 * A small engine to drive variable creation, action enabling, status updates,
 * and control payload generation from JSON capability descriptors.
 *
 * NOTE: This class intentionally uses global IPS_* functions and requires the
 * instance ID to operate. It does not depend on private methods from the module.
 */
class CapabilityEngine
{
    private int $instanceId;

    /** @var array<string, mixed> */
    private array $caps = [];

    /** @var array<string, mixed> the profile as buildPlan got it (stored form, see ThinQShape) */
    private array $profile = [];

    /** @var array<string, mixed> */
    private array $flatProfile = [];
    /** @var array<string, mixed> */
    private array $flatStatus = [];

    /** @var array<int, string> idents (with message) that failed creation in the last ensureVariables() run */
    private array $createFailures = [];

    /** @var ThinQProfileParser|null */
    private ?ThinQProfileParser $parser = null;

    /** @var callable|null */
    private $translateCallback = null;
    
    /** @var callable|null */
    private $maintainVariableCallback = null;

    public function __construct(int $instanceId)
    {
        $this->instanceId = $instanceId;
    }

    /**
     * Lazy-init CapabilityProfileExtractor for current state
     */
    private function getExtractor(): CapabilityProfileExtractor
    {
        return new CapabilityProfileExtractor($this->flatProfile, $this->translateCallback);
    }

    /**
     * Lazy-init CapabilityVarManager for current state
     */
    private function getVarManager(): CapabilityVarManager
    {
        return new CapabilityVarManager($this->flatProfile, $this->flatStatus);
    }

    /**
     * Lazy-init CapabilityControlBuilder for current state.
     * Re-created on each call so it always has fresh caps/profile/status.
     */
    private function getControlBuilder(): CapabilityControlBuilder
    {
        return new CapabilityControlBuilder(
            $this->caps,
            $this->flatProfile,
            $this->flatStatus,
            $this->instanceId,
            function(string $msg) { $this->dbg($msg); },
            $this->getVarManager(),
            $this->profile
        );
    }

    /** Signature: function(string $ident, string $name, int $type, string $profile, int $position, bool $keep): int */
    public function setMaintainVariableCallback(callable $callback): void
    {
        $this->maintainVariableCallback = $callback;
    }

    public function setTranslateCallback(callable $callback): void
    {
        $this->translateCallback = $callback;
    }

    private function debugEnabled(): bool
    {
        try {
            $v = @IPS_GetProperty($this->instanceId, 'Debug');
            return is_bool($v) ? $v : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function dbg(string $message): void
    {
        if ($this->debugEnabled()) {
            @IPS_LogMessage('CapabilityEngine', $message);
        }
    }

    /** @param array<string, mixed> $cap */
    private function capHasWriteDefinition(array $cap): bool
    {
        return $this->getControlBuilder()->capHasWriteDefinition($cap);
    }

    /** @return array<int, array<string, mixed>> */
    public function getDescriptors(): array
    {
        return array_values($this->caps);
    }

    /**
     * Values the current plan reads from a status, keyed by ident (idents without a value are left out).
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    public function readValues(array $status): array
    {
        $flat = $this->flatten($status);
        $out = [];
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            $val = $this->readValue($cap, $flat);
            if ($val !== null) {
                $out[$ident] = $val;
            }
        }
        return $out;
    }

    /** @return array<int, string> idents that only appear with a status value (create: statusHasAny), have one now, but no variable */
    public function missingStatusVariables(): array
    {
        $out = [];
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident !== '' && strtolower((string)($cap['create']['when'] ?? '')) === 'statushasany'
                && $this->getVarId($ident) === 0 && $this->shouldCreate($cap, $this->flatProfile, $this->flatStatus)) {
                $out[] = $ident;
            }
        }
        return $out;
    }

    /** @return array<int, string> idents (with message) that failed to create in the last ensureVariables() run */
    public function getCreateFailures(): array
    {
        return $this->createFailures;
    }

    /** @param array<string, mixed>|null $status */
    public function ensureVariables(array $profile, ?array $status, string $deviceType): void
    {
        if ($this->maintainVariableCallback === null) {
            throw new \LogicException('ensureVariables needs the MaintainVariable callback');
        }
        // Use caps loaded by buildPlan() — do NOT reload here (preserves auto-discovered caps).
        $flatStatus = is_array($status) ? $this->flatten($status) : [];
        $this->flatStatus = $flatStatus;
        $this->createFailures = [];

        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            // Best-effort per capability: a failure on a single variable must not
            // abort creation of all the others (incl. the always-create ERROR_LAST/PUSH_LAST).
            try {
                if ($this->getVarId($ident) > 0 || !$this->shouldCreate($cap, $this->flatProfile, $flatStatus)) {
                    continue;
                }
                $type = strtoupper((string)($cap['type'] ?? 'string'));
                $ipsType = match ($type) {
                    'BOOLEAN' => VARIABLETYPE_BOOLEAN,
                    'INTEGER' => VARIABLETYPE_INTEGER,
                    'FLOAT'   => VARIABLETYPE_FLOAT,
                    default   => VARIABLETYPE_STRING
                };
                $vid = (int)call_user_func($this->maintainVariableCallback, $ident, (string)($cap['name'] ?? $ident), $ipsType, '', 0, true);
                if ($vid <= 0) {
                    throw new \RuntimeException('Symcon did not create the variable');
                }
            } catch (\Throwable $e) {
                $this->createFailures[] = $ident . ': ' . $e->getMessage();
                $this->dbg(sprintf('ensureVariables: failed for ident=%s: %s', $ident, $e->getMessage()));
                continue;
            }
        }
    }

    /**
     * Determine idents that should have actions enabled on setup.
     * This mirrors the enable logic from ensureVariables() and considers reassertOn:["setup"].
     * @return array<int, string>
     */
    public function listIdentsToEnableOnSetup(): array
    {
        $out = [];
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            $reassert = $cap['action']['reassertOn'] ?? [];
            if (!is_array($reassert) || !in_array('setup', array_map('strtolower', $reassert), true)) {
                continue;
            }
            $enableWhen = strtolower((string)($cap['action']['enableWhen'] ?? ''));
            if ($enableWhen === 'always') {
                $out[] = $ident;
                continue;
            }
            if ($enableWhen === 'profilewriteableany') {
                $writeKeys = $cap['action']['writeableKeys'] ?? [];
                $hasWrite = is_array($writeKeys) && $this->profileHasWriteAny($writeKeys);
                if ($hasWrite || $this->capHasWriteDefinition($cap)) {
                    $out[] = $ident;
                }
            }
        }
        return $out;
    }

    /**
     * Determine idents that should have actions enabled right now based on enableWhen rules.
     * @return array<int, string>
     */
    public function listIdentsToEnable(): array
    {
        $out = [];
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            $enableWhen = strtolower((string)($cap['action']['enableWhen'] ?? ''));
            if ($enableWhen === 'always') {
                $out[] = $ident; continue;
            }
            if ($enableWhen === 'profilewriteableany') {
                $writeKeys = $cap['action']['writeableKeys'] ?? [];
                $hasWrite = is_array($writeKeys) && $this->profileHasWriteAny($writeKeys);
                if ($hasWrite || $this->capHasWriteDefinition($cap)) {
                    $out[] = $ident;
                }
            }
        }
        return $out;
    }

    /**
     * Build a configuration plan describing which variables should exist and how they should be presented.
     * With auto-discovery support.
     *
     * @param string $deviceType
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $status
     * @return array<string, array<string, mixed>> keyed by ident
     */
    public function buildPlan(string $deviceType, array $profile, ?array $status): array
    {
        // Auto-discover from the profile, apply companion patterns, add ERROR_LAST/PUSH_LAST
        $this->caps = [];
        $this->profile = $profile;
        $this->flatProfile = $this->flatten($profile);
        $this->flatStatus = is_array($status) ? $this->flatten($status) : [];
        $builder = new CapabilityPlanBuilder(
            $this->caps,
            $this->flatProfile,
            function(string $msg) { $this->dbg($msg); },
            $this->getExtractor(),
            $this->getParser()
        );
        $builder->run($profile);

        // 3. Build final plan (existing logic)
        $plan = [];
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') {
                continue;
            }

            $shouldCreate = $this->shouldCreate($cap, $this->flatProfile, $this->flatStatus);
            $entry = [
                'ident' => $ident,
                'type' => strtoupper((string)($cap['type'] ?? 'string')),
                'name' => (string)($cap['name'] ?? $ident),
                'hidden' => (bool)($cap['visibility']['hidden'] ?? false),
                'shouldCreate' => $shouldCreate,
                'presentation' => isset($cap['presentation']) && is_array($cap['presentation']) ? $cap['presentation'] : null,
                'enableAction' => $shouldCreate && $this->shouldEnableAction($cap),
                'location' => $cap['location'] ?? null,
                'legacyIdent' => $cap['legacyIdent'] ?? null
            ];

            if ($shouldCreate) {
                $value = $this->readValue($cap, $this->flatStatus);
                if ($value !== null) {
                    $entry['initialValue'] = $value;
                    $entry['presentation'] = CapabilityOptionStates::extend($entry['presentation'], $value, $this->getVarId($ident),
                        fn(string $state): string => $this->getParser()->naming()->caption((string)($cap['property'] ?? ''), $state));
                }
            }

            $plan[$ident] = $entry;
        }

        return $plan;
    }

    /**
     * Idents whose variable reports a value its options do not contain (a state outside LG's lists):
     * ensureVariables() then brings the extended presentation.
     *
     * @return array<int, string>
     */
    public function unknownOptionValues(): array
    {
        $out = [];
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            $vid = $ident !== '' ? $this->getVarId($ident) : 0;
            if ($vid > 0 && is_array($cap['presentation']['options'] ?? null)
                && CapabilityOptionStates::unknown($vid, $this->readValue($cap, $this->flatStatus))) {
                $out[] = $ident;
            }
        }
        return $out;
    }

    /**
     * Apply status to variables declared in capabilities and reassert actions if desired.
     *
     * @param array<string, mixed> $status
     */
    public function applyStatus(array $status): void
    {
        $flat = $this->flatten($status);
        $this->flatStatus = $flat;
        $updated = 0;
        $skipped = 0;
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            $vid = $this->getVarId($ident);
            if ($vid === 0) {
                $skipped++;
                continue;
            }
            // Read value
            $val = $this->readValue($cap, $flat);
            // Special handling for ERROR_LAST and PUSH_LAST: extract from raw status if not mapped
            if ($val === null) {
                if ($ident === 'ERROR_LAST') {
                    $val = $this->getExtractor()->extractErrorFromStatus($status, $flat);
                } elseif ($ident === 'PUSH_LAST') {
                    $val = $this->getExtractor()->extractPushFromStatus($status, $flat);
                }
            }
            if ($val !== null) {
                $this->setValueByType($vid, $cap, $val);
                $updated++;
            }
        }
        $this->resetDeactivatedTimers($flat);

        $this->dbg(sprintf('applyStatus: %d capabilities, %d updated, %d skipped (no variable)', count($this->caps), $updated, $skipped));
    }

    /** Zero HOUR_TO and MINUTE_TO vars when their START_TIMER or STOP_TIMER reports UNSET. */
    private function resetDeactivatedTimers(array $flat): void
    {
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if (!preg_match('/^(.+)_(START|STOP)_TIMER$/i', $ident, $m)) {
                continue;
            }
            $val = $this->readValue($cap, $flat);
            if ($val !== false) {
                continue;
            }
            $prefix = $m[1];
            $direction = strtoupper($m[2]); // START or STOP
            $hourIdent   = $prefix . '_HOUR_TO_' . $direction;
            $minuteIdent = $prefix . '_MINUTE_TO_' . $direction;

            foreach ([$hourIdent, $minuteIdent] as $resetIdent) {
                $vid = $this->getVarId($resetIdent);
                if ($vid === 0) {
                    continue;
                }
                ThinQValue::write($vid, 0); // hours of some devices are FLOAT (number), others INTEGER
                $this->dbg(sprintf('resetDeactivatedTimers: %s → 0 (timer %s is UNSET)', $resetIdent, $ident));
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function buildControlPayload(string $ident, $value): ?array
    {
        return $this->getControlBuilder()->buildControlPayload($ident, $value);
    }

    // ---------- Helpers ----------

    /** @param array<string, mixed> $cap */
    private function shouldCreate(array $cap, array $flatProfile, array $flatStatus): bool
    {
        return $this->getControlBuilder()->shouldCreate($cap, $flatProfile, $flatStatus);
    }

    /** @param array<string, mixed> $cap */
    private function shouldEnableAction(array $cap): bool
    {
        return $this->getControlBuilder()->shouldEnableAction($cap);
    }

    private function profileHasWriteAny(array $writeableKeys): bool
    {
        return $this->getControlBuilder()->profileHasWriteAny($writeableKeys);
    }

    /**
     * Read value using 'read' section from descriptor.
     * @param array<string, mixed> $cap
     * @param array<string, mixed> $flat
     */
    private function readValue(array $cap, array $flat)
    {
        return $this->getVarManager()->readValue($cap, $flat);
    }

    private function setValueByType(int $vid, array $cap, $val): void
    {
        $this->getVarManager()->setValueByType($vid, $cap, $val);
    }

    private function getVarId(string $ident): int
    {
        return (int)@IPS_GetObjectIDByIdent($ident, $this->instanceId);
    }

    /** @param array<string, mixed> $arr */
    private function flatten(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += $this->flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    // === Auto-Discovery Helper Methods ===

    /**
     * Get or create ProfileParser instance
     */
    private function getParser(): ThinQProfileParser
    {
        if ($this->parser === null) {
            // Names in Symcon's language: English sources, translated through locale.json
            $this->parser = new ThinQProfileParser(new ThinQNaming($this->translateCallback !== null ? Closure::fromCallable($this->translateCallback) : null));
        }
        return $this->parser;
    }

}
