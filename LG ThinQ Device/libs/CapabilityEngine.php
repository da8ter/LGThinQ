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
        $dt = strtolower($deviceType);
        @IPS_LogMessage('CapabilityEngine', sprintf('loadCapabilities: deviceType="%s", dt="%s"', $deviceType, $dt));
        $files = [];
        if ($dt === '' || str_contains($dt, 'air') || str_contains($dt, 'condition') || str_contains($dt, 'hvac') || str_contains($dt, 'ac')) {
            $files[] = $this->baseDir . '/capabilities/ac.json';
            @IPS_LogMessage('CapabilityEngine', 'Added ac.json capability file');
        }
        if (str_contains($dt, 'fridge') || str_contains($dt, 'refrigerator') || str_contains($dt, 'freezer')) {
            $files[] = $this->baseDir . '/capabilities/fridge.json';
        }
        if (str_contains($dt, 'wash') || str_contains($dt, 'laundry') || str_contains($dt, 'dish')) {
            $files[] = $this->baseDir . '/capabilities/washer.json';
        }
        // Washtower washer detection (load after generic washer to override where needed)
        if (str_contains($dt, 'washtower') || str_contains($dt, 'wash_tower') || (str_contains($dt, 'tower') && str_contains($dt, 'wash'))) {
            $files[] = $this->baseDir . '/capabilities/washtower_washer.json';
        }
        // WashCombo detection (load after generic washer to override where needed)
        if (str_contains($dt, 'washcombo') || str_contains($dt, 'wash_combo') || (str_contains($dt, 'combo') && (str_contains($dt, 'wash') || str_contains($dt, 'dry')))) {
            $files[] = $this->baseDir . '/capabilities/washcombo.json';
        }
        // Mini WashCombo detection (load after washcombo to override where needed)
        if (str_contains($dt, 'mini_washcombo') || str_contains($dt, 'mini washcombo') || str_contains($dt, 'mini_wash_combo') || str_contains($dt, 'miniwashcombo')) {
            $files[] = $this->baseDir . '/capabilities/mini_washcombo.json';
        }
        // Kimchi refrigerator detection (additional to generic fridge)
        if (str_contains($dt, 'kimchi')) {
            $files[] = $this->baseDir . '/capabilities/kimchi_refrigerator.json';
        }
        // Dishwasher detection (load after washer to override common idents where needed)
        if (str_contains($dt, 'dish') || str_contains($dt, 'dishwasher') || str_contains($dt, 'dish_washer')) {
            $files[] = $this->baseDir . '/capabilities/dish_washer.json';
        }
        // Humidifier detection (avoid collision with dehumidifier)
        $isHumidifier = str_contains($dt, 'humidifier') || (str_contains($dt, 'humid') && !str_contains($dt, 'dehumid'));
        if ($isHumidifier) {
            $files[] = $this->baseDir . '/capabilities/humidifier.json';
        }
        // Dehumidifier detection (avoid collision with dryer)
        if (str_contains($dt, 'dehumid') || str_contains($dt, 'dehum') || (str_contains($dt, 'dry') && str_contains($dt, 'humid'))) {
            $files[] = $this->baseDir . '/capabilities/dehumidifier.json';
        }
        // Dryer detection
        if (str_contains($dt, 'dry') || str_contains($dt, 'dryer')) {
            $files[] = $this->baseDir . '/capabilities/dryer.json';
        }
        // Washtower dryer detection (load after generic dryer to override where needed)
        if (str_contains($dt, 'washtower') || str_contains($dt, 'wash_tower') || (str_contains($dt, 'tower') && (str_contains($dt, 'dry') || str_contains($dt, 'dryer')))) {
            $files[] = $this->baseDir . '/capabilities/washtower_dryer.json';
        }
        // Purifier detection with disambiguation
        $isWaterPurifier = (str_contains($dt, 'water') && str_contains($dt, 'purifier'))
            || str_contains($dt, 'water_purifier')
            || str_contains($dt, 'waterpurifier');
        if ($isWaterPurifier) {
            $files[] = $this->baseDir . '/capabilities/water_purifier.json';
        }
        $isAirPurifier = (str_contains($dt, 'purifier') || str_contains($dt, 'air_purifier') || str_contains($dt, 'airpurifier') || str_contains($dt, 'puri')) && !$isWaterPurifier;
        if ($isAirPurifier) {
            $files[] = $this->baseDir . '/capabilities/air_purifier.json';
            // Air purifier fan variant (load after standard purifier to override where needed)
            if (str_contains($dt, 'fan') || str_contains($dt, 'air_purifier_fan') || str_contains($dt, 'purifier_fan')) {
                $files[] = $this->baseDir . '/capabilities/air_purifier_fan.json';
            }
        }
        // Stick cleaner detection (before robot to avoid overlap)
        $isStickCleaner = str_contains($dt, 'stick') || str_contains($dt, 'cordless') || str_contains($dt, 'handstick') || str_contains($dt, 'stick_cleaner');
        if ($isStickCleaner) {
            $files[] = $this->baseDir . '/capabilities/stick_cleaner.json';
        }
        // Robot cleaner detection (exclude stick cleaner)
        if ((str_contains($dt, 'robot') || str_contains($dt, 'vacuum') || (str_contains($dt, 'cleaner') && !$isStickCleaner)) && !$isStickCleaner) {
            $files[] = $this->baseDir . '/capabilities/robot_cleaner.json';
        }
        // Oven / Range detection
        if (str_contains($dt, 'oven') || str_contains($dt, 'range') || str_contains($dt, 'cook')) {
            $files[] = $this->baseDir . '/capabilities/oven.json';
        }
        // Microwave oven detection
        if (str_contains($dt, 'microwave') || str_contains($dt, 'micro_wave')) {
            $files[] = $this->baseDir . '/capabilities/microwave_oven.json';
        }
        // Cooktop / Hob detection
        if (str_contains($dt, 'cooktop') || str_contains($dt, 'cook_top') || str_contains($dt, 'hob') || str_contains($dt, 'stove')) {
            $files[] = $this->baseDir . '/capabilities/cooktop.json';
        }
        // Hood / Range Hood detection
        if (str_contains($dt, 'hood') || str_contains($dt, 'range_hood') || str_contains($dt, 'rangehood') || str_contains($dt, 'cooker_hood') || str_contains($dt, 'extractor')) {
            $files[] = $this->baseDir . '/capabilities/hood.json';
        }
        // Ventilator (stand/tower fan) detection
        if (str_contains($dt, 'ventilator') || str_contains($dt, 'stand_fan') || str_contains($dt, 'standfan') || str_contains($dt, 'tower_fan') || str_contains($dt, 'towerfan')) {
            $files[] = $this->baseDir . '/capabilities/ventilator.json';
        }
        // Styler detection
        if (str_contains($dt, 'styler') || str_contains($dt, 'steam') || str_contains($dt, 'closet') || str_contains($dt, 'clothing')) {
            $files[] = $this->baseDir . '/capabilities/styler.json';
        }
        // Ceiling fan detection
        if (str_contains($dt, 'ceiling') || str_contains($dt, 'ceiling_fan') || str_contains($dt, 'ceilingfan')) {
            $files[] = $this->baseDir . '/capabilities/ceiling_fan.json';
        }
        // Wine cellar / wine cooler detection
        if (str_contains($dt, 'wine') || str_contains($dt, 'cellar') || str_contains($dt, 'wine_cellar') || str_contains($dt, 'winecooler') || str_contains($dt, 'wine_cooler')) {
            $files[] = $this->baseDir . '/capabilities/wine_cellar.json';
        }
        // HomeBrew / Beer maker detection
        if (str_contains($dt, 'homebrew') || str_contains($dt, 'home_brew') || str_contains($dt, 'beer')) {
            $files[] = $this->baseDir . '/capabilities/home_brew.json';
        }
        // Plant cultivator detection
        if (str_contains($dt, 'plant') || str_contains($dt, 'cultivator') || str_contains($dt, 'garden') || str_contains($dt, 'grow')) {
            $files[] = $this->baseDir . '/capabilities/plant_cultivator.json';
        }
        // System boiler detection
        if (str_contains($dt, 'boiler') || str_contains($dt, 'system_boiler') || (str_contains($dt, 'system') && str_contains($dt, 'boiler'))) {
            $files[] = $this->baseDir . '/capabilities/system_boiler.json';
        }
        // Water heater detection
        if (str_contains($dt, 'water_heater') || str_contains($dt, 'water heater') || (str_contains($dt, 'water') && str_contains($dt, 'heater'))) {
            $files[] = $this->baseDir . '/capabilities/water_heater.json';
        }
        // Fallback: if nothing matched, try AC basic
        if (empty($files)) {
            $files[] = $this->baseDir . '/capabilities/ac.json';
        }

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
        @IPS_LogMessage('CapabilityEngine', sprintf('ensureVariables called with deviceType: %s', $deviceType));
        @IPS_LogMessage('CapabilityEngine', sprintf('DEBUG: instanceId=%d, profile keys=%s', $this->instanceId, implode(',', array_keys($profile))));
        $this->loadCapabilities($deviceType, $profile);
        @IPS_LogMessage('CapabilityEngine', sprintf('Loaded %d capabilities', count($this->caps)));
        
        $flatStatus = is_array($status) ? $this->flatten($status) : [];
        $this->flatStatus = $flatStatus;
        
        foreach ($this->caps as $cap) {
            $ident = (string)($cap['ident'] ?? '');
            if ($ident === '') continue;
            
            @IPS_LogMessage('CapabilityEngine', sprintf('Processing capability: %s', $ident));
            
            $should = $this->shouldCreate($cap, $this->flatProfile, $flatStatus);
            $vid = $this->getVarId($ident);
            
            @IPS_LogMessage('CapabilityEngine', sprintf('Capability %s: should=%s, vid=%d', $ident, $should ? 'true' : 'false', $vid));
            
            // Create variable if required or if it already exists proceed to action enabling
            if ($vid === 0 && !$should) {
                // Nothing to do for variables that should not exist and do not exist
                @IPS_LogMessage('CapabilityEngine', sprintf('Skipping %s: should not exist and does not exist', $ident));
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
                @IPS_LogMessage('CapabilityEngine', sprintf('Created variable %s with VID: %d', $ident, $vid));
            }
            // Hidden
            $hidden = (bool)($cap['visibility']['hidden'] ?? false);
            if ($hidden) { @IPS_SetHidden($vid, true); }
            
            // EnableAction: always, profile writeable, or fallback when a write mapping exists
            $enableWhen = strtolower((string)($cap['action']['enableWhen'] ?? ''));
            @IPS_LogMessage('CapabilityEngine', sprintf('Capability %s: enableWhen=%s', $ident, $enableWhen));
            
            if ($enableWhen === 'always') {
                @IPS_LogMessage('CapabilityEngine', sprintf('Enabling action for %s (always)', $ident));
                $this->enableAction($ident);
            } elseif ($enableWhen === 'profilewriteableany') {
                $writeKeys = $cap['action']['writeableKeys'] ?? [];
                $hasWrite = is_array($writeKeys) && $this->profileHasWriteAny($writeKeys);
                @IPS_LogMessage('CapabilityEngine', sprintf('Capability %s: hasWrite=%s, writeKeys=%s', $ident, $hasWrite ? 'true' : 'false', json_encode($writeKeys)));
                
                if ($hasWrite) {
                    @IPS_LogMessage('CapabilityEngine', sprintf('Enabling action for %s (profile writeable)', $ident));
                    $this->enableAction($ident);
                } else {
                    // Fallback: if the capability defines a write mapping, still enable action
                    if ($this->capHasWriteDefinition($cap)) {
                        @IPS_LogMessage('CapabilityEngine', sprintf('Enabling action for %s (fallback - has write definition)', $ident));
                        $this->enableAction($ident);
                    } else {
                        @IPS_LogMessage('CapabilityEngine', sprintf('NOT enabling action for %s (no write definition)', $ident));
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
            // Reassert action on status
            $reassert = $cap['action']['reassertOn'] ?? [];
            if (is_array($reassert) && in_array('status', array_map('strtolower', $reassert), true)) {
                $this->enableAction($ident);
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
