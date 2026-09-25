<?php

declare(strict_types=1);

/*
 * Checks a control command against a device profile the way the fake cloud needs it, and
 * merges commands and reports into a device state. The profile shapes come from LG's
 * examples: resources as objects, element lists selected by locationName/switchName/unit
 * (refrigerator, light switch, AC temperatureInUnits), zone lists with a "location"
 * wrapper (washer, oven, cooktop) and two sub-profiles (washtower: washer + dryer).
 */
final class ThinQCommandCheck
{
    private const SELECTORS = ['locationName', 'switchName', 'unit'];

    /** @return string|null reason for rejection, null if LG would accept it */
    public static function validate(array $profile, array $cmd): ?string
    {
        if ($cmd === []) {
            return 'leerer Befehl';
        }
        if (!isset($profile['property']) && (isset($profile['washer']) || isset($profile['dryer']))) {
            foreach ($cmd as $part => $sub) {
                if (!isset($profile[$part]) || !is_array($sub)) {
                    return 'Teilgerät ' . $part . ' nicht im Profil';
                }
                $err = self::validate($profile[$part], $sub);
                if ($err !== null) {
                    return $part . ': ' . $err;
                }
            }
            return null;
        }
        $zone = self::zone($profile, $cmd['location']['locationName'] ?? null);
        if (is_string($zone)) {
            return $zone;
        }
        foreach ($cmd as $resource => $values) {
            if ($resource === 'location') {
                continue;
            }
            $def = $zone[$resource] ?? null;
            if (!is_array($def)) {
                return 'Ressource ' . $resource . ' nicht im Profil';
            }
            if (!is_array($values) || array_is_list($values)) {
                return 'Ressource ' . $resource . ' braucht ein Objekt';
            }
            if (array_is_list($def)) {
                $def = self::element($def, $values);
                if (is_string($def)) {
                    return $resource . ': ' . $def;
                }
            }
            if ($resource === 'airFlow' && isset($values['windStrength'], $values['windStrengthDetail'])) {
                return 'windStrength und windStrengthDetail zusammen'; // [Spez] air conditioner, airFlow
            }
            foreach ($values as $prop => $v) {
                $meta = $def[$prop] ?? null;
                if (!is_array($meta)) {
                    if (in_array($prop, self::SELECTORS, true) && (is_string($meta) || $prop === 'unit')) {
                        if (is_string($meta) && (string)$v !== $meta) {
                            return $resource . '.' . $prop . ' passt nicht zum Element';
                        }
                        continue;
                    }
                    return $resource . '.' . $prop . ' nicht im Profil';
                }
                if ($prop === 'unit' && self::unitAllowed($meta, $v)) {
                    continue; // unit travels along with temperatures, also where the profile marks it read-only
                }
                if (!in_array('w', (array)($meta['mode'] ?? []), true)) {
                    return $resource . '.' . $prop . ' nicht schreibbar';
                }
                $err = self::checkValue($meta, $v);
                if ($err !== null) {
                    return $resource . '.' . $prop . ': ' . $err;
                }
            }
        }
        return null;
    }

    /** Resources of the addressed zone, or the reason why there is none. */
    private static function zone(array $profile, ?string $location): array|string
    {
        $prop = $profile['property'] ?? $profile;
        if (!is_array($prop)) {
            return 'Profil ohne property';
        }
        if (!array_is_list($prop)) {
            return $prop;
        }
        if ($location === null) {
            if (count($prop) === 1) {
                return self::unwrap($prop[0]); // [Annahme] a single zone needs no location
            }
            return 'location fehlt, das Profil hat ' . count($prop) . ' Zonen';
        }
        foreach ($prop as $wrapper) {
            if (($wrapper['location']['locationName'] ?? null) === $location) {
                return self::unwrap($wrapper);
            }
        }
        return 'Zone ' . $location . ' nicht im Profil';
    }

    private static function unwrap(mixed $wrapper): array
    {
        $out = is_array($wrapper) ? $wrapper : [];
        unset($out['location']);
        return $out;
    }

    /** Picks the list element addressed by the selectors in the command. */
    private static function element(array $list, array $values): array|string
    {
        $hits = [];
        foreach ($list as $el) {
            if (!is_array($el)) {
                continue;
            }
            $given = 0;
            $ok = true;
            foreach (self::SELECTORS as $key) {
                if (isset($el[$key]) && is_string($el[$key]) && array_key_exists($key, $values)) {
                    $given++;
                    $ok = $ok && (string)$values[$key] === $el[$key];
                }
            }
            if ($given > 0 && $ok) {
                $hits[] = $el;
            }
        }
        if (count($hits) === 1) {
            return $hits[0];
        }
        return $hits === [] ? 'kein Element passt zu ' . json_encode(array_intersect_key($values, array_flip(self::SELECTORS)))
            : 'mehrdeutig (' . count($hits) . ' Elemente)';
    }

