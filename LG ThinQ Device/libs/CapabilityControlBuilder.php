<?php

declare(strict_types=1);

/**
 * CapabilityControlBuilder
 *
 * Extracted from CapabilityEngine: control payload building for write operations.
 * The plan builder produces two write kinds, enumMap and attribute; buildControlPayload
 * handles exactly these.
 */
class CapabilityControlBuilder
{
    /** @var callable|null */
    private $debugCallback;

    public function __construct(
        private array $caps,
        private array $flatProfile,
        private array $flatStatus,
        private int $instanceId,
        ?callable $debugCallback,
        private CapabilityVarManager $varManager,
        private array $profile = []
    ) {
        $this->debugCallback = $debugCallback;
    }

    private function dbg(string $msg): void
    {
        if ($this->debugCallback) {
            ($this->debugCallback)($msg);
        }
    }

    /**
     * Build a control payload for writing a variable value to the device.
     * Returns a nested array suitable for JSON-encoding and sending to the LG API.
     * Returns null if no write config found or the value is not applicable.
     *
     * @param string $ident   Variable ident
     * @param mixed  $value   New value (bool, int, float, or string)
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
        // Enum map (map incoming value to a set of target paths/values)
        if (isset($cap['write']['enumMap']) && is_array($cap['write']['enumMap'])) {
            $map = $cap['write']['enumMap'];
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
                $enumLocWrap = $cap['write']['enumMapLocationWrap'] ?? null;
                if (is_string($enumLocWrap) && $enumLocWrap !== '') {
                    $out['location'] = ['locationName' => $enumLocWrap];
                }
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
        // attribute: generic builder
        if (isset($cap['write']['attribute']) && is_array($cap['write']['attribute'])) {
            $cfg = $cap['write']['attribute'];
            $resource = (string)($cfg['resource'] ?? '');
            $property = (string)($cfg['property'] ?? '');
            $extras = is_array($cfg['extras'] ?? null) ? $cfg['extras'] : [];
            if ($resource !== '' && $property !== '') {
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
                if (!empty($cfg['clampFromProfile'])) {
                    $rng = $this->rangeFor($resource, $property, $extras, $cfg['locationWrap'] ?? null);
                    if (is_array($rng)) {
                        $v = $value;
                        if (isset($rng['min']) && is_numeric($rng['min'])) { $v = max((float)$rng['min'], (float)$v); }
                        if (isset($rng['max']) && is_numeric($rng['max'])) { $v = min((float)$rng['max'], (float)$v); }
                        $value = $v;
                    }
                }
                $converted = $this->varManager->convertValueForType($cap, $value);
                $resourcePayload = $extras + [$property => $converted];
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
        }
        return null;
    }

    /**
     * min/max/step of the addressed element (its selector in extras, e.g. locationName FREEZER) or
     * zone (locationWrap); the old scan over the flat profile only when the profile has no such element.
     *
     * @param array<string, mixed> $extras
     */
    private function rangeFor(string $resource, string $property, array $extras, mixed $zone): ?array
    {
        $selector = array_intersect_key($extras, array_flip(ThinQShape::SELECTORS));
        return ThinQShape::range($this->profile, $resource, $property, $selector, is_string($zone) && $zone !== '' ? $zone : null)
            ?? $this->varManager->findRangeFromProfile($resource, $property);
    }

    /** @param array<string, mixed> $arr */
    private function setByPath(array &$arr, string $path, $value): void
    {
        $parts = explode('.', $path);
        $ref = &$arr;
        foreach ($parts as $p) {
            $key = ctype_digit($p) ? (int)$p : $p;
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
    }

    private function getTimerPairValue(string $ident, string $property, $value): ?array
    {
        if (!preg_match('/(hour|minute).*(to|timer)|^(timer|target|remain)(hour|minute)$/i', $property)) {
            return null;
        }
        $isHourProperty = (stripos($property, 'hour') !== false);
        $isMinuteProperty = (stripos($property, 'minute') !== false);
        if (!$isHourProperty && !$isMinuteProperty) {
            return null;
        }
        if ($isHourProperty) {
            $partnerIdent = preg_replace('/_HOUR(_|$)/i', '_MINUTE$1', $ident);
        } else {
            $partnerIdent = preg_replace('/_MINUTE(_|$)/i', '_HOUR$1', $ident);
        }
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

    private function getVariableValue(string $ident): int
    {
        $varId = @IPS_GetObjectIDByIdent($ident, $this->instanceId);
        if ($varId === false) {
            return 0;
        }
        return (int)GetValue($varId);
    }

    private function getVariableValueMixed(string $ident)
    {
        $varId = @IPS_GetObjectIDByIdent($ident, $this->instanceId);
        if ($varId === false) {
            return 0;
        }
        return GetValue($varId);
    }

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
            if (!array_key_exists($pProperty, $payload[$pResource])) {
                $payload[$pResource][$pProperty] = $pValue;
                $this->dbg(sprintf('coSendFromStatus: added %s.%s=%s from %s', $pResource, $pProperty, json_encode($pValue), $pIdent));
            }
        }
    }

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

