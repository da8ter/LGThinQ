<?php

declare(strict_types=1);

/**
 * ThinQProfileParser
 * 
 * Automatically generates variable plans from LG ThinQ API device profiles.
 * Implements patterns from official pythinqconnect SDK.
 * 
 * Features:
 * - Auto-discovery of properties from profile structure
 * - Multi-location support (Main/Sub for Fridge, Oven, Cooktop)
 * - Type-based presentation inference (boolean→switch, range→slider, enum→buttons)
 * - Generic property library integration
 * - SDK-conformant naming patterns
 */
class ThinQProfileParser
{
    private ThinQNaming $naming;

    /**
     * Zone of the resources being parsed (washer, oven, cooktop: {location: {locationName: …}});
     * commands carry it as a separate top-level key, unlike the elements of an element list
     * (refrigerator compartments) whose locationName is inside the resource.
     */
    private ?string $topLevelLocation = null;

    public function __construct(?ThinQNaming $naming = null)
    {
        $this->naming = $naming ?? new ThinQNaming();
    }

    public function naming(): ThinQNaming
    {
        return $this->naming;
    }

    /**
     * Variable plan of a device profile (any form ThinQShape knows): every zone of a zone list with
     * the zone as ident prefix, element lists per element (locationName/switchName as prefix, unit
     * lists through the device's unit), extensionProperty without zone, and the parts of a washtower
     * with WASHER_/DRYER_ as prefix.
     *
     * @param array<string, mixed> $profile Device profile from API
     * @return array<string, array<string, mixed>> Variable plan keyed by ident
     */
    public function parseProfile(array $profile): array
    {
        $wrapped = ThinQShape::wrapProfile($profile);
        if ($wrapped === null) {
            return [];
        }
        if (ThinQShape::isPartProfile($wrapped)) {
            $plan = [];
            foreach ($wrapped as $part => $sub) {
                foreach ($this->parseProfile($sub) as $entry) {
                    $entry['ident'] = strtoupper((string)$part) . '_' . $entry['ident'];
                    $entry['name'] = ThinQNaming::partPrefix((string)$part) . $entry['name'];
                    $entry['path'] = $part . '.' . $entry['path'];
                    $entry['part'] = (string)$part;
                    unset($entry['legacyIdent']);
                    $plan[$entry['ident']] = $entry;
                }
            }
            return $plan;
        }
        $zones = ThinQShape::zones($wrapped);
        $plan = [];
        if (count($zones) > 1) {
            // Several zones (cooktop, plant cultivator): each gets its prefix, commands carry the zone
            foreach ($zones as $i => $zone) {
                foreach ($this->parseResources(ThinQShape::resources($wrapped, $zone), $zone) as $ident => $entry) {
                    $entry['ident'] = strtoupper($zone) . '_' . $ident;
                    $entry['name'] = ThinQNaming::zonePrefix($zone) . $entry['name'];
                    $entry['zone'] = $zone;
                    if ($i === 0) {
                        $entry['legacyIdent'] = $ident; // earlier versions parsed only this zone, without prefix
                    }
                    $plan[$entry['ident']] = $entry;
                }
            }
        } else {
            $plan = $this->parseResources(ThinQShape::resources($wrapped), $zones[0] ?? null);
        }
        if (is_array($wrapped['extensionProperty'] ?? null)) {
            $plan += $this->parseResources($wrapped['extensionProperty'], null); // device-wide, no zone in commands
        }
        return $plan;
    }