    private static function unitAllowed(array $meta, mixed $v): bool
    {
        $allowed = array_merge((array)($meta['value']['r'] ?? []), (array)($meta['value']['w'] ?? []));
        return is_string($v) && in_array($v, $allowed, true);
    }

    private static function checkValue(array $meta, mixed $v): ?string
    {
        $type = (string)($meta['type'] ?? '');
        $allowed = $meta['value']['w'] ?? ($meta['value']['r'] ?? null);
        switch ($type) {
            case 'enum':
                return is_string($v) && in_array($v, (array)$allowed, true) ? null
                    : json_encode($v) . ' nicht in ' . json_encode($allowed);
            case 'boolean':
                return is_bool($v) ? null : json_encode($v) . ' ist kein Wahrheitswert';
            case 'range':
                if (!is_int($v) && !is_float($v)) {
                    return json_encode($v) . ' ist keine Zahl';
                }
                $min = (float)($allowed['min'] ?? PHP_INT_MIN);
                $max = (float)($allowed['max'] ?? PHP_INT_MAX);
                $step = (float)($allowed['step'] ?? 0);
                if ($v < $min || $v > $max) {
                    return $v . ' außerhalb ' . $min . '..' . $max;
                }
                if ($step > 0) {
                    $n = ($v - $min) / $step;
                    if (abs($n - round($n)) > 1e-6) {
                        return $v . ' liegt nicht im Raster ' . $step;
                    }
                }
                if (in_array($v, (array)($allowed['except'] ?? []), false)) {
                    return $v . ' ist ausgenommen';
                }
                return null;
            case 'number':
                return is_int($v) || is_float($v) ? null : json_encode($v) . ' ist keine Zahl';
        }
        return null;
    }

    /** The part of a command the device reports back: readable properties plus their selectors. */
    public static function readablePart(array $profile, array $cmd): array
    {
        if (!isset($profile['property']) && (isset($profile['washer']) || isset($profile['dryer']))) {
            $out = [];
            foreach ($cmd as $part => $sub) {
                $r = is_array($sub) && isset($profile[$part]) ? self::readablePart($profile[$part], $sub) : [];
                if ($r !== []) {
                    $out[$part] = $r;
                }
            }
            return $out;
        }
        $zone = self::zone($profile, $cmd['location']['locationName'] ?? null);
        if (is_string($zone)) {
            return [];
        }
        $out = [];
        foreach ($cmd as $resource => $values) {
            if ($resource === 'location' || !is_array($values) || !isset($zone[$resource]) || !is_array($zone[$resource])) {
                continue;
            }
            $def = array_is_list($zone[$resource]) ? self::element($zone[$resource], $values) : $zone[$resource];
            if (is_string($def)) {
                continue;
            }
            $part = [];
            foreach ($values as $prop => $v) {
                $meta = $def[$prop] ?? null;
                if (in_array($prop, self::SELECTORS, true) || (is_array($meta) && in_array('r', (array)($meta['mode'] ?? []), true))) {
                    $part[$prop] = $v;
                }
            }
            if (array_diff_key($part, array_flip(self::SELECTORS)) !== []) {
                $out[$resource] = array_is_list($zone[$resource]) ? [$part] : $part;
            }
        }
        if ($out !== [] && isset($cmd['location'])) {
            $out = ['location' => $cmd['location']] + $out;
        }
        return $out;
    }

    /** Merges a command/report into a state: zones by location, element lists by selector, rest deep. */
    public static function merge(mixed $state, array $patch): array
    {
        $state = is_array($state) ? $state : [];
        if (array_is_list($state) && isset($state[0]['location'])) {
            $loc = $patch['location']['locationName'] ?? null;
            foreach ($state as $i => $zone) {
                if ($loc === null || ($zone['location']['locationName'] ?? null) === $loc) {
                    $state[$i] = self::merge($zone, $patch);
                    return $state;
                }
            }
            $state[] = $patch;
            return $state;
        }
        foreach ($patch as $key => $value) {
            $cur = $state[$key] ?? null;
            if (is_array($cur) && array_is_list($cur) && is_array($value)) {
                foreach (array_is_list($value) ? $value : [$value] as $el) {
                    $state[$key] = self::mergeElement($cur, (array)$el);
                    $cur = $state[$key];
                }
            } elseif (is_array($cur) && is_array($value)) {
                $state[$key] = self::merge($cur, $value);
            } else {
                $state[$key] = $value;
            }
        }
        return $state;
    }

    private static function mergeElement(array $list, array $el): array
    {
        foreach ($list as $i => $cur) {
            $match = false;
            foreach (self::SELECTORS as $key) {
                if (isset($el[$key], $cur[$key])) {
                    if ($el[$key] !== $cur[$key]) {
                        continue 2;
                    }
                    $match = true;
                }
            }
            if ($match) {
                $list[$i] = array_merge($cur, $el);
                return $list;
            }
        }
        $list[] = $el;
        return $list;
    }
}
