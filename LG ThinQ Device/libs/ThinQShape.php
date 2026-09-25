<?php

declare(strict_types=1);

/**
 * The shapes of LG's profiles and states in one place. LG uses three kinds of structure:
 * resources as objects, element lists selected by locationName/switchName/unit (refrigerator,
 * light switch, AC temperatureInUnits), zone lists with a "location" wrapper (washer, oven,
 * cooktop, plant cultivator) and sub-devices (washtower: washer + dryer). The rules follow LG's
 * examples; tests/fake/ThinQCommandCheck.php checks commands against the same rules.
 */
final class ThinQShape
{
    public const SELECTORS = ['locationName', 'switchName', 'unit'];
    public const PARTS = ['washer', 'dryer'];

    /**
     * LG's profile answer in one stored form: {property, error, notification} or, for the
     * washtower, {washer: {property…}, dryer: {property…}}. Repairs copies stored without the
     * property wrapper (earlier versions did that after a push) and the washtower wrapped into
     * {property: {washer, dryer}}. Null for anything without properties; that must not be stored.
     *
     * @return array<string, mixed>|null
     */
    public static function wrapProfile(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }
        foreach (['response', 'profile'] as $envelope) {
            if (!array_key_exists('property', $raw) && isset($raw[$envelope]) && is_array($raw[$envelope])) {
                return self::wrapProfile($raw[$envelope]);
            }
        }
        if (self::isPartMap($raw)) {
            $parts = [];
            foreach ($raw as $part => $sub) {
                $wrapped = self::wrapProfile($sub);
                if ($wrapped !== null) {
                    $parts[$part] = $wrapped;
                }
            }
            return $parts === [] ? null : $parts;
        }
        if (array_key_exists('property', $raw)) {
            if (is_array($raw['property']) && self::isPartMap($raw['property'])) {
                return self::wrapProfile($raw['property']);
            }
            return self::hasProperties($raw['property']) ? $raw : null;
        }
        return self::hasProperties($raw) ? ['property' => $raw] : null;
    }

    /** @param array<string, mixed> $profile */
    public static function isPartProfile(array $profile): bool
    {
        return !array_key_exists('property', $profile) && self::isPartMap($profile);
    }

    /** LG's state answer or a push report in the stored form: {state: …} and one-zone lists unwrapped. */
    public static function status(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        if (isset($raw['state']) && is_array($raw['state'])) {
            $raw = $raw['state'];
        }
        if (array_is_list($raw) && count($raw) === 1 && is_array($raw[0])) {
            $raw = $raw[0];
        }
        return $raw;
    }

    /**
     * Merges a report into the stored state: zones by location, element lists by selector
     * (locationName, switchName, unit), everything else key by key.
     *
     * @param array<mixed> $state
     * @param array<mixed> $patch
     * @return array<mixed>
     */
    public static function merge(array $state, array $patch): array
    {
        if (self::isZoneList($state)) {
            foreach (array_is_list($patch) ? $patch : [$patch] as $zonePatch) {
                if (is_array($zonePatch)) {
                    $state = self::mergeZone($state, $zonePatch);
                }
            }
            return $state;
        }
        foreach ($patch as $key => $value) {
            $current = $state[$key] ?? null;
            if (is_array($current) && array_is_list($current) && $current !== [] && is_array($value)) {
                foreach (array_is_list($value) ? $value : [$value] as $element) {
                    $current = self::mergeElement($current, is_array($element) ? $element : []);
                }
                $state[$key] = $current;
            } elseif (is_array($current) && is_array($value) && !array_is_list($value)) {
                $state[$key] = self::merge($current, $value);
            } else {
                $state[$key] = $value;
            }
        }
        return $state;
    }

    /**
     * Resources of a zone: the one named (or the only/first one) of a zone list, else the
     * resource map itself; for the washtower those of $part.
     *
     * @param array<string, mixed> $profile wrapped (see wrapProfile)
     * @return array<string, mixed>
     */
    public static function resources(array $profile, ?string $zone = null, ?string $part = null): array
    {
        if ($part !== null) {
            $profile = is_array($profile[$part] ?? null) ? $profile[$part] : [];
        }
        $property = $profile['property'] ?? [];
        if (!is_array($property)) {
            return [];
        }
        if (!self::isZoneList($property)) {
            return $property;
        }
        foreach ($property as $wrapper) {
            if ($zone === null || ($wrapper['location']['locationName'] ?? null) === $zone) {
                unset($wrapper['location']);
                return $wrapper;
            }
        }
        return [];
    }

    /** @return array<int, string> zone names of a zone-list profile, [] otherwise */
    public static function zones(array $profile, ?string $part = null): array
    {
        $property = ($part !== null ? ($profile[$part]['property'] ?? []) : ($profile['property'] ?? []));
        if (!is_array($property) || !self::isZoneList($property)) {
            return [];
        }
        return array_values(array_map(static fn(array $w): string => (string)($w['location']['locationName'] ?? ''), $property));
    }

    /** The selector key of an element list (locationName, switchName, unit), null for anything else. */
    public static function selectorOf(mixed $list): ?string
    {
        if (!is_array($list) || !array_is_list($list) || $list === []) {
            return null;
        }
        foreach (self::SELECTORS as $key) {
            $values = [];
            foreach ($list as $element) {
                if (!is_array($element) || !is_string($element[$key] ?? null)) {
                    continue 2;
                }
                $values[] = $element[$key];
            }
            if (count(array_unique($values)) === count($values)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * min/max/step of a property from value.w, else value.r; for element lists from the element the
     * selector names (e.g. ['locationName' => 'FREEZER']), without a selector from the first element.
     *
     * @param array<string, mixed> $profile wrapped
     * @param array<string, string> $selector
     * @return array{min?: float, max?: float, step?: float}|null
     */
    public static function range(array $profile, string $resource, string $property, array $selector = [], ?string $zone = null, ?string $part = null): ?array
    {
        $def = self::resources($profile, $zone, $part)[$resource] ?? null;
        if (is_array($def) && array_is_list($def)) {
            $def = self::element($def, $selector);
        }
        $meta = is_array($def) ? ($def[$property] ?? null) : null;
        $value = is_array($meta) ? ($meta['value']['w'] ?? ($meta['value']['r'] ?? null)) : null;
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach (['min', 'max', 'step'] as $key) {
            if (isset($value[$key]) && is_numeric($value[$key])) {
                $out[$key] = (float)$value[$key];
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * "resource.property" of every property in the profile, over all zones and element lists,
     * prefixed with "washer."/"dryer." for sub-devices.
     *
     * @param array<string, mixed> $profile wrapped
     * @return array<string, true>
     */
    public static function profileKeys(array $profile): array
    {
        if (self::isPartProfile($profile)) {
            $keys = [];
            foreach ($profile as $part => $sub) {
                foreach (self::profileKeys(is_array($sub) ? $sub : []) as $key => $_) {
                    $keys[$part . '.' . $key] = true;
                }
            }
            return $keys;
        }
        $keys = [];
        $property = $profile['property'] ?? [];
        $zones = is_array($property) && self::isZoneList($property) ? $property : [$property];
        if (is_array($profile['extensionProperty'] ?? null)) {
            $zones[] = $profile['extensionProperty'];
        }
        foreach ($zones as $zone) {
            foreach (is_array($zone) ? $zone : [] as $resource => $def) {
                foreach (is_array($def) && array_is_list($def) ? $def : [$def] as $element) {
                    foreach (is_array($element) ? $element : [] as $prop => $meta) {
                        if (is_array($meta) && isset($meta['type'])) {
                            $keys[$resource . '.' . $prop] = true;
                        }
                    }
                }
            }
        }
        return $keys;
    }

    /**
     * "resource.property" of every value in a state or report, over zones and element lists
     * (selectors and the zone wrapper left out), prefixed with the sub-device for the washtower.
     *
     * @param array<mixed> $status
     * @return array<string, true>
     */
    public static function statusKeys(array $status): array
    {
        $keys = [];
        foreach (self::isZoneList($status) || array_is_list($status) ? $status : [$status] as $zone) {
            foreach (is_array($zone) ? $zone : [] as $resource => $values) {
                if ($resource === 'location' || !is_array($values)) {
                    continue;
                }
                if (in_array($resource, self::PARTS, true) && !array_is_list($values)) {
                    foreach (self::statusKeys($values) as $key => $_) {
                        $keys[$resource . '.' . $key] = true;
                    }
                    continue;
                }
                foreach (array_is_list($values) ? $values : [$values] as $element) {
                    foreach (is_array($element) ? $element : [] as $prop => $value) {
                        if (!in_array($prop, self::SELECTORS, true) && !is_array($value)) {
                            $keys[$resource . '.' . $prop] = true;
                        }
                    }
                }
            }
        }
        return $keys;
    }

    /** A list of zone objects, each with location.locationName. */
    public static function isZoneList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return false;
        }
        foreach ($value as $zone) {
            if (!is_array($zone) || !is_string($zone['location']['locationName'] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<mixed> $selector */
    private static function element(array $list, array $selector): ?array
    {
        foreach ($list as $element) {
            if (!is_array($element)) {
                continue;
            }
            if ($selector === []) {
                return $element;
            }
            foreach ($selector as $key => $value) {
                if (($element[$key] ?? null) !== $value) {
                    continue 2;
                }
            }
            return $element;
        }
        return null;
    }

    private static function mergeZone(array $zones, array $patch): array
    {
        $name = $patch['location']['locationName'] ?? null;
        foreach ($zones as $i => $zone) {
            if ($name === null || ($zone['location']['locationName'] ?? null) === $name) {
                $zones[$i] = self::merge($zone, $patch);
                return $zones;
            }
        }
        $zones[] = $patch;
        return $zones;
    }

    private static function mergeElement(array $list, array $element): array
    {
        foreach ($list as $i => $current) {
            if (!is_array($current)) {
                continue;
            }
            $match = false;
            foreach (self::SELECTORS as $key) {
                if (isset($element[$key], $current[$key])) {
                    if ($element[$key] !== $current[$key]) {
                        continue 2;
                    }
                    $match = true;
                }
            }
            if ($match) {
                $list[$i] = array_merge($current, $element);
                return $list;
            }
        }
        $list[] = $element;
        return $list;
    }

    private static function isPartMap(array $value): bool
    {
        if ($value === [] || array_is_list($value)) {
            return false;
        }
        foreach ($value as $key => $sub) {
            if (!in_array($key, self::PARTS, true) || !is_array($sub)) {
                return false;
            }
        }
        return true;
    }

    /** Somewhere below: a property description (an array with type). */
    private static function hasProperties(mixed $value, int $depth = 0): bool
    {
        if (!is_array($value) || $depth > 4) {
            return false;
        }
        if (isset($value['type']) && is_string($value['type'])) {
            return $depth >= 2;
        }
        foreach ($value as $child) {
            if (self::hasProperties($child, $depth + 1)) {
                return true;
            }
        }
        return false;
    }
}