    // === Profile analysis helpers (also used by coordinator via delegation) ===

    public function profileHasWriteAny(array $writeableKeys): bool
    {
        foreach ($writeableKeys as $wk) {
            $wk = (string)$wk;
            if ($wk === '') continue;
            if (array_key_exists($wk, $this->flatProfile) && $this->modeHasW($this->flatProfile[$wk])) {
                return true;
            }
            $prefix = $wk . '.';
            foreach ($this->flatProfile as $k => $v) {
                if (strpos($k, $prefix) === 0 && $this->modeHasW($v)) return true;
            }
            $wrappers = ['','property.','value.','profile.'];
            foreach ($wrappers as $wrap) {
                $cand = $wrap . $wk;
                if (array_key_exists($cand, $this->flatProfile) && $this->modeHasW($this->flatProfile[$cand])) return true;
                for ($i = 0; $i <= 4; $i++) {
                    $candIdx = $wrap . $i . '.' . $wk;
                    if (array_key_exists($candIdx, $this->flatProfile) && $this->modeHasW($this->flatProfile[$candIdx])) return true;
                }
            }
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
            $base = preg_replace('/\.(mode|type)$/i', '', $wk);
            if (is_string($base) && $base !== '') {
                $candidates = [$base];
                foreach (['property.', 'value.', 'profile.'] as $wrap) {
                    $candidates[] = $wrap . $base;
                    for ($i = 0; $i <= 4; $i++) {
                        $candidates[] = $wrap . $i . '.' . $base;
                    }
                    if ($wrap === 'value.') {
                        for ($i = 0; $i <= 4; $i++) {
                            $candidates[] = 'value.property.' . $i . '.' . $base;
                        }
                    }
                    if ($wrap === 'profile.') {
                        for ($i = 0; $i <= 4; $i++) {
                            $candidates[] = 'profile.value.property.' . $i . '.' . $base;
                        }
                    }
                }
                foreach ($this->flatProfile as $k => $v) {
                    foreach ($candidates as $cand) {
                        if (strpos($k, $cand) === 0 && strpos($k, '.value.w') !== false) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    public function modeHasW($mode): bool
    {
        if (is_string($mode)) {
            return str_contains(strtolower($mode), 'w');
        }
        if (is_array($mode)) {
            foreach ($mode as $m) { if ($this->modeHasW($m)) return true; }
        }
        return false;
    }

    /** @param array<string, mixed> $cap */
    public function capHasWriteDefinition(array $cap): bool
    {
        $w = $cap['write'] ?? null;
        if (!is_array($w)) return false;
        foreach (['enumMap', 'attribute'] as $k) {
            if (isset($w[$k]) && is_array($w[$k])) return true;
        }
        return false;
    }

    /** @param array<string, mixed> $cap */
    public function shouldEnableAction(array $cap): bool
    {
        $enableWhen = strtolower((string)($cap['action']['enableWhen'] ?? ''));
        if ($enableWhen === 'never') return false;
        if ($enableWhen === 'always') return true;
        if ($enableWhen === 'profilewriteableany') {
            $writeKeys = $cap['action']['writeableKeys'] ?? [];
            if (is_array($writeKeys) && $this->profileHasWriteAny($writeKeys)) {
                return true;
            }
            return $this->capHasWriteDefinition($cap);
        }
        return false;
    }

    /**
     * @param array<string, mixed> $cap
     * @param array<string, mixed> $flatProfile
     * @param array<string, mixed> $flatStatus
     */
    public function shouldCreate(array $cap, array $flatProfile, array $flatStatus): bool
    {
        $create = $cap['create'] ?? [];
        $when = strtolower((string)($create['when'] ?? 'always'));
        $keys = $create['keys'] ?? [];
        if ($when === 'always') return true;
        if (!is_array($keys) || empty($keys)) return false;
        if ($when === 'statushasany') {
            foreach ($keys as $k) { if (array_key_exists($k, $flatStatus)) return true; }
            foreach ($keys as $k) {
                foreach ($flatStatus as $fk => $_) {
                    if (strpos($fk, $k) !== false) return true;
                }
            }
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
}
