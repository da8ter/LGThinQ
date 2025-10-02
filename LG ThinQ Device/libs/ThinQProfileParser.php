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
    private string $language = 'de';
    
    /**
     * Parse device profile and generate variable plan
     * 
     * @param array<string, mixed> $profile Device profile from API
     * @return array<string, array<string, mixed>> Variable plan keyed by ident
     */
    public function parseProfile(array $profile): array
    {
        $plan = [];
        
        // Profile can be empty (e.g., not yet loaded in simulation mode)
        if (empty($profile)) {
            return [];
        }
        
        // Profile structure: property array contains resources
        // Can be: property[0] = {...} OR property = [{...}] OR property = {...}
        $properties = $this->normalizePropertyStructure($profile);
        
        foreach ($properties as $resource => $data) {
            if (!is_array($data)) {
                continue; // Skip non-array entries (e.g., simple strings)
            }
            
            // Ensure resource is always a string (can be int if array is numeric)
            $resource = (string)$resource;
            
            // Check if this is a multi-location resource (array of locations)
            if ($this->isMultiLocationArray($data)) {
                foreach ($data as $idx => $locData) {
                    if (!is_array($locData)) continue;
                    $location = $locData['locationName'] ?? "LOC_$idx";
                    $plan = array_merge($plan, $this->parseResource($resource, $locData, $location));
                }
            } elseif (isset($data['locationName'])) {
                // Single location case
                $location = (string)$data['locationName'];
                $plan = array_merge($plan, $this->parseResource($resource, $data, $location));
            } else {
                // Standard case: no location (most common)
                $plan = array_merge($plan, $this->parseResource($resource, $data));
            }
        }
        
        return $plan;
    }
    
    /**
     * Normalize different profile structure variants
     * 
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function normalizePropertyStructure(array $profile): array
    {
        // Variant 1: property[0] = { resource: {...} }
        if (isset($profile['property'][0]) && is_array($profile['property'][0])) {
            return $profile['property'][0];
        }
        
        // Variant 2: property = [{ resource: {...} }]
        if (isset($profile['property']) && is_array($profile['property'])) {
            $first = reset($profile['property']);
            if (is_array($first) && !isset($first['type'])) {
                // This looks like an array wrapper, unwrap it
                return $profile['property'];
            }
            return $profile['property'];
        }
        
        // Variant 3: Direct structure (for testing)
        return $profile;
    }
    
    /**
     * Check if data is an array of location objects
     * 
     * @param array<int|string, mixed> $data
     * @return bool
     */
    private function isMultiLocationArray(array $data): bool
    {
        // If array has numeric keys and first element has properties with 'type'
        if (!isset($data[0])) {
            return false;
        }
        
        $firstElem = $data[0];
        if (!is_array($firstElem)) {
            return false;
        }
        
        // Check if it looks like a property object (has attributes with 'type')
        foreach ($firstElem as $key => $val) {
            if ($key === 'locationName') continue;
            if (is_array($val) && isset($val['type'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Parse a single resource into variable entries
     * 
     * @param string $resource Resource name (e.g., 'timer', 'temperature')
     * @param array<string, mixed> $data Resource data containing properties
     * @param string|null $location Optional location name (e.g., 'FRIDGE', 'MAIN')
     * @return array<string, array<string, mixed>>
     */
    private function parseResource(string $resource, array $data, ?string $location = null): array
    {
        $plan = [];
        
        foreach ($data as $attrName => $meta) {
            // Skip special keys
            if ($attrName === 'locationName' || !is_array($meta)) {
                continue;
            }
            
            // Skip if no type defined (not a property)
            if (!isset($meta['type'])) {
                continue;
            }
            
            $ident = $this->buildIdent($resource, $attrName, $location);
            $fullPropertyName = $resource . '.' . $attrName;
            
            $readable = $this->isReadable($meta);
            $writeable = $this->isWriteable($meta);
            
            // Force writeable for timer properties (often marked read-only in profile but should be settable)
            if (preg_match('/hour.*to.*start|minute.*to.*start|hour.*to.*stop|minute.*to.*stop/i', $attrName)) {
                $writeable = true;
            }
            
            $plan[$ident] = [
                'ident' => $ident,
                'name' => $this->translateProperty($attrName, $resource, $location),
                'type' => $this->mapType($meta['type']),
                'path' => $fullPropertyName,
                'resource' => $resource,
                'property' => $attrName,
                'location' => $location,
                'readable' => $readable,
                'writeable' => $writeable,
                'presentation' => $this->inferPresentation($meta, $attrName, $writeable),
                'range' => $this->extractRange($meta),
                'enum' => $this->extractEnum($meta),
                'meta' => $meta // Keep original for write payloads
            ];
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
     * Map API type to Symcon variable type
     * 
     * @param string $apiType
     * @return int Symcon VARIABLETYPE_* constant
     */
    private function mapType(string $apiType): int
    {
        return match(strtolower($apiType)) {
            'boolean' => VARIABLETYPE_BOOLEAN,
            'number' => VARIABLETYPE_INTEGER,
            'range' => VARIABLETYPE_INTEGER,
            'enum' => VARIABLETYPE_STRING, // Could be INTEGER if mapped
            default => VARIABLETYPE_STRING
        };
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
                        'caption' => ThinQEnumTranslator::translate($propertyName, (string)$val, $this->language)
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
    
    /**
     * Translate property name to human-readable format
     * 
     * @param string $property Property name (e.g., 'remote_control_enabled')
     * @param string $resource Resource name (e.g., 'timer')
     * @param string|null $location Location name (e.g., 'FRIDGE')
     * @return string
     */
    private function translateProperty(string $property, string $resource, ?string $location): string
    {
        // Convert to snake_case for lookup
        $propertySnake = $this->camelToSnake($property);
        $resourceSnake = $this->camelToSnake($resource);
        
        // Special naming for timer properties (timer, sleep_timer, sleepTimer)
        if (preg_match('/^(timer|sleep_timer)$/i', $resourceSnake)) {
            $isSleepTimer = (stripos($resourceSnake, 'sleep') !== false);
            
            // Special cases: Timer objects (without hour/minute granularity)
            // sleepTimer.relativeStopTimer → "Sleep Timer"
            if ($isSleepTimer && preg_match('/relative.*stop.*timer/i', $property)) {
                return 'Sleep Timer';
            }
            // timer.relativeStartTimer → "Timer Relativ Start"
            if (!$isSleepTimer && preg_match('/relative.*start.*timer/i', $property)) {
                return 'Timer Relativ Start';
            }
            // timer.relativeStopTimer → "Timer Relativ Stop"
            if (!$isSleepTimer && preg_match('/relative.*stop.*timer/i', $property)) {
                return 'Timer Relativ Stop';
            }
            // timer.absoluteStartTimer → "Timer Absolut Start"
            if (!$isSleepTimer && preg_match('/absolute.*start.*timer/i', $property)) {
                return 'Timer Absolut Start';
            }
            // timer.absoluteStopTimer → "Timer Absolut Stop"
            if (!$isSleepTimer && preg_match('/absolute.*stop.*timer/i', $property)) {
                return 'Timer Absolut Stop';
            }
            
            // Determine timer type label for hour/minute properties
            $typeLabel = '';
            if (stripos($property, 'relative') !== false) {
                $typeLabel = 'Relativ ';
            } elseif (stripos($property, 'absolute') !== false) {
                $typeLabel = 'Absolut ';
            }
            
            // Match minute-based timers (minute, minutes)
            if (preg_match('/minutes?.*to.*start/i', $property)) {
                return $isSleepTimer 
                    ? 'Startzeit Sleeptimer ' . $typeLabel . '(Minuten)'
                    : 'Startzeit ' . $typeLabel . '(Minuten)';
            }
            // Match hour-based timers (hour, hours)
            if (preg_match('/hours?.*to.*start/i', $property)) {
                return $isSleepTimer 
                    ? 'Startzeit Sleeptimer ' . $typeLabel . '(Stunden)'
                    : 'Startzeit ' . $typeLabel . '(Stunden)';
            }
            // Match minute-based stop timers
            if (preg_match('/minutes?.*to.*stop/i', $property)) {
                return $isSleepTimer 
                    ? 'Stoppzeit Sleeptimer ' . $typeLabel . '(Minuten)'
                    : 'Stoppzeit ' . $typeLabel . '(Minuten)';
            }
            // Match hour-based stop timers
            if (preg_match('/hours?.*to.*stop/i', $property)) {
                return $isSleepTimer 
                    ? 'Stoppzeit Sleeptimer ' . $typeLabel . '(Stunden)'
                    : 'Stoppzeit ' . $typeLabel . '(Stunden)';
            }
        }
        
        // Get translations in configured language (default: German)
        $translations = ThinQGenericProperties::getTranslations($this->language);
        
        // Generic property names that need resource context
        $genericProperties = ['current_state', 'state', 'status', 'value', 'enabled', 'mode'];
        
        // If property is too generic, prepend resource name for context
        if (in_array($propertySnake, $genericProperties, true)) {
            // Use resource name for better context
            if (isset($translations[$resourceSnake])) {
                $name = $translations[$resourceSnake];
            } else {
                $name = $this->humanize($resource, null);
            }
            
            // Prepend location if not MAIN
            if ($location !== null && strtoupper($location) !== 'MAIN') {
                return ucfirst(strtolower($location)) . ' ' . $name;
            }
            
            return $name;
        }
        
        // Normal property lookup
        if (isset($translations[$propertySnake])) {
            $name = $translations[$propertySnake];
            
            // Prepend location if not MAIN
            if ($location !== null && strtoupper($location) !== 'MAIN') {
                return ucfirst(strtolower($location)) . ' ' . $name;
            }
            
            return $name;
        }
        
        // Fallback: Humanize
        return $this->humanize($property, $location);
    }
    
    /**
     * Convert snake_case or camelCase to readable format
     * 
     * @param string $text
     * @param string|null $location
     * @return string
     */
    private function humanize(string $text, ?string $location = null): string
    {
        // Convert camelCase to snake_case first
        $text = preg_replace('/([a-z])([A-Z])/', '$1_$2', $text) ?? $text;
        
        // Replace underscores with spaces
        $text = str_replace('_', ' ', $text);
        
        // Capitalize words
        $readable = ucwords(strtolower($text));
        
        // Prepend location
        if ($location !== null && strtoupper($location) !== 'MAIN') {
            return ucfirst(strtolower($location)) . ' ' . $readable;
        }
        
        return $readable;
    }
    
    /**
     * Set language for translations
     * 
     * @param string $lang Language code ('de', 'en')
     */
    public function setLanguage(string $lang): void
    {
        $this->language = $lang;
    }
}