    /**
     * Entries for the resources of one zone; $wrap is the zone name commands carry as top-level
     * location (washer, oven: {"location": {…}, …}). Of the temperature resources of refrigerators
     * and air conditioners only the target and current temperature are taken.
     *
     * @param array<string, mixed> $properties
     * @return array<string, array<string, mixed>>
     */
    private function parseResources(array $properties, ?string $wrap): array
    {
        $this->topLevelLocation = $wrap;
        $plan = [];
        $temp = $properties['temperature'] ?? null;
        $tiu  = $properties['temperatureInUnits'] ?? null;
        $skip = [];
        $hasLoc = fn($d): bool => $this->isLocationList($d) || (is_array($d) && is_string($d['locationName'] ?? null) && $d['locationName'] !== '');
        // Prefer 'temperature' with location; else 'temperatureInUnits' with location; else the two main fields
        $pick = null;
        if (is_array($temp) && $hasLoc($temp)) {
            $pick = ['temperature', $temp, ['targetTemperature', 'currentTemperature']];
        } elseif (is_array($tiu) && $hasLoc($tiu)) {
            $pick = ['temperatureInUnits', $tiu, ['targetTemperatureC', 'currentTemperatureC']];
        } elseif (is_array($temp)) {
            $pick = ['temperature', $temp, ['targetTemperature', 'currentTemperature']];
        } elseif (is_array($tiu)) {
            $pick = ['temperatureInUnits', $tiu, ['targetTemperatureC', 'currentTemperatureC']];
        }
        if ($pick !== null) {
            [$res, $data, $allowed] = $pick;
            if (ThinQShape::selectorOf($data) === 'unit') { // oven: one temperature per unit
                $element = $this->unitElement($data);
                $plan = $this->parseResource($res, $element, null, $allowed, ['unit' => (string)$element['unit']]);
                $data = [];
            }
            foreach ($this->isLocationList($data) ? $data : [$data] as $idx => $locData) {
                if (!is_array($locData)) continue;
                $location = $hasLoc($data) ? (string)($locData['locationName'] ?? ($this->isLocationList($data) ? 'LOC_' . $idx : 'MAIN')) : null;
                $plan = array_merge($plan, $this->parseResource($res, $locData, $location, $allowed, $location !== null ? ['locationName' => $location] : null));
            }
            $skip = ['temperature' => true, 'temperatureInUnits' => true];
        }

        foreach ($properties as $resource => $data) {
            $resource = (string)$resource;
            // 'location' is the zone wrapper, not a resource
            if (isset($skip[$resource]) || !is_array($data) || $resource === 'location') {
                continue;
            }
            $selector = ThinQShape::selectorOf($data);
            if ($selector === 'locationName' || $selector === 'switchName') {
                foreach ($data as $element) {
                    $value = (string)$element[$selector];
                    $plan = array_merge($plan, $this->parseResource($resource, $element, $value, null, [$selector => $value]));
                }
            } elseif ($selector === 'unit') {
                // One variable per property in the device's unit, not one per unit; skipped next to a plain twin
                if (!isset($properties[(string)preg_replace('/InUnits$/', '', $resource)]) || !str_ends_with($resource, 'InUnits')) {
                    $element = $this->unitElement($data);
                    $plan = array_merge($plan, $this->parseResource($resource, $element, null, null, ['unit' => (string)$element['unit']], true));
                }
            } elseif (!array_is_list($data)) {
                $location = is_string($data['locationName'] ?? null) ? $data['locationName'] : null;
                $plan = array_merge($plan, $this->parseResource($resource, $data, $location, null, $location !== null ? ['locationName' => $location] : null));
            }
        }
        return $plan;
    }

    /** A list of elements selected by locationName (refrigerator compartments). */
    private function isLocationList(mixed $data): bool
    {
        return ThinQShape::selectorOf($data) === 'locationName';
    }

    /** The element of a unit list in Celsius, else the first one. */
    private function unitElement(array $list): array
    {
        foreach ($list as $element) {
            if (($element['unit'] ?? null) === 'C') {
                return $element;
            }
        }
        return $list[0];
    }

