<?php

declare(strict_types=1);

/**
 * States a device reports that LG's profile lists under neither value.w nor value.r (the live air
 * conditioner reports windStrengthDetail LOW_MID, the profile knows NATURE/LOW/MID/HIGH). Without an
 * option the state shows as "-"; here it becomes an option and stays one.
 */
final class CapabilityOptionStates
{
    /**
     * The plan presentation of a capability with the states its variable already lists (reported
     * earlier or by an older profile, with the current captions) and the reported value appended.
     *
     * @param array<string, mixed>|null $presentation plan presentation (kind, options…)
     * @param Closure $caption fn(string $value): string
     * @return array<string, mixed>|null
     */
    public static function extend(?array $presentation, mixed $value, int $vid, Closure $caption): ?array
    {
        if (!is_array($presentation) || !is_array($presentation['options'] ?? null) || !is_scalar($value) || is_bool($value)) {
            return $presentation;
        }
        $have = array_map(static fn($option) => (string)($option['value'] ?? ''), $presentation['options']);
        foreach (array_keys(self::variableOptions($vid)) as $known) {
            $known = (string)$known; // PHP turns numeric option values into integer keys
            if (!in_array($known, $have, true)) {
                $presentation['options'][] = ['value' => $known, 'caption' => $caption($known)]; // current translation
                $have[] = $known;
            }
        }
        if (!in_array((string)$value, $have, true)) {
            $presentation['options'][] = ['value' => (string)$value, 'caption' => $caption((string)$value)];
        }
        return $presentation;
    }

    /** True when the variable has options and $value is not one of them. */
    public static function unknown(int $vid, mixed $value): bool
    {
        if (!is_scalar($value) || is_bool($value)) {
            return false;
        }
        $options = self::variableOptions($vid);
        return $options !== [] && !array_key_exists((string)$value, $options);
    }

    /** value => caption of the variable's module presentation, [] without variable or options */
    private static function variableOptions(int $vid): array
    {
        $var = $vid > 0 ? @IPS_GetVariable($vid) : null;
        $current = is_array($var) ? $var['VariablePresentation'] : null;
        $current = is_string($current) ? json_decode($current, true) : $current;
        $out = [];
        foreach (json_decode((string)($current['OPTIONS'] ?? '[]'), true) ?: [] as $option) {
            $known = (string)($option['Value'] ?? '');
            if ($known !== '') {
                $out[$known] = (string)($option['Caption'] ?? $known);
            }
        }
        return $out;
    }
}
