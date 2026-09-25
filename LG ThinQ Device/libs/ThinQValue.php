<?php

declare(strict_types=1);

/** Variable types and values in one place: from LG's profile to the variable and to a command. */
final class ThinQValue
{
    /**
     * Symcon variable type for a profile property. A range becomes FLOAT as soon as min, max or step
     * is fractional (air conditioners set targets in 0.5 steps).
     *
     * @param array<string, mixed> $meta
     */
    public static function variableType(array $meta): int
    {
        switch (strtolower((string)($meta['type'] ?? ''))) {
            case 'boolean':
                return VARIABLETYPE_BOOLEAN;
            case 'number':
                return VARIABLETYPE_FLOAT;
            case 'range':
                foreach (['w', 'r'] as $mode) {
                    foreach (['min', 'max', 'step'] as $key) {
                        $v = $meta['value'][$mode][$key] ?? null;
                        if (is_numeric($v) && floor((float)$v) != (float)$v) {
                            return VARIABLETYPE_FLOAT;
                        }
                    }
                }
                return VARIABLETYPE_INTEGER;
            default:
                return VARIABLETYPE_STRING;
        }
    }

    /** The type the variable actually has; null if the object is no variable. */
    public static function typeOf(int $vid): ?int
    {
        $var = $vid > 0 ? @IPS_GetVariable($vid) : false;
        return is_array($var) ? (int)$var['VariableType'] : null;
    }

    /** $value for a variable of $type; whole numbers are rounded, not cut off. */
    public static function forType(int $type, mixed $value): bool|int|float|string
    {
        return match ($type) {
            VARIABLETYPE_BOOLEAN => is_string($value) ? in_array(strtoupper($value), ['TRUE', 'ON', '1', 'SET'], true) : (bool)$value,
            VARIABLETYPE_INTEGER => (int)round((float)$value),
            VARIABLETYPE_FLOAT => (float)$value,
            default => is_bool($value) ? ($value ? 'true' : 'false') : (string)$value,
        };
    }

    /**
     * Writes $value according to the variable's actual type, only when it changes. An older
     * installation keeps its INTEGER variable where the profile now asks for FLOAT; that
     * variable gets the rounded value instead of none.
     */
    public static function write(int $vid, mixed $value): void
    {
        $type = self::typeOf($vid);
        if ($type === null || is_array($value)) {
            return;
        }
        $new = self::forType($type, $value);
        $current = @GetValue($vid);
        if ($type === VARIABLETYPE_FLOAT ? (is_numeric($current) && abs((float)$current - $new) < 1e-9) : $current === $new) {
            return;
        }
        match ($type) {
            VARIABLETYPE_BOOLEAN => @SetValueBoolean($vid, (bool)$new),
            VARIABLETYPE_INTEGER => @SetValueInteger($vid, (int)$new),
            VARIABLETYPE_FLOAT => @SetValueFloat($vid, (float)$new),
            default => @SetValueString($vid, (string)$new),
        };
    }

    /** A number for a command: whole numbers as int (LG gets 24, not 24.0), fractions as float. */
    public static function forCommand(mixed $value): int|float
    {
        $f = round((float)$value, 6);
        return floor($f) == $f ? (int)$f : $f;
    }

    /**
     * $value clamped to min/max and put on the step grid (min + n·step).
     * @param array{min?: float, max?: float, step?: float} $range
     */
    public static function clamp(float $value, array $range): float
    {
        if (isset($range['min'])) {
            $value = max($range['min'], $value);
        }
        if (isset($range['max'])) {
            $value = min($range['max'], $value);
        }
        $step = (float)($range['step'] ?? 0);
        if ($step > 0) {
            $base = (float)($range['min'] ?? 0);
            $value = round($base + round(($value - $base) / $step) * $step, 6);
        }
        return $value;
    }
}