    /**
     * Entries for the properties of one resource (or one element of an element list).
     *
     * @param array<string, mixed> $data resource or element
     * @param string|null $location ident prefix of an element (FRIDGE, SWITCH_1)
     * @param array<int, string>|null $allowed only these properties
     * @param array<string, string>|null $selector how reads and commands address the element, e.g. ['switchName' => 'SWITCH_1']
     * @param bool $skipBounds leave out read-only bounds (minTemperature, airCoolMaxTemperature) in unit lists
     * @return array<string, array<string, mixed>>
     */
    private function parseResource(string $resource, array $data, ?string $location = null, ?array $allowed = null, ?array $selector = null, bool $skipBounds = false): array
    {
        $plan = [];
        foreach ($data as $attrName => $meta) {
            $attrName = (string)$attrName;
            if (!is_array($meta) || !isset($meta['type']) || in_array($attrName, ThinQShape::SELECTORS, true)) {
                continue;
            }
            if (($allowed !== null && !in_array($attrName, $allowed, true))
                || ($skipBounds && preg_match('/(^(min|max)|[a-z](Min|Max))[A-Z]/', $attrName) === 1 && !$this->isWriteable($meta))) {
                continue;
            }
            $ident = $this->buildIdent($resource, $attrName, $location);
            $writeable = $this->isWriteable($meta);
            // Timer HOUR properties are settable even where the profile marks them read-only
            // (relativeHourToStart, absoluteHourToStop, timerHour, targetHour); minutes follow the profile
            if (preg_match('/hour.*to.*(start|stop)|^(timer|target)Hour$/i', $attrName)) {
                $writeable = true;
            }
            $entry = [
                'ident' => $ident,
                'name' => $this->naming->property($attrName, $resource, $location),
                'type' => ThinQValue::variableType($meta),
                'path' => $resource . '.' . $attrName,
                'resource' => $resource,
                'property' => $attrName,
                'location' => $location,
                'selector' => $selector,
                'readable' => $this->isReadable($meta),
                'writeable' => $writeable,
                'presentation' => $this->inferPresentation($meta, $attrName, $writeable),
                'range' => $this->extractRange($meta),
                'enum' => $this->extractEnum($meta),
                'meta' => $meta // Keep original for write payloads
            ];
            // Zone lists (washer, oven, cooktop) carry the zone as a separate top-level key in commands
            if ($location === null && $this->topLevelLocation !== null) {
                $entry['topLevelLocation'] = $this->topLevelLocation;
            }
            $plan[$ident] = $entry;
        }
        return $plan;
    }

    /**
     * Build variable identifier following SDK naming patterns
     * 
     * @param string $resource
     * @param string $attr
     * @param string|null $location
     * @return string
     */
    private function buildIdent(string $resource, string $attr, ?string $location = null): string
    {
        // Convert camelCase to SNAKE_CASE
        $resourceSnake = $this->camelToSnake($resource);
        $attrSnake = $this->camelToSnake($attr);
        
        // Build base ident
        $base = strtoupper($resourceSnake) . '_' . strtoupper($attrSnake);
        
        // Prepend location if provided (and not 'MAIN' which is default)
        if ($location !== null && strtoupper($location) !== 'MAIN') {
            return strtoupper($location) . '_' . $base;
        }
        
        return $base;
    }
    
