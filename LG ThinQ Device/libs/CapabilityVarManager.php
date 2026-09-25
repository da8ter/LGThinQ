<?php

declare(strict_types=1);

/**
 * CapabilityVarManager
 *
 * Extracted from CapabilityEngine: value-reading, value-writing, and array-index helpers.
 * These are pure utility methods that do not depend on IPSModule instance methods.
 */
class CapabilityVarManager
{
    public function __construct(
        private array $flatProfile,
        private array $flatStatus
    ) {}

    /**
     * Read value using 'read' section from descriptor: a source path (optionally mapped through
     * valueMap, case-insensitive) or an element of a list selected by 'where'.
     * @param array<string, mixed> $cap
     * @param array<string, mixed> $flat
     */
    public function readValue(array $cap, array $flat)
    {
        $read = $cap['read'] ?? null;
        if (!is_array($read)) return null;
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
                        return $v;
                    }
                }
            }
        }
        $map = is_array($read['valueMap'] ?? null) ? array_change_key_case($read['valueMap'], CASE_UPPER) : null;
        foreach (is_array($read['sources'] ?? null) ? $read['sources'] : [] as $p) {
            $v = $this->getFromFlat($flat, (string)$p);
            if ($v !== null) {
                return ($map !== null && array_key_exists(strtoupper((string)$v), $map)) ? $map[strtoupper((string)$v)] : $v;
            }
        }
        return null;
    }

    public function getFromFlat(array $flat, string $path)
    {
        return $flat[$path] ?? null;
    }

    public function setValueByType(int $vid, array $cap, $val): void
    {
        $type = strtoupper((string)($cap['type'] ?? 'string'));

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

    public function convertValueForType(array $cap, $value)
    {
        $type = strtoupper((string)($cap['type'] ?? 'string'));
        return match ($type) {
            'BOOLEAN' => (bool)$value,
            'INTEGER' => (int)$value,
            'FLOAT'   => (float)$value,
            default   => (string)$value
        };
    }

    /**
     * Find min/max/step for a resource.property from the flattened profile.
     * @return array{min?:float,max?:float,step?:float}|null
     */
    public function findRangeFromProfile(string $resource, string $property): ?array
    {
        $min = null; $max = null; $step = null;
        $suffixes = [
            'min' => '.' . $property . '.value.w.min',
            'max' => '.' . $property . '.value.w.max',
            'step' => '.' . $property . '.value.w.step'
        ];
        foreach ($this->flatProfile as $k => $v) {
            if (strpos($k, $resource) === false) continue;
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
     */
    public function findArrayIndex(array $flat, string $container, array $where): ?int
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
    public function collectArrayIndices(array $flat, string $container): array
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
