<?php

declare(strict_types=1);

// Load auto-discovery classes
require_once __DIR__ . '/ThinQGenericProperties.php';
require_once __DIR__ . '/ThinQEnumTranslator.php';
require_once __DIR__ . '/ThinQProfileParser.php';
require_once __DIR__ . '/CapabilityProfileExtractor.php';
require_once __DIR__ . '/CapabilityCatalogLoader.php';
require_once __DIR__ . '/CapabilityPlanBuilder.php';

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
        $cap = $this->caps[$ident] ?? null;
        if (!is_array($cap)) {
            $this->dbg(sprintf('buildControlPayload: Capability not found for ident=%s (available: %s)', $ident, implode(', ', array_keys($this->caps))));
            return null;
        }
        $this->dbg(sprintf('buildControlPayload: Found capability for %s, write config: %s', $ident, json_encode($cap['write'] ?? null)));
        // Clamp
        if (isset($cap['write']['clamp']) && is_array($cap['write']['clamp'])) {
            $min = $cap['write']['clamp']['min'] ?? null;
            $max = $cap['write']['clamp']['max'] ?? null;
            if (is_numeric($min)) { $value = max((int)$min, (int)$value); }
            if (is_numeric($max)) { $value = min((int)$max, (int)$value); }
        }
        // Composite decompose
        if (isset($cap['write']['composite']) && is_array($cap['write']['composite'])) {
            $comp = $cap['write']['composite'];
            $fn = strtolower((string)($comp['decompose'] ?? ''));
            $targets = $comp['targets'] ?? [];
            $out = [];
            if ($fn === 'minutes_to_hm' && is_array($targets) && count($targets) >= 2) {
                $total = (int)$value;
                $h = intdiv($total, 60);
                $m = $total % 60;
                $t0 = (string)($targets[0]['path'] ?? '');
                $t1 = (string)($targets[1]['path'] ?? '');
                if ($t0 !== '') $this->setByPath($out, $t0, $h);
                if ($t1 !== '') $this->setByPath($out, $t1, $m);
                return $out;
            }
        }
        // Enum map (map incoming value to a set of target paths/values)
        if (isset($cap['write']['enumMap']) && is_array($cap['write']['enumMap'])) {
            $map = $cap['write']['enumMap'];
            // Convert boolean to string properly: false -> "false", true -> "true"
            if (is_bool($value)) {
                $key = $value ? 'true' : 'false';
            } else {
                $key = (string)$value;
            }
            if (array_key_exists($key, $map) && is_array($map[$key])) {
                $out = [];
                foreach ($map[$key] as $path => $v) {
                    $this->setByPath($out, (string)$path, $v);
                }
                // Per SDK: Washer/Oven/Cooktop enum writes need top-level location wrapping
                $enumLocWrap = $cap['write']['enumMapLocationWrap'] ?? null;
                if (is_string($enumLocWrap) && $enumLocWrap !== '') {
                    $out['location'] = ['locationName' => $enumLocWrap];
                }
                // coSendConst for enumMap (e.g., Oven cookMode → ovenOperationMode=START)
                $enumConst = $cap['write']['enumMapCoSendConst'] ?? null;
                if (is_array($enumConst)) {
                    foreach ($enumConst as $entry) {
                        if (!is_array($entry)) continue;
                        $cRes = (string)($entry['resource'] ?? '');
                        $cProp = (string)($entry['property'] ?? '');
                        $cVal = $entry['value'] ?? null;
                        if ($cRes === '' || $cProp === '' || $cVal === null) continue;
                        if (!isset($out[$cRes]) || !is_array($out[$cRes])) {
                            $out[$cRes] = [];
                        }
                        if (!array_key_exists($cProp, $out[$cRes])) {
                            $out[$cRes][$cProp] = $cVal;
                        }
                    }
                }
                return $out;
            }
        }
        // arrayTemplate: choose array element by where and set fields
        if (isset($cap['write']['arrayTemplate']) && is_array($cap['write']['arrayTemplate'])) {
            $cfg = $cap['write']['arrayTemplate'];
            $container = (string)($cfg['container'] ?? '');
            $path = (string)($cfg['path'] ?? ''); // optional base path inside element
            $where = is_array($cfg['where'] ?? null) ? $cfg['where'] : [];
            $set   = is_array($cfg['set'] ?? null) ? $cfg['set'] : [];
            if ($container !== '' && !empty($set)) {
                $flatSrc = $this->flatStatus ?: $this->flatProfile;
                $idx = $this->findArrayIndex($flatSrc, $container, $where);
                if ($idx === null) return null;
                $out = [];
                foreach ($set as $k => $v) {
                    $tplVal = $v;
                    $this->walkReplace($tplVal, $value);
                    $p = $container . '.' . $idx . '.' . ($path !== '' ? ($path . '.') : '') . (string)$k;
                    $this->setByPath($out, $p, $tplVal);
                }
                // Normalize container to a sequential array if numeric indices are present
                if (isset($out[$container]) && is_array($out[$container])) {
                    $allNumeric = true;
                    foreach (array_keys($out[$container]) as $k2) {
                        if (!is_int($k2)) { $allNumeric = false; break; }
                    }
                    if ($allNumeric) {
                        $out[$container] = array_values($out[$container]);
                    }
                }
                return $out;
            }
        }
        // Template
        if (isset($cap['write']['template']) && is_array($cap['write']['template'])) {
            $tpl = $cap['write']['template'];
            $converted = $this->convertValueForType($cap, $value);
            $out = $this->replaceTemplatePlaceholders($tpl, $converted);
            return $out;
        }

        // attribute: generic builder inspired by SDK
        // {
        //   "write": {
        //     "attribute": {
        //       "resource": "temperatureInUnits",
        //       "property": "targetTemperatureC",
        //       "extras": { "locationName": "FRIDGE" },
        //       "coSend": { "unit": "C" },
        //       "clampFromProfile": true
        //     }
        //   }
        // }
        // Optional: "locationWrap": "MAIN" → puts location as separate top-level key (washer/oven pattern)
        if (isset($cap['write']['attribute']) && is_array($cap['write']['attribute'])) {
            $cfg = $cap['write']['attribute'];
            $resource = (string)($cfg['resource'] ?? '');
            $property = (string)($cfg['property'] ?? '');
            $extras = is_array($cfg['extras'] ?? null) ? $cfg['extras'] : [];
            if ($resource !== '' && $property !== '') {
                // Special handling for timer properties: send BOTH hour and minute when partner is writable
                $timerPair = $this->getTimerPairValue($ident, $property, $value);
                if ($timerPair !== null) {
                    $resourcePayload = $extras + $timerPair;
                    if (is_array($cfg['coSend'] ?? null)) {
                        $resourcePayload = $cfg['coSend'] + $resourcePayload;
                    }
                    $payload = [$resource => $resourcePayload];
                    if (isset($cfg['locationWrap']) && is_string($cfg['locationWrap']) && $cfg['locationWrap'] !== '') {
                        $payload['location'] = ['locationName' => $cfg['locationWrap']];
                        unset($payload[$resource]['locationName']);
                    }
                    $this->applyCoSendFromStatus($cfg, $payload);
                    $this->applyCoSendConst($cfg, $payload);
                    return $payload;
                }
                // Optional clamp from profile writable range
                if (!empty($cfg['clampFromProfile'])) {
                    $rng = $this->findRangeFromProfile($resource, $property);
                    if (is_array($rng)) {
                        $v = $value;
                        if (isset($rng['min']) && is_numeric($rng['min'])) { $v = max((float)$rng['min'], (float)$v); }
                        if (isset($rng['max']) && is_numeric($rng['max'])) { $v = min((float)$rng['max'], (float)$v); }
                        $value = $v;
                    }
                }
                // Optional valueTemplate (e.g. @onoff, @startstop)
                if (isset($cfg['valueTemplate'])) {
                    $node = $cfg['valueTemplate'];
                    $this->walkReplace($node, $value);
                    $converted = $node;
                } else {
                    $converted = $this->convertValueForType($cap, $value);
                }
                $resourcePayload = $extras + [$property => $converted];
                // coSend: additional properties to merge into the resource payload
                if (is_array($cfg['coSend'] ?? null)) {
                    $resourcePayload = $cfg['coSend'] + $resourcePayload;
                }
                $payload = [$resource => $resourcePayload];
                // locationWrap: put location as separate top-level key (SDK washer pattern)
                if (isset($cfg['locationWrap']) && is_string($cfg['locationWrap']) && $cfg['locationWrap'] !== '') {
                    $payload['location'] = ['locationName' => $cfg['locationWrap']];
                    unset($payload[$resource]['locationName']);
                }
                // coSendFromStatus: include partner properties' current values in payload
                $this->applyCoSendFromStatus($cfg, $payload);
                // coSendConst: include constant companion values in payload
                $this->applyCoSendConst($cfg, $payload);
                return $payload;
            }
        }

        // multiAttribute: build a combined payload of multiple attribute entries
        if (isset($cap['write']['multiAttribute']) && is_array($cap['write']['multiAttribute'])) {
            $cfg = $cap['write']['multiAttribute'];
            $items = is_array($cfg['items'] ?? null) ? $cfg['items'] : [];
            if (!empty($items)) {
                $payload = [];
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $resource = (string)($item['resource'] ?? '');
                    $property = (string)($item['property'] ?? '');
                    if ($resource === '' || $property === '') continue;
                    $extras = is_array($item['extras'] ?? null) ? $item['extras'] : [];
                    $useVal = array_key_exists('valueConst', $item) ? $item['valueConst'] : $value;
                    // Optional clamp
                    if (!empty($item['clampFromProfile'])) {
                        $rng = $this->findRangeFromProfile($resource, $property);
                        if (is_array($rng) && is_numeric($useVal)) {
                            $v = $useVal;
                            if (isset($rng['min']) && is_numeric($rng['min'])) { $v = max((float)$rng['min'], (float)$v); }
                            if (isset($rng['max']) && is_numeric($rng['max'])) { $v = min((float)$rng['max'], (float)$v); }
                            $useVal = $v;
                        }
                    }
                    // Optional valueTemplate per item
                    if (isset($item['valueTemplate'])) {
                        $node = $item['valueTemplate'];
                        $this->walkReplace($node, $useVal);
                        $converted = $node;
                    } else {
                        $converted = $this->convertValueForType($cap, $useVal);
                    }
                    if (!isset($payload[$resource]) || !is_array($payload[$resource])) {
                        $payload[$resource] = [];
                    }
                    $payload[$resource] = $extras + $payload[$resource];
                    $payload[$resource][$property] = $converted;
                }
                if (!empty($payload)) return $payload;
            }
        }

        // firstOf: pick the first matching write option based on profile keys
        if (isset($cap['write']['firstOf']) && is_array($cap['write']['firstOf'])) {
            foreach ($cap['write']['firstOf'] as $opt) {
                if (!is_array($opt)) continue;
                // Prefer explicit writeable condition
                $writeKeys = $opt['profileWriteableAny'] ?? [];
                if (is_array($writeKeys) && !empty($writeKeys)) {
                    $ok = false;
                    foreach ($writeKeys as $wk) {
                        $wk = (string)$wk;
                        if ($wk === '') continue;
                        // Use robust writeability detection that supports ".mode" paths and wrappers
                        if ($this->profileHasWriteAny([$wk])) { $ok = true; break; }
                        // Backward-compatibility: if caller provided a base path (or we can derive it), test that too
                        $base = preg_replace('/\.(mode|type)$/i', '', $wk);
                        if (is_string($base) && $base !== '' && $this->flatProfileIsWriteable($base)) { $ok = true; break; }
                    }
                    if (!$ok) continue; // not writeable, skip option
                } else {
                    // Fallback: presence only
                    $keys = $opt['profileHasAny'] ?? ($opt['whenProfileHasAny'] ?? []);
                    $keys = is_array($keys) ? $keys : [];
                    if (!empty($keys) && !$this->flatProfileHasAny($keys)) {
                        continue;
                    }
                }
                // Support attribute within firstOf
                if (isset($opt['attribute']) && is_array($opt['attribute'])) {
                    $cfg = $opt['attribute'];
                    $resource = (string)($cfg['resource'] ?? '');
                    $property = (string)($cfg['property'] ?? '');
                    $extras = is_array($cfg['extras'] ?? null) ? $cfg['extras'] : [];
                    if ($resource !== '' && $property !== '') {
                        $useVal = $value;
                        if (!empty($cfg['clampFromProfile'])) {
                            $rng = $this->findRangeFromProfile($resource, $property);
                            if (is_array($rng) && is_numeric($useVal)) {
                                $v = $useVal;
                                if (isset($rng['min']) && is_numeric($rng['min'])) { $v = max((float)$rng['min'], (float)$v); }
                                if (isset($rng['max']) && is_numeric($rng['max'])) { $v = min((float)$rng['max'], (float)$v); }
                                $useVal = $v;
                            }
                        }
                        if (isset($cfg['valueTemplate'])) {
                            $node = $cfg['valueTemplate'];
                            $this->walkReplace($node, $useVal);
                            $converted = $node;
                        } else {
                            $converted = $this->convertValueForType($cap, $useVal);
                        }
                        $resourcePayload = $extras + [$property => $converted];
                        if (is_array($cfg['coSend'] ?? null)) {
                            $resourcePayload = $cfg['coSend'] + $resourcePayload;
                        }
                        $payload = [$resource => $resourcePayload];
                        if (isset($cfg['locationWrap']) && is_string($cfg['locationWrap']) && $cfg['locationWrap'] !== '') {
                            $payload['location'] = ['locationName' => $cfg['locationWrap']];
                            unset($payload[$resource]['locationName']);
                        }
                        return $payload;
                    }
                }
                // Support template within firstOf
                if (isset($opt['template']) && is_array($opt['template'])) {
                    $converted = $this->convertValueForType($cap, $value);
                    $out = $this->replaceTemplatePlaceholders($opt['template'], $converted);
                    return $out;
                }
                // Support enumMap within firstOf (optional)
                if (isset($opt['enumMap']) && is_array($opt['enumMap'])) {
                    $map = $opt['enumMap'];
                    $key = (string)$value;
                    if (array_key_exists($key, $map) && is_array($map[$key])) {
                        $out = [];
                        foreach ($map[$key] as $path => $v) {
                            $this->setByPath($out, (string)$path, $v);
                        }
                        return $out;
                    }
                }
                // Support composite within firstOf (optional)
                if (isset($opt['composite']) && is_array($opt['composite'])) {
                    $comp = $opt['composite'];
                    $fn = strtolower((string)($comp['decompose'] ?? ''));
                    $targets = $comp['targets'] ?? [];
                    if ($fn === 'minutes_to_hm' && is_array($targets) && count($targets) >= 2) {
                        $total = (int)$value;
                        $h = intdiv($total, 60);
                        $m = $total % 60;
                        $t0 = (string)($targets[0]['path'] ?? '');
                        $t1 = (string)($targets[1]['path'] ?? '');
                        $out = [];
                        if ($t0 !== '') $this->setByPath($out, $t0, $h);
                        if ($t1 !== '') $this->setByPath($out, $t1, $m);
                        return $out;
                    }
                }
                // Support multiAttribute within firstOf
                if (isset($opt['multiAttribute']) && is_array($opt['multiAttribute'])) {
                    $cfg = $opt['multiAttribute'];
                    $items = is_array($cfg['items'] ?? null) ? $cfg['items'] : [];
                    if (!empty($items)) {
                        $payload = [];
                        foreach ($items as $item) {
                            if (!is_array($item)) continue;
                            $resource = (string)($item['resource'] ?? '');
                            $property = (string)($item['property'] ?? '');
                            if ($resource === '' || $property === '') continue;
                            $extras = is_array($item['extras'] ?? null) ? $item['extras'] : [];
                            $useVal = array_key_exists('valueConst', $item) ? $item['valueConst'] : $value;
                            if (!empty($item['clampFromProfile'])) {
                                $rng = $this->findRangeFromProfile($resource, $property);
                                if (is_array($rng) && is_numeric($useVal)) {
                                    $v = $useVal;
                                    if (isset($rng['min']) && is_numeric($rng['min'])) { $v = max((float)$rng['min'], (float)$v); }
                                    if (isset($rng['max']) && is_numeric($rng['max'])) { $v = min((float)$rng['max'], (float)$v); }
                                    $useVal = $v;
                                }
                            }
                            if (isset($item['valueTemplate'])) {
                                $node = $item['valueTemplate'];
                                $this->walkReplace($node, $useVal);
                                $converted = $node;
                            } else {
                                $converted = $this->convertValueForType($cap, $useVal);
                            }
                            if (!isset($payload[$resource]) || !is_array($payload[$resource])) {
                                $payload[$resource] = [];
                            }
                            $payload[$resource] = $extras + $payload[$resource];
                            $payload[$resource][$property] = $converted;
                        }
                        if (!empty($payload)) return $payload;
                    }
                }
            }
        }
        return null;
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
        foreach ($writeableKeys as $wk) {
            $wk = (string)$wk;
            if ($wk === '') continue;
            // 1) Direct mode value
            if (array_key_exists($wk, $this->flatProfile) && $this->modeHasW($this->flatProfile[$wk])) {
                return true;
            }
            // 2) Any nested path under the given key shows a writeable mode
            $prefix = $wk . '.';
            foreach ($this->flatProfile as $k => $v) {
                if (strpos($k, $prefix) === 0 && $this->modeHasW($v)) return true;
            }
            // 2b) Wrapped direct mode keys (profile./value./property./indexed)
            $wrappers = ['','property.','value.','profile.'];
            foreach ($wrappers as $wrap) {
                $cand = $wrap . $wk;
                if (array_key_exists($cand, $this->flatProfile) && $this->modeHasW($this->flatProfile[$cand])) return true;
                for ($i = 0; $i <= 4; $i++) {
                    $candIdx = $wrap . $i . '.' . $wk;
                    if (array_key_exists($candIdx, $this->flatProfile) && $this->modeHasW($this->flatProfile[$candIdx])) return true;
                }
            }
            // 2c) Suffix match: any key ending with the wk path (covers additional nesting above)
            $suffix = '.' . $wk;
            foreach ($this->flatProfile as $k => $v) {
                if ($k === $wk) {
                    if ($this->modeHasW($v)) return true;
                    continue;
                }
                $lenS = strlen($suffix);
                $lenK = strlen($k);
                if ($lenK >= $lenS && substr($k, -$lenS) === $suffix) {
                    if ($this->modeHasW($v)) return true;
                }
            }
            // 3) Consider base container (strip trailing .mode/.type) and detect presence of value.w (min/max/step)
            $base = preg_replace('/\.(mode|type)$/i', '', $wk);
            if (is_string($base) && $base !== '') {
                // Probing common wrappers: '', 'property.', 'value.', 'profile.', and indexed forms
                $candidates = [$base];
                foreach (['property.', 'value.', 'profile.'] as $wrap) {
                    $candidates[] = $wrap . $base;
                    for ($i = 0; $i <= 4; $i++) {
                        $candidates[] = $wrap . $i . '.' . $base;
                    }
                    // value.property.N.
                    if ($wrap === 'value.') {
                        for ($i = 0; $i <= 4; $i++) {
                            $candidates[] = 'value.property.' . $i . '.' . $base;
                        }
                    }
                    // profile.value.property.N.
                    if ($wrap === 'profile.') {
                        for ($i = 0; $i <= 4; $i++) {
                            $candidates[] = 'profile.value.property.' . $i . '.' . $base;
                        }
                    }
                }
                // Now search for any path under candidate that contains '.value.w'
                foreach ($this->flatProfile as $k => $v) {
                    foreach ($candidates as $cand) {
                        if (strpos($k, $cand) === 0 && strpos($k, '.value.w') !== false) {
                            return true; // writeable definition exists (min/step/max specifics)
                        }
                    }
                }
            }
        }
        return false;
    }

    private function modeHasW($mode): bool
    {
        if (is_string($mode)) {
            return str_contains(strtolower($mode), 'w');
        }
        if (is_array($mode)) {
            foreach ($mode as $m) { if ($this->modeHasW($m)) return true; }
        }
        return false;
    }

    /** @param array<int, string> $keys */
    private function flatProfileHasAny(array $keys): bool
    {
        foreach ($keys as $k) {
            $k = (string)$k;
            if ($k === '') continue;
            if (array_key_exists($k, $this->flatProfile)) return true;
            foreach ($this->flatProfile as $fk => $_) {
                if (strpos($fk, $k) !== false) return true;
            }
        }
        return false;
    }

    private function flatProfileIsWriteable(string $basePath): bool
    {
        $basePath = (string)$basePath;
        if ($basePath === '') return false;
        // 1) Direct mode flag indicates write (contains 'w')
        $modeKey = $basePath . '.mode';
        if (array_key_exists($modeKey, $this->flatProfile) && $this->modeHasW($this->flatProfile[$modeKey])) {
            return true;
        }
        // 2) Any nested value.w under the base path indicates writeable range
        $prefix = $basePath . '.';
        foreach ($this->flatProfile as $k => $_) {
            if (strpos($k, $prefix) !== 0) continue;
            if (strpos($k, '.value.w') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read value using 'read' section from descriptor.
     * @param array<string, mixed> $cap
     * @param array<string, mixed> $flat
     */
    private function readValue(array $cap, array $flat)
    {
        $read = $cap['read'] ?? null;
        if (!is_array($read)) return null;
        // direct mapped value (support both 'map' and 'valueMap')
        $mapField = $read['valueMap'] ?? $read['map'] ?? null;
        if (isset($read['sources']) && is_array($mapField)) {
            $src = $read['sources'];
            $map = $mapField;
            $ci  = (bool)($read['mapCaseInsensitive'] ?? true);
            if (is_array($src)) {
                foreach ($src as $p) {
                    $v = $this->getFromFlat($flat, (string)$p);
                    if ($v !== null) {
                        $key = (string)$v;
                        if ($ci) {
                            // build uppercase map once
                            $umap = [];
                            foreach ($map as $mk => $mv) { $umap[strtoupper((string)$mk)] = $mv; }
                            $u = strtoupper($key);
                            if (array_key_exists($u, $umap)) return $umap[$u];
                        } else {
                            if (array_key_exists($key, $map)) return $map[$key];
                        }
                        // if not mapped, return raw
                        return $v;
                    }
                }
            }
        }
        // array read: select array element by where and read a path
        if (isset($read['array']) && is_array($read['array'])) {
            $cfg = $read['array'];
            $container = (string)($cfg['container'] ?? '');
            $path = (string)($cfg['path'] ?? '');
            $where = is_array($cfg['where'] ?? null) ? $cfg['where'] : [];
            if ($container !== '' && $path !== '') {
                $flatSrc = $this->flatStatus ?: $this->flatProfile;
                $idx = $this->findArrayIndex($flatSrc, $container, $where);
                if ($idx !== null) {
                    $v = $this->getFromFlat($flatSrc, $container . '.' . $idx . '.' . $path);
                    if ($v !== null) {
                        // Optional enum mapping for array-based read
                        $map = $read['map'] ?? null;
                        if (is_array($map)) {
                            $key = (string)$v;
                            $ci  = (bool)($read['mapCaseInsensitive'] ?? true);
                            if ($ci) {
                                $umap = [];
                                foreach ($map as $mk => $mv) { $umap[strtoupper((string)$mk)] = $mv; }
                                $u = strtoupper($key);
                                if (array_key_exists($u, $umap)) return $umap[$u];
                            } else {
                                if (array_key_exists($key, $map)) return $map[$key];
                            }
                        }
                        // Optional string true/false conversion
                        $trueVals = $read['string_true'] ?? [];
                        $falseVals = $read['string_false'] ?? [];
                        if (!empty($trueVals) || !empty($falseVals)) {
                            $s = strtoupper((string)$v);
                            if (in_array($s, array_map('strtoupper', $trueVals), true)) return true;
                            if (in_array($s, array_map('strtoupper', $falseVals), true)) return false;
                        }
                        return $v;
                    }
                }
            }
        }
        // composite
        if (isset($read['composite']) && is_array($read['composite'])) {
            $comp = $read['composite'];
            $fn = strtolower((string)($comp['combine'] ?? ''));
            $parts = $comp['parts'] ?? [];
            if ($fn === 'hm_to_minutes' && is_array($parts) && count($parts) >= 2) {
                $p0 = (string)($parts[0]['path'] ?? '');
                $p1 = (string)($parts[1]['path'] ?? '');
                $h = $this->getFromFlat($flat, $p0);
                $m = $this->getFromFlat($flat, $p1);
                if ($h !== null || $m !== null) {
                    return (int)((int)($h ?? 0) * 60 + (int)($m ?? 0));
                }
            }
        }
        // sources
        $src = $read['sources'] ?? [];
        if (is_array($src)) {
            foreach ($src as $p) {
                $v = $this->getFromFlat($flat, (string)$p);
                if ($v !== null) {
                    // optional string true/false lists
                    $trueVals = $read['string_true'] ?? [];
                    $falseVals = $read['string_false'] ?? [];
                    if (!empty($trueVals) || !empty($falseVals)) {
                        $s = strtoupper((string)$v);
                        if (in_array($s, array_map('strtoupper', $trueVals), true)) return true;
                        if (in_array($s, array_map('strtoupper', $falseVals), true)) return false;
                    }
                    return $v;
                }
            }
        }
        return null;
    }

    private function getFromFlat(array $flat, string $path)
    {
        return $flat[$path] ?? null;
    }

    private function setValueByType(int $vid, array $cap, $val): void
    {
        $type = strtoupper((string)($cap['type'] ?? 'string'));
        
        // Get current value and compare to avoid unnecessary updates
        if ($type === 'BOOLEAN') {
            $current = @GetValueBoolean($vid);
            $new = (bool)$val;
            if ($current !== $new) {
                @SetValueBoolean($vid, $new);
            }
        } elseif ($type === 'INTEGER') {
            $current = @GetValueInteger($vid);
            $new = (int)$val;
            if ($current !== $new) {
                @SetValueInteger($vid, $new);
            }
        } elseif ($type === 'FLOAT') {
            $current = @GetValueFloat($vid);
            $new = (float)$val;
            // Use epsilon comparison for floats
            if (abs($current - $new) > 0.0001) {
                @SetValueFloat($vid, $new);
            }
        } else {
            $current = @GetValueString($vid);
            $new = (string)$val;
            if ($current !== $new) {
                @SetValueString($vid, $new);
            }
        }
    }

    private function convertValueForType(array $cap, $value)
    {
        $type = strtoupper((string)($cap['type'] ?? 'string'));
        return match ($type) {
            'BOOLEAN' => (bool)$value,
            'INTEGER' => (int)$value,
            'FLOAT'   => (float)$value,
            default   => (string)$value
        };
    }

    private function replaceTemplatePlaceholders(array $tpl, $value): array
    {
        // Replace '@bool', '@int', '@float', '@string' in leaf values
        $out = $tpl;
        $this->walkReplace($out, $value);
        return $out;
    }

    private function walkReplace(&$node, $value): void
    {
        if (is_array($node)) {
            foreach ($node as $k => &$v) {
                $this->walkReplace($v, $value);
            }
            unset($v);
        } else {
            if (is_string($node)) {
                $s = $node;
                if (strpos($s, '@bool') !== false) {
                    $node = ((bool)$value) ? true : false;
                    return;
                }
                if (strpos($s, '@int') !== false) {
                    $node = (int)$value;
                    return;
                }
                if (strpos($s, '@float') !== false) {
                    $node = (float)$value;
                    return;
                }
                if (strpos($s, '@string') !== false) {
                    $node = (string)$value;
                    return;
                }
                if (strpos($s, '@onoff') !== false) {
                    $node = ((bool)$value) ? 'ON' : 'OFF';
                    return;
                }
                if (strpos($s, '@startstop') !== false) {
                    $node = ((bool)$value) ? 'START' : 'STOP';
                    return;
                }
                if (strpos($s, '@power_on_off') !== false) {
                    $node = ((bool)$value) ? 'POWER_ON' : 'POWER_OFF';
                    return;
                }
            }
        }
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

    /** @param array<string, mixed> $arr */
    private function setByPath(array &$arr, string $path, $value): void
    {
        $parts = explode('.', $path);
        $ref = &$arr;
        foreach ($parts as $p) {
            // Use integer array indices for numeric-looking parts to ensure JSON arrays are emitted
            $key = ctype_digit($p) ? (int)$p : $p;
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
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
        $min = null; $max = null; $step = null;
        $suffixes = [
            'min' => '.' . $property . '.value.w.min',
            'max' => '.' . $property . '.value.w.max',
            'step' => '.' . $property . '.value.w.step'
        ];
        foreach ($this->flatProfile as $k => $v) {
            if (strpos($k, $resource) === false) continue;
            // prefer keys under property.* when present
            if (strpos($k, 'property.') === false) continue;
            foreach ($suffixes as $kind => $suf) {
                $lenS = strlen($suf);
                $lenK = strlen($k);
                if ($lenK >= $lenS && substr($k, -$lenS) === $suf) {
                    if ($kind === 'min' && is_numeric($v)) { $min = (float)$v; }
                    if ($kind === 'max' && is_numeric($v)) { $max = (float)$v; }
                    if ($kind === 'step' && is_numeric($v)) { $step = (float)$v; }
                }
            }
            if ($min !== null && $max !== null && $step !== null) {
                break;
            }
        }
        if ($min === null && $max === null && $step === null) return null;
        $out = [];
        if ($min !== null) $out['min'] = $min;
        if ($max !== null) $out['max'] = $max;
        if ($step !== null) $out['step'] = $step;
        return $out;
    }

    /**
     * Find array index inside flattened structure for a container using where conditions.
     * where: [ field => expected ] matches when flat["container.idx.field"] == expected (string compare, case-sensitive).
     */
    private function findArrayIndex(array $flat, string $container, array $where): ?int
    {
        $indices = $this->collectArrayIndices($flat, $container);
        foreach ($indices as $i) {
            $ok = true;
            foreach ($where as $k => $v) {
                $cur = $flat[$container . '.' . $i . '.' . (string)$k] ?? null;
                if ((string)$cur !== (string)$v) { $ok = false; break; }
            }
            if ($ok) return $i;
        }
        return null;
    }

    /** Collect existing numeric indices for keys that start with container."index". */
    private function collectArrayIndices(array $flat, string $container): array
    {
        $set = [];
        $prefix = $container . '.';
        foreach ($flat as $k => $_) {
            if (strpos($k, $prefix) !== 0) continue;
            $rest = substr($k, strlen($prefix));
            $pos = strpos($rest, '.');
            if ($pos === false) continue;
            $idxStr = substr($rest, 0, $pos);
            if (ctype_digit($idxStr)) {
                $set[(int)$idxStr] = true;
            }
        }
        $out = array_keys($set);
        sort($out);
        return $out;
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

    /**
     * Get timer pair values (hour + minute) for timer properties
     * Returns array with both values or null if not a timer property
     * 
     * @param string $ident Current variable ident
     * @param string $property Property name (e.g., "relativeMinuteToStart")
     * @param mixed $value Current value being set
     * @return array<string, mixed>|null
     */
    private function getTimerPairValue(string $ident, string $property, $value): ?array
    {
        // Check if this is a timer hour or minute property
        // Matches: relativeHourToStart, absoluteMinuteToStop, timerHour, timerMinute,
        //          targetHour, targetMinute, sleepTimerRelativeHourToStop, etc.
        if (!preg_match('/(hour|minute).*(to|timer)|^(timer|target|remain)(hour|minute)$/i', $property)) {
            return null;
        }
        
        $isHourProperty = (stripos($property, 'hour') !== false);
        $isMinuteProperty = (stripos($property, 'minute') !== false);
        
        if (!$isHourProperty && !$isMinuteProperty) {
            return null;
        }
        
        // Determine partner ident (handle both _HOUR_ mid-ident and _HOUR at end)
        if ($isHourProperty) {
            $partnerIdent = preg_replace('/_HOUR(_|$)/i', '_MINUTE$1', $ident);
        } else {
            $partnerIdent = preg_replace('/_MINUTE(_|$)/i', '_HOUR$1', $ident);
        }
        
        // Only pair if partner capability exists and is writable
        // Per SDK: AC pairs hour+minute, Washer sends only hour (minute is read-only)
        $partnerCap = $this->caps[$partnerIdent] ?? null;
        if (!is_array($partnerCap)) {
            $this->dbg(sprintf('getTimerPairValue: partner %s not found in caps, skipping pair', $partnerIdent));
            return null;
        }
        $partnerEnableWhen = (string)($partnerCap['action']['enableWhen'] ?? 'never');
        if ($partnerEnableWhen === 'never') {
            $this->dbg(sprintf('getTimerPairValue: partner %s is read-only (enableWhen=never), skipping pair', $partnerIdent));
            return null;
        }
        
        // Find the partner property name and build paired payload
        if ($isHourProperty) {
            $hourProperty = $property;
            $minuteProperty = preg_replace('/[Hh]our/', 'Minute', $property);
            if ($minuteProperty === $property) {
                $minuteProperty = preg_replace('/HOUR/', 'MINUTE', $property);
            }
            $hourValue = (int)$value;
            $minuteValue = $this->getVariableValue($partnerIdent);
        } else {
            $minuteProperty = $property;
            $hourProperty = preg_replace('/[Mm]inute/', 'Hour', $property);
            if ($hourProperty === $property) {
                $hourProperty = preg_replace('/MINUTE/', 'HOUR', $property);
            }
            $minuteValue = (int)$value;
            $hourValue = $this->getVariableValue($partnerIdent);
        }
        
        $this->dbg(sprintf('getTimerPairValue: pairing %s=%d + %s=%d', $hourProperty, $hourValue, $minuteProperty, $minuteValue));
        
        return [
            $hourProperty => $hourValue,
            $minuteProperty => $minuteValue
        ];
    }
    
    /**
     * Get value of a variable by ident
     * 
     * @param string $ident
     * @return int
     */
    private function getVariableValue(string $ident): int
    {
        // Find variable by ident in this instance
        $varId = @IPS_GetObjectIDByIdent($ident, $this->instanceId);
        if ($varId === false) {
            return 0;
        }
        return (int)GetValue($varId);
    }

    /**
     * Apply coSendFromStatus: read partner variable current values and merge into payload.
     * Per SDK: AC two-set temperature sends both heat+cool, Cooktop sends power+timer together.
     *
     * Config format: "coSendFromStatus": [{"ident": "...", "resource": "...", "property": "..."}]
     *
     * @param array<string, mixed> $cfg  attribute write config
     * @param array<string, mixed> &$payload  payload to modify (by reference)
     */
    private function applyCoSendFromStatus(array $cfg, array &$payload): void
    {
        $companions = $cfg['coSendFromStatus'] ?? null;
        if (!is_array($companions) || empty($companions)) {
            return;
        }
        foreach ($companions as $companion) {
            if (!is_array($companion)) continue;
            $pIdent = (string)($companion['ident'] ?? '');
            $pResource = (string)($companion['resource'] ?? '');
            $pProperty = (string)($companion['property'] ?? '');
            if ($pIdent === '' || $pResource === '' || $pProperty === '') continue;

            $pValue = $this->getVariableValueMixed($pIdent);
            if (!isset($payload[$pResource]) || !is_array($payload[$pResource])) {
                $payload[$pResource] = [];
            }
            // Only add if not already set (don't overwrite the primary value)
            if (!array_key_exists($pProperty, $payload[$pResource])) {
                $payload[$pResource][$pProperty] = $pValue;
                $this->dbg(sprintf('coSendFromStatus: added %s.%s=%s from %s', $pResource, $pProperty, json_encode($pValue), $pIdent));
            }
        }
    }

    /**
     * Apply coSendConst: inject constant companion values into payload.
     * Per SDK: Oven always sends ovenOperationMode="START" alongside cook/timer/temp writes.
     *
     * Config format: "coSendConst": [{"resource": "...", "property": "...", "value": "..."}]
     *
     * @param array<string, mixed> $cfg  attribute write config
     * @param array<string, mixed> &$payload  payload to modify (by reference)
     */
    private function applyCoSendConst(array $cfg, array &$payload): void
    {
        $constants = $cfg['coSendConst'] ?? null;
        if (!is_array($constants) || empty($constants)) {
            return;
        }
        foreach ($constants as $entry) {
            if (!is_array($entry)) continue;
            $cResource = (string)($entry['resource'] ?? '');
            $cProperty = (string)($entry['property'] ?? '');
            $cValue = $entry['value'] ?? null;
            if ($cResource === '' || $cProperty === '' || $cValue === null) continue;

            if (!isset($payload[$cResource]) || !is_array($payload[$cResource])) {
                $payload[$cResource] = [];
            }
            if (!array_key_exists($cProperty, $payload[$cResource])) {
                $payload[$cResource][$cProperty] = $cValue;
                $this->dbg(sprintf('coSendConst: added %s.%s=%s', $cResource, $cProperty, json_encode($cValue)));
            }
        }
    }

    /**
     * Get variable value as mixed type (int, float, string, or bool)
     * @param string $ident
     * @return mixed
     */
    private function getVariableValueMixed(string $ident)
    {
        $varId = @IPS_GetObjectIDByIdent($ident, $this->instanceId);
        if ($varId === false) {
            return 0;
        }
        return GetValue($varId);
    }

}