    /**
     * Convert camelCase to snake_case
     * 
     * @param string $input
     * @return string
     */
    private function camelToSnake(string $input): string
    {
        // Insert underscore before uppercase letters (except first char)
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $input);
        return strtolower($snake);
    }
    
    /**
     * Check if property is readable
     * 
     * @param array<string, mixed> $meta
     * @return bool
     */
    private function isReadable(array $meta): bool
    {
        $modes = $meta['mode'] ?? [];
        return is_array($modes) && in_array('r', $modes, true);
    }
    
    /**
     * Check if property is writeable
     * 
     * @param array<string, mixed> $meta
     * @return bool
     */
    private function isWriteable(array $meta): bool
    {
        $modes = $meta['mode'] ?? [];
        return is_array($modes) && in_array('w', $modes, true);
    }
    
    /**
     * Infer presentation configuration from meta-data
     * 
     * @param array<string, mixed> $meta
     * @param string $propertyName
     * @param bool $writeable
     * @return array<string, mixed>|null
     */
    private function inferPresentation(array $meta, string $propertyName, bool $writeable): ?array
    {
        // Type-based inference
        $propertySnake = $this->camelToSnake($propertyName);
        $type = $meta['type'] ?? '';
        
        if ($type === 'boolean') {
            return ['kind' => 'switch'];
        }
        
        if ($type === 'range' || $type === 'number') {
            // Use slider for writeable numbers, value for read-only
            $presentation = ['kind' => $writeable ? 'slider' : 'value'];
            
            // Extract range from profile (uses existing extractRange method)
            $rangeInfo = $this->extractRange($meta);
            if ($rangeInfo !== null) {
                $presentation['range'] = [
                    'min' => $rangeInfo['min'],
                    'max' => $rangeInfo['max'],
                    'step' => $rangeInfo['step']
                ];
            } else {
                // Fallback: Default ranges for common timer properties
                if (strpos($propertySnake, 'hour') !== false) {
                    $presentation['range'] = ['min' => 0, 'max' => 24, 'step' => 1];
                } elseif (strpos($propertySnake, 'minute') !== false) {
                    $presentation['range'] = ['min' => 0, 'max' => 59, 'step' => 1];
                } elseif (strpos($propertySnake, 'temperature') !== false) {
                    // Temperature properties: sensible Celsius range, float-step for number-type
                    $presentation['range'] = ['min' => 0, 'max' => 100, 'step' => 0.5];
                } elseif (strpos($propertySnake, 'humidity') !== false || strpos($propertySnake, 'percent') !== false) {
                    $presentation['range'] = ['min' => 0, 'max' => 100, 'step' => 1];
                } else {
                    // Generic fallback for sliders without range in profile
                    $presentation['range'] = ['min' => 0, 'max' => 100, 'step' => 1];
                }
            }
            
            // Auto-detect suffix from API or property name
            if (strpos($propertySnake, 'temperature') !== false) {
                $presentation['suffix'] = ' °C';  // Default for temperature
            }
            if (strpos($propertySnake, 'humidity') !== false) {
                $presentation['suffix'] = ' %';
            }
            if (strpos($propertySnake, 'hour') !== false) {
                $presentation['suffix'] = ' h';
            }
            if (strpos($propertySnake, 'minute') !== false) {
                $presentation['suffix'] = ' min';
            }
            if (strpos($propertySnake, 'second') !== false) {
                $presentation['suffix'] = ' s';
            }
            if (strpos($propertySnake, 'percent') !== false) {
                $presentation['suffix'] = ' %';
            }
            
            return $presentation;
        }
        
        if ($type === 'enum') {
            $values = $meta['value']['w'] ?? $meta['value']['r'] ?? [];
            if (is_array($values) && !empty($values)) {
                $options = [];
                foreach ($values as $val) {
                    // Use the actual enum string as value (for string variables)
                    // and translate it for caption
                    $options[] = [
                        'value' => (string)$val,  // String value (COOL, HEAT, AUTO, ...)
                        'caption' => $this->naming->caption($propertyName, (string)$val)
                    ];
                }
                
                // Read-only enums use 'value' presentation (display only)
                // Writeable enums use 'buttons' presentation (clickable)
                return [
                    'kind' => $writeable ? 'buttons' : 'value',
                    'options' => $options
                ];
            }
        }
        
        return ['kind' => 'value']; // Fallback
    }
    
    /**
     * Extract range information
     * 
     * @param array<string, mixed> $meta
     * @return array{min: float|int, max: float|int, step: float|int, except?: array}|null
     */
    private function extractRange(array $meta): ?array
    {
        if ($meta['type'] !== 'range' && $meta['type'] !== 'number') {
            return null;
        }
        
        // Prefer writeable range, fallback to readable
        $range = $meta['value']['w'] ?? $meta['value']['r'] ?? null;
        if (!is_array($range)) {
            return null;
        }
        
        $result = [
            'min' => $range['min'] ?? 0,
            'max' => $range['max'] ?? 100,
            'step' => $range['step'] ?? 1
        ];
        
        if (isset($range['except']) && is_array($range['except'])) {
            $result['except'] = $range['except'];
        }
        
        return $result;
    }
    
    /**
     * Extract enum values
     * 
     * @param array<string, mixed> $meta
     * @return array<int, string>|null
     */
    private function extractEnum(array $meta): ?array
    {
        if ($meta['type'] !== 'enum') {
            return null;
        }
        
        $values = $meta['value']['w'] ?? $meta['value']['r'] ?? [];
        return is_array($values) ? array_values($values) : null;
    }
}
