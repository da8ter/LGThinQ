<?php

declare(strict_types=1);

// Load auto-discovery classes
require_once __DIR__ . '/ThinQGenericProperties.php';
require_once __DIR__ . '/ThinQEnumTranslator.php';
require_once __DIR__ . '/ThinQProfileParser.php';
require_once __DIR__ . '/CapabilityProfileExtractor.php';
require_once __DIR__ . '/CapabilityCatalogLoader.php';
require_once __DIR__ . '/CapabilityPlanBuilder.php';
require_once __DIR__ . '/CapabilityVarManager.php';
require_once __DIR__ . '/CapabilityControlBuilder.php';

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
    private string $baseDir;

    /** @var array<string, mixed> */
    private array $caps = [];

    /** @var array<string, mixed> */
    private array $flatProfile = [];
    /** @var array<string, mixed> */
    private array $flatStatus = [];

    /** @var ThinQProfileParser|null */
    private ?ThinQProfileParser $parser = null;

    /** @var CapabilityControlBuilder|null */
    private ?CapabilityControlBuilder $controlBuilder = null;

    /** @var bool */
    private bool $autoDiscoveryEnabled = true;
    
    /** @var callable|null */
    private $translateCallback = null;
    
    /** @var callable|null */
    private $maintainVariableCallback = null;

    public function __construct(int $instanceId, string $baseDir)
    {
        $this->instanceId = $instanceId;
        $this->baseDir = rtrim($baseDir, '/');
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
        return new CapabilityVarManager($this->caps, $this->flatProfile, $this->flatStatus);
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
            $this->getVarManager()
        );
    }

    /**
     * Set callback for maintaining variables (create/update/delete)
     * Signature: function(string $ident, string $name, int $type, string $profile, int $position, bool $keep): int
     */
    public function setMaintainVariableCallback(callable $callback): void
    {
        $this->maintainVariableCallback = $callback;
    }
    
    /**
     * Set translation callback for translating variable names
     * 
     * @param callable $callback Function that takes a string and returns translated string
     */
    public function setTranslateCallback(callable $callback): void
    {
        $this->translateCallback = $callback;
    }
    
    /**
     * Translate a string using the callback or return as-is
     * 
     * @param string $text Text to translate
     * @return string Translated text or original if no callback set
     */
    private function translate(string $text): string
    {
        if ($this->translateCallback !== null) {
            return ($this->translateCallback)($text);
        }
        return $text;
    }

    private function debugEnabled(): bool
    {
        // Read the parent module's Debug property to decide whether to emit debug logs
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
        $w = $cap['write'] ?? null;
        if (!is_array($w)) return false;
        foreach (['enumMap','template','composite','arrayTemplate','attribute','multiAttribute','firstOf'] as $k) {
            if (isset($w[$k]) && is_array($w[$k])) return true;
        }
        return false;
    }

    /**
     * Load capabilities for the given device type. Profile can be used to decide variants.
     *
     * @param string $deviceType
     * @param array<string, mixed> $profile
     */
    public function loadCapabilities(string $deviceType, array $profile): void
    {
        // Manual capability files are disabled. Use auto-discovery only.
        $this->caps = [];
        $this->flatProfile = $this->flatten($profile);
        $this->dbg('Manual capabilities disabled; using auto-discovery only.');
    }

    /**
     * Return loaded capability descriptors.
     * @return array<int, array<string, mixed>>
     */
    public function getDescriptors(): array
    {
        return array_values($this->caps);
    }
    
    /**
     * Get presentation map for all capabilities
     * @return array<string, array<string, mixed>> Map of ident => presentation
     */
    public function getPresentationMap(): array
    {
        $map = [];
        foreach ($this->caps as $ident => $cap) {
            if (isset($cap['presentation']) && is_array($cap['presentation'])) {
                $map[$ident] = $cap['presentation'];
            }
        }
        return $map;
    }

    /**
     * Ensure variables exist and attach actions according to descriptors.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $status
     * @param string $deviceType
     */
    public function ensureVariables(array $profile, ?array $status, string $deviceType): void
    {
        // Do NOT reload capabilities here - use the ones loaded by buildPlan()
        // This preserves auto-discovered capabilities based on current status
        
        $flatStatus = is_array($status) ? $this->flatten($status) : [];
        $this->flatStatus = $flatStatus;
        
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            
            $should = $this->shouldCreate($cap, $this->flatProfile, $flatStatus);
            $vid = $this->getVarId($ident);
            
            // Skip if variable should not be created (but never delete existing variables!)
            if (!$should && $vid === 0) {
                continue;
            }
            
            // Create variable if it doesn't exist
            if ($vid === 0) {
                $type = strtoupper((string)($cap['type'] ?? 'string'));
                $ipsType = match ($type) {
                    'BOOLEAN' => VARIABLETYPE_BOOLEAN,
                    'INTEGER' => VARIABLETYPE_INTEGER,
                    'FLOAT'   => VARIABLETYPE_FLOAT,
                    default   => VARIABLETYPE_STRING
                };
                $name = (string)($cap['name'] ?? $ident);
                
                // Use MaintainVariable callback if available, otherwise fallback to manual creation
                if ($this->maintainVariableCallback !== null) {
                    $vid = call_user_func($this->maintainVariableCallback, $ident, $name, $ipsType, '', 0, true);
                } else {
                    // Fallback: manual creation (legacy)
                    $vid = IPS_CreateVariable($ipsType);
                    IPS_SetParent($vid, $this->instanceId);
                    IPS_SetIdent($vid, $ident);
                    IPS_SetName($vid, $name);
                }
            }
            
            // EnableAction: always, profile writeable, or fallback when a write mapping exists
            $enableWhen = strtolower((string)($cap['action']['enableWhen'] ?? ''));
            
            if ($enableWhen === 'always') {
                $this->enableAction($ident);
            } elseif ($enableWhen === 'profilewriteableany') {
                $writeKeys = $cap['action']['writeableKeys'] ?? [];
                $hasWrite = is_array($writeKeys) && $this->profileHasWriteAny($writeKeys);
                
                if ($hasWrite) {
                    $this->enableAction($ident);
                } else {
                    // Fallback: if the capability defines a write mapping, still enable action
                    if ($this->capHasWriteDefinition($cap)) {
                        $this->enableAction($ident);
                    }
                }
            } else {
                $this->dbg(sprintf('NOT enabling action for %s (enableWhen=%s)', $ident, $enableWhen));
            }
        }
    }

    /**
     * Re-enable actions for variables that request reassertOn:["setup"].
     * Call this after variables were created and presentations applied.
     */
    public function reassertActionsOnSetup(): void
    {
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            $reassert = $cap['action']['reassertOn'] ?? [];
            if (is_array($reassert) && in_array('setup', array_map('strtolower', $reassert), true)) {
                $this->enableAction($ident);
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
        // 1. Load manual capabilities (if exist)
        $this->loadCapabilities($deviceType, $profile);
        $this->flatProfile = $this->flatten($profile);
        $this->flatStatus = is_array($status) ? $this->flatten($status) : [];

        // 2. Auto-discover from profile (if enabled), apply companion patterns, add ERROR_LAST/PUSH_LAST
        if ($this->autoDiscoveryEnabled) {
            $builder = new CapabilityPlanBuilder(
                $this->caps,
                $this->flatProfile,
                function(string $msg) { $this->dbg($msg); },
                $this->getExtractor(),
                $this->getParser()
            );
            $builder->run($profile);
        }

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
                'location' => $cap['location'] ?? null // NEW: Location support
            ];

            if ($shouldCreate) {
                $value = $this->readValue($cap, $this->flatStatus);
                if ($value !== null) {
                    $entry['initialValue'] = $value;
                }
            }

            $plan[$ident] = $entry;
        }
        
        // Translate all variable names from English to user's language
        // This uses Symcon's locale.json for translations via callback
        foreach ($plan as $ident => &$entry) {
            if (isset($entry['name'])) {
                $entry['name'] = $this->translate($entry['name']);
            }
        }

        return $plan;
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
            // NOTE: Do not re-enable actions on every status update to avoid noisy logs and redundant calls.
            // Action enabling is performed during variable creation in the main module (SetupDeviceVariables).
        }
        // Timer reset: when a timer status changes to UNSET (false), zero the related hour/minute vars
        $this->resetDeactivatedTimers($flat);

        $this->dbg(sprintf('applyStatus: %d capabilities, %d updated, %d skipped (no variable)', count($this->caps), $updated, $skipped));
    }

    /**
     * When a timer control variable (*_START_TIMER, *_STOP_TIMER) reports UNSET,
     * set the corresponding HOUR_TO_* and MINUTE_TO_* variables to 0.
     *
     * Ident mapping:
     *   *_START_TIMER  → *_HOUR_TO_START  + *_MINUTE_TO_START
     *   *_STOP_TIMER   → *_HOUR_TO_STOP   + *_MINUTE_TO_STOP
     */
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
                $current = @GetValueInteger($vid);
                if ($current !== 0) {
                    @SetValueInteger($vid, 0);
                    $this->dbg(sprintf('resetDeactivatedTimers: %s → 0 (timer %s is UNSET)', $resetIdent, $ident));
                }
            }
        }
    }

    /**
     * Build a control payload for a given ident/value based on capability descriptor.
     * Returns null if the ident is not handled by capabilities.
     *
     * @param string $ident
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    public function buildControlPayload(string $ident, $value): ?array
    {
        return $this->getControlBuilder()->buildControlPayload($ident, $value);
    }

    // ---------- Helpers ----------

    /** @param array<string, mixed> $cap */
    private function shouldCreate(array $cap, array $flatProfile, array $flatStatus): bool
    {
        $create = $cap['create'] ?? [];
        $when = strtolower((string)($create['when'] ?? 'always'));
        $keys = $create['keys'] ?? [];
        if ($when === 'always') return true;
        if (!is_array($keys) || empty($keys)) return false;
        if ($when === 'profilehasall') {
            // All keys must be present in profile (direct or substring match)
            foreach ($keys as $k) {
                $k = (string)$k;
                if ($k === '') return false;
                $found = array_key_exists($k, $flatProfile);
                if (!$found) {
                    foreach ($flatProfile as $fk => $_) {
                        if (strpos($fk, $k) !== false) { $found = true; break; }
                    }
                }
                if (!$found) return false;
            }
            return true;
        }
        if ($when === 'profilehasany') {
            foreach ($keys as $k) { if (array_key_exists($k, $flatProfile)) return true; }
            // Substring match (handles array prefixes like property.0.*)
            foreach ($keys as $k) {
                foreach ($flatProfile as $fk => $_) {
                    if (strpos($fk, $k) !== false) return true;
                }
            }
            // As a last resort, treat writeable mode as present
            foreach ($keys as $b) {
                if ($this->profileHasWriteAny([$b . '.mode'])) return true;
            }
            return false;
        }
        if ($when === 'statushasany') {
            // 1) Direct key present
            foreach ($keys as $k) { if (array_key_exists($k, $flatStatus)) return true; }
            // 2) Substring match (covers simple nesting)
            foreach ($keys as $k) {
                foreach ($flatStatus as $fk => $_) {
                    if (strpos($fk, $k) !== false) return true;
                }
            }
            // 3) Index-insensitive match: ignore numeric array indices in status paths
            //    Example: status has 'temperature.0.targetTemperature' while key is 'temperature.targetTemperature'
            foreach ($keys as $k) {
                foreach ($flatStatus as $fk => $_) {
                    $fkNorm = preg_replace('/\.\d+(?=\.|$)/', '', (string)$fk);
                    if ($fkNorm === $k || strpos((string)$fkNorm, (string)$k) !== false) {
                        return true;
                    }
                }
            }
            return false;
        }
        return false;
    }

    private function enableAction(string $ident): void
    {
        $this->dbg(sprintf('enableAction called for ident: %s, instanceId: %d - SKIPPING (will be handled by main module)', $ident, $this->instanceId));

        // NOTE: Action enabling is now handled directly in the main module's SetupDeviceVariables method
        // using $this->EnableAction() which is the correct Symcon approach
        // This method is kept for compatibility but doesn't do the actual enabling anymore
    }

    /** @param array<string, mixed> $cap */
    private function shouldEnableAction(array $cap): bool
    {
        $enableWhen = strtolower((string)($cap['action']['enableWhen'] ?? ''));
        if ($enableWhen === 'never') {
            return false;
        }
        if ($enableWhen === 'always') {
            return true;
        }
        if ($enableWhen === 'profilewriteableany') {
            $writeKeys = $cap['action']['writeableKeys'] ?? [];
            if (is_array($writeKeys) && $this->profileHasWriteAny($writeKeys)) {
                return true;
            }
            return $this->capHasWriteDefinition($cap);
        }
        return false;
    }

    private function profileHasWriteAny(array $writeableKeys): bool
    {
        return $this->getControlBuilder()->profileHasWriteAny($writeableKeys);
    }

    private function modeHasW($mode): bool
    {
        return $this->getControlBuilder()->modeHasW($mode);
    }

    /** @param array<int, string> $keys */
    private function flatProfileHasAny(array $keys): bool
    {
        return $this->getControlBuilder()->flatProfileHasAny($keys);
    }

    private function flatProfileIsWriteable(string $basePath): bool
    {
        return $this->getControlBuilder()->flatProfileIsWriteable($basePath);
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

    private function getFromFlat(array $flat, string $path)
    {
        return $flat[$path] ?? null;
    }

    private function setValueByType(int $vid, array $cap, $val): void
    {
        $this->getVarManager()->setValueByType($vid, $cap, $val);
    }

    private function convertValueForType(array $cap, $value)
    {
        return $this->getVarManager()->convertValueForType($cap, $value);
    }

    private function replaceTemplatePlaceholders(array $tpl, $value): array
    {
        return $this->getVarManager()->replaceTemplatePlaceholders($tpl, $value);
    }

    private function walkReplace(&$node, $value): void
    {
        $this->getVarManager()->walkReplace($node, $value);
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

    /**
     * Find min/max/step for a resource.property from the flattened profile.
     * Looks for keys like:
     *   property.<resource>.<index?>.<property>.value.w.{min|max|step}
     * and returns the first values found.
     * @return array{min?:float,max?:float,step?:float}|null
     */
    private function findRangeFromProfile(string $resource, string $property): ?array
    {
        return $this->getVarManager()->findRangeFromProfile($resource, $property);
    }

    private function findArrayIndex(array $flat, string $container, array $where): ?int
    {
        return $this->getVarManager()->findArrayIndex($flat, $container, $where);
    }

    private function collectArrayIndices(array $flat, string $container): array
    {
        return $this->getVarManager()->collectArrayIndices($flat, $container);
    }

    // === Auto-Discovery Helper Methods ===

    /**
     * Get or create ProfileParser instance
     */
    private function getParser(): ThinQProfileParser
    {
        if ($this->parser === null) {
            $this->parser = new ThinQProfileParser();
            // Set translation callback to use locale.json via Symcon's Translate()
            $this->parser->setTranslateCallback(function($text) {
                return $this->translate($text);
            });
        }
        return $this->parser;
    }

}
