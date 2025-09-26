<?php

declare(strict_types=1);

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

    /** @var array<string, mixed>|null */
    private static ?array $catalog = null;

    public function __construct(int $instanceId, string $baseDir)
    {
        $this->instanceId = $instanceId;
        $this->baseDir = rtrim($baseDir, '/');
    }

    /** @param array<string, mixed> $cap */
    private function capHasWriteDefinition(array $cap): bool
    {
        $w = $cap['write'] ?? null;
        if (!is_array($w)) return false;
        foreach (['enumMap','template','composite','arrayTemplate'] as $k) {
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
        $files = $this->resolveCapabilityFiles($deviceType, $profile);
        $this->caps = [];
        @IPS_LogMessage('CapabilityEngine', sprintf('Loading %d capability files: %s', count($files), implode(', ', $files)));
        foreach ($files as $f) {
            if (@is_file($f)) {
                $json = @file_get_contents($f);
                if (is_string($json) && $json !== '') {
                    $dec = @json_decode($json, true);
                    if (is_array($dec) && isset($dec['capabilities']) && is_array($dec['capabilities'])) {
                        $capCount = count($dec['capabilities']);
                        @IPS_LogMessage('CapabilityEngine', sprintf('Loaded %d capabilities from %s', $capCount, basename($f)));
                        foreach ($dec['capabilities'] as $cap) {
                            if (is_array($cap) && isset($cap['ident'])) {
                                $this->caps[$cap['ident']] = $cap;
                            }
                        }
                    } else {
                        @IPS_LogMessage('CapabilityEngine', sprintf('Invalid capability file format: %s', basename($f)));
                    }
                } else {
                    @IPS_LogMessage('CapabilityEngine', sprintf('Could not read capability file: %s', basename($f)));
                }
            } else {
                @IPS_LogMessage('CapabilityEngine', sprintf('Capability file not found: %s', $f));
            }
        }
        $this->flatProfile = $this->flatten($profile);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<int, string>
     */
    private function resolveCapabilityFiles(string $deviceType, array $profile): array
    {
        $catalog = $this->loadCatalog();
        $type = strtolower((string)$deviceType);
        $profileText = strtolower(json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        $files = [];

        // 1) Strict deviceType-only matching: if any rules match the deviceType string alone, use ONLY those files
        $strictFiles = [];
        foreach ($catalog['rules'] as $rule) {
            if ($this->catalogRuleMatchesDeviceOnly($rule, $type)) {
                foreach ($rule['files'] as $file) {
                    $strictFiles[] = $this->baseDir . '/capabilities/' . $file;
                }
            }
        }
        if (!empty($strictFiles)) {
            return array_values(array_unique($strictFiles));
        }

        // 2) Fallback: broader match using deviceType + profile text
        foreach ($catalog['rules'] as $rule) {
            if ($this->catalogRuleMatches($rule, $type, $profileText)) {
                foreach ($rule['files'] as $file) {
                    $files[] = $this->baseDir . '/capabilities/' . $file;
                }
            }
        }
        if (empty($files)) {
            foreach ($catalog['fallback'] as $fallback) {
                $files[] = $this->baseDir . '/capabilities/' . $fallback;
            }
        }

        $files = array_values(array_unique($files));
        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCatalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $file = $this->baseDir . '/capabilities/catalog.json';
        $default = ['rules' => [], 'fallback' => ['ac.json']];
        if (!@is_file($file)) {
            self::$catalog = $default;
            return self::$catalog;
        }
        $json = @file_get_contents($file);
        if (!is_string($json) || $json === '') {
            self::$catalog = $default;
            return self::$catalog;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['rules']) || !is_array($data['rules'])) {
            self::$catalog = $default;
            return self::$catalog;
        }
        foreach ($data['rules'] as &$rule) {
            if (!isset($rule['files']) || !is_array($rule['files'])) {
                $rule['files'] = [];
            }
        }
        self::$catalog = $data;
        return self::$catalog;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function catalogRuleMatches(array $rule, string $deviceType, string $profileText): bool
    {
        $match = $rule['match'] ?? [];
        $exclude = $rule['exclude'] ?? [];
        if (!$this->matchesCondition($match, $deviceType, $profileText)) {
            return false;
        }
        if (!empty($exclude) && $this->matchesCondition($exclude, $deviceType, $profileText)) {
            return false;
        }
        return true;
    }

    /**
     * Like catalogRuleMatches but only considers the deviceType (ignores profileText).
     */
    private function catalogRuleMatchesDeviceOnly(array $rule, string $deviceType): bool
    {
        $match = $rule['match'] ?? [];
        $exclude = $rule['exclude'] ?? [];
        if (!$this->matchesCondition($match, $deviceType, '')) {
            return false;
        }
        if (!empty($exclude) && $this->matchesCondition($exclude, $deviceType, '')) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function matchesCondition(array $condition, string $deviceType, string $profileText): bool
    {
        if (empty($condition)) {
            return true;
        }
        $haystacks = [$deviceType];
        if ($profileText !== '') {
            $haystacks[] = $profileText;
        }

        if (isset($condition['any']) && is_array($condition['any'])) {
            $found = false;
            foreach ($condition['any'] as $needle) {
                $needle = strtolower((string)$needle);
                if ($needle === '') {
                    continue;
                }
                foreach ($haystacks as $haystack) {
                    if (strpos($haystack, $needle) !== false) {
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) {
                return false;
            }
        }

        if (isset($condition['all']) && is_array($condition['all'])) {
            foreach ($condition['all'] as $needle) {
                $needle = strtolower((string)$needle);
                if ($needle === '') {
                    continue;
                }
                $matches = false;
                foreach ($haystacks as $haystack) {
                    if (strpos($haystack, $needle) !== false) {
                        $matches = true;
                        break;
                    }
                }
                if (!$matches) {
                    return false;
                }
            }
        }

        if (isset($condition['regex']) && is_array($condition['regex'])) {
            $regexMatch = false;
            foreach ($condition['regex'] as $pattern) {
                $pattern = (string)$pattern;
                if ($pattern === '') {
                    continue;
                }
                $pattern = '/' . str_replace('/', '\/', $pattern) . '/i';
                foreach ($haystacks as $haystack) {
                    if (@preg_match($pattern, $haystack)) {
                        $regexMatch = true;
                        break 2;
                    }
                }
            }
            if (!$regexMatch) {
                return false;
            }
        }

        return true;
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
     * Ensure variables exist and attach actions according to descriptors.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $status
     * @param string $deviceType
     */
    public function ensureVariables(array $profile, ?array $status, string $deviceType): void
    {
        //@IPS_LogMessage('CapabilityEngine', sprintf('ensureVariables called with deviceType: %s', $deviceType));
        //@IPS_LogMessage('CapabilityEngine', sprintf('DEBUG: instanceId=%d, profile keys=%s', $this->instanceId, implode(',', array_keys($profile))));
        $this->loadCapabilities($deviceType, $profile);
        //@IPS_LogMessage('CapabilityEngine', sprintf('Loaded %d capabilities', count($this->caps)));
        
        $flatStatus = is_array($status) ? $this->flatten($status) : [];
        $this->flatStatus = $flatStatus;
        
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            
            //@IPS_LogMessage('CapabilityEngine', sprintf('Processing capability: %s', $ident));
            
            $should = $this->shouldCreate($cap, $this->flatProfile, $flatStatus);
            $vid = $this->getVarId($ident);
            
            //@IPS_LogMessage('CapabilityEngine', sprintf('Capability %s: should=%s, vid=%d', $ident, $should ? 'true' : 'false', $vid));
            
            // Create variable if required or if it already exists proceed to action enabling
            if ($vid === 0 && !$should) {
                // Nothing to do for variables that should not exist and do not exist
                //@IPS_LogMessage('CapabilityEngine', sprintf('Skipping %s: should not exist and does not exist', $ident));
                continue;
            }
            // Ensure variable exists
            if ($vid === 0) {
                $type = strtoupper((string)($cap['type'] ?? 'string'));
                $ipsType = match ($type) {
                    'BOOLEAN' => VARIABLETYPE_BOOLEAN,
                    'INTEGER' => VARIABLETYPE_INTEGER,
                    'FLOAT'   => VARIABLETYPE_FLOAT,
                    default   => VARIABLETYPE_STRING
                };
                $name = (string)($cap['name'] ?? $ident);
                $vid = IPS_CreateVariable($ipsType);
                IPS_SetParent($vid, $this->instanceId);
                IPS_SetIdent($vid, $ident);
                IPS_SetName($vid, $name);
                //@IPS_LogMessage('CapabilityEngine', sprintf('Created variable %s with VID: %d', $ident, $vid));
            }
            // Hidden
            $hidden = (bool)($cap['visibility']['hidden'] ?? false);
            if ($hidden) { @IPS_SetHidden($vid, true); }
            
            // EnableAction: always, profile writeable, or fallback when a write mapping exists
            $enableWhen = strtolower((string)($cap['action']['enableWhen'] ?? ''));
            //@IPS_LogMessage('CapabilityEngine', sprintf('Capability %s: enableWhen=%s', $ident, $enableWhen));
            
            if ($enableWhen === 'always') {
                //@IPS_LogMessage('CapabilityEngine', sprintf('Enabling action for %s (always)', $ident));
                $this->enableAction($ident);
            } elseif ($enableWhen === 'profilewriteableany') {
                $writeKeys = $cap['action']['writeableKeys'] ?? [];
                $hasWrite = is_array($writeKeys) && $this->profileHasWriteAny($writeKeys);
                //@IPS_LogMessage('CapabilityEngine', sprintf('Capability %s: hasWrite=%s, writeKeys=%s', $ident, $hasWrite ? 'true' : 'false', json_encode($writeKeys)));
                
                if ($hasWrite) {
                    //@IPS_LogMessage('CapabilityEngine', sprintf('Enabling action for %s (profile writeable)', $ident));
                    $this->enableAction($ident);
                } else {
                    // Fallback: if the capability defines a write mapping, still enable action
                    if ($this->capHasWriteDefinition($cap)) {
                        //@IPS_LogMessage('CapabilityEngine', sprintf('Enabling action for %s (fallback - has write definition)', $ident));
                        $this->enableAction($ident);
                    } else {
                        //@IPS_LogMessage('CapabilityEngine', sprintf('NOT enabling action for %s (no write definition)', $ident));
                    }
                }
            } else {
                @IPS_LogMessage('CapabilityEngine', sprintf('NOT enabling action for %s (enableWhen=%s)', $ident, $enableWhen));
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
     *
     * @param string $deviceType
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $status
     * @return array<string, array<string, mixed>> keyed by ident
     */
    public function buildPlan(string $deviceType, array $profile, ?array $status): array
    {
        $this->loadCapabilities($deviceType, $profile);
        $this->flatProfile = $this->flatten($profile);
        $this->flatStatus = is_array($status) ? $this->flatten($status) : [];

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
                'enableAction' => $shouldCreate && $this->shouldEnableAction($cap)
            ];

            if ($shouldCreate) {
                $value = $this->readValue($cap, $this->flatStatus);
                if ($value !== null) {
                    $entry['initialValue'] = $value;
                }
            }

            $plan[$ident] = $entry;
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
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            $vid = $this->getVarId($ident);
            if ($vid === 0) continue;
            // Read value
            $val = $this->readValue($cap, $flat);
            if ($val !== null) {
                $this->setValueByType($vid, $cap, $val);
            }
            // NOTE: Do not re-enable actions on every status update to avoid noisy logs and redundant calls.
            // Action enabling is performed during variable creation in the main module (SetupDeviceVariables).
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
        if (!is_array($cap)) return null;
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
            $key = (string)$value;
            if (array_key_exists($key, $map) && is_array($map[$key])) {
                $out = [];
                foreach ($map[$key] as $path => $v) {
                    $this->setByPath($out, (string)$path, $v);
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

        // firstOf: pick the first matching write option based on profile keys
        if (isset($cap['write']['firstOf']) && is_array($cap['write']['firstOf'])) {
            foreach ($cap['write']['firstOf'] as $opt) {
                if (!is_array($opt)) continue;
                // Prefer explicit writeable condition
                $writeKeys = $opt['profileWriteableAny'] ?? [];
                if (is_array($writeKeys) && !empty($writeKeys)) {
                    $ok = false;
                    foreach ($writeKeys as $wk) {
                        if ($this->flatProfileIsWriteable((string)$wk)) { $ok = true; break; }
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
                // Support template within firstOf
                if (isset($opt['template']) && is_array($opt['template'])) {
                    $converted = $this->convertValueForType($cap, $value);
                    $out = $this->replaceTemplatePlaceholders($opt['template'], $converted);
                    //@IPS_LogMessage('CapabilityEngine', sprintf('firstOf: using template for ident=%s with keys=%s', (string)($cap['ident'] ?? ''), implode(',', array_keys($opt['template']))));
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
                        //@IPS_LogMessage('CapabilityEngine', sprintf('firstOf: using enumMap for ident=%s on paths=%s', (string)($cap['ident'] ?? ''), implode(',', array_keys($map[$key]))));
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
                        //@IPS_LogMessage('CapabilityEngine', sprintf('firstOf: using composite(minutes_to_hm) for ident=%s targets=%s,%s', (string)($cap['ident'] ?? ''), $t0, $t1));
                        return $out;
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
        if ($when === 'profilehasany') {
            foreach ($keys as $k) { if (array_key_exists($k, $flatProfile)) return true; }
            // Substring match (handles array prefixes like property.0.*)
            foreach ($keys as $k) {
                foreach ($flatProfile as $fk => $_) {
                    if (strpos($fk, $k) !== false) return true;
                }
            }
            // Consider presence of common schema nodes even if not writeable
            foreach ($keys as $b) {
                foreach ([$b . '.mode', $b . '.type', $b . '.value', $b . '.value.r', $b . '.value.w'] as $probe) {
                    if (array_key_exists($probe, $flatProfile)) return true;
                    foreach ($flatProfile as $fk => $_) {
                        if (strpos($fk, $probe) !== false) return true;
                    }
                }
            }
            // As a last resort, treat writeable mode as present
            foreach ($keys as $b) {
                if ($this->profileHasWriteAny([$b . '.mode'])) return true;
            }
            return false;
        }
        if ($when === 'statushasany') {
            foreach ($keys as $k) { if (array_key_exists($k, $flatStatus)) return true; }
            foreach ($keys as $k) {
                foreach ($flatStatus as $fk => $_) {
                    if (strpos($fk, $k) !== false) return true;
                }
            }
            return false;
        }
        return false;
    }

    private function enableAction(string $ident): void
    {
        @IPS_LogMessage('CapabilityEngine', sprintf('enableAction called for ident: %s, instanceId: %d - SKIPPING (will be handled by main module)', $ident, $this->instanceId));

        // NOTE: Action enabling is now handled directly in the main module's SetupDeviceVariables method
        // using $this->EnableAction() which is the correct Symcon approach
        // This method is kept for compatibility but doesn't do the actual enabling anymore
    }

    /** @param array<string, mixed> $cap */
    private function shouldEnableAction(array $cap): bool
    {
        $enableWhen = strtolower((string)($cap['action']['enableWhen'] ?? ''));
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
        // direct mapped value
        if (isset($read['sources']) && isset($read['map']) && is_array($read['map'])) {
            $src = $read['sources'];
            $map = $read['map'];
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
                    if ($v !== null) return $v;
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
        if ($type === 'BOOLEAN') {
            @SetValueBoolean($vid, (bool)$val);
        } elseif ($type === 'INTEGER') {
            @SetValueInteger($vid, (int)$val);
        } elseif ($type === 'FLOAT') {
            @SetValueFloat($vid, (float)$val);
        } else {
            @SetValueString($vid, (string)$val);
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
            if (!isset($ref[$p]) || !is_array($ref[$p])) {
                $ref[$p] = [];
            }
            $ref = &$ref[$p];
        }
        $ref = $value;
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
}
