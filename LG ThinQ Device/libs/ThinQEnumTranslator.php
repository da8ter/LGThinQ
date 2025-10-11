<?php

declare(strict_types=1);

/**
 * ThinQEnumTranslator
 * 
 * Translates LG ThinQ API enum values to human-readable strings.
 * Based on common enum values from official SDK documentation.
 */
class ThinQEnumTranslator
{
    /**
     * Enum translation maps
     * 
     * @var array<string, array<string, array<string, string>>>
     */
    private static array $ENUM_MAPS = [
        // Washer/Dryer Operation Modes
        'washer_operation_mode' => [
            'START' => ['de' => 'Starten', 'en' => 'Start'],
            'STOP' => ['de' => 'Stoppen', 'en' => 'Stop'],
            'POWER_OFF' => ['de' => 'Ausschalten', 'en' => 'Power Off'],
            'PAUSE' => ['de' => 'Pausieren', 'en' => 'Pause'],
            'WAKE_UP' => ['de' => 'Aufwecken', 'en' => 'Wake Up']
        ],
        'dryer_operation_mode' => [
            'START' => ['de' => 'Starten', 'en' => 'Start'],
            'STOP' => ['de' => 'Stoppen', 'en' => 'Stop'],
            'POWER_OFF' => ['de' => 'Ausschalten', 'en' => 'Power Off'],
            'WAKE_UP' => ['de' => 'Aufwecken', 'en' => 'Wake Up']
        ],
        
        // Run States (universal)
        'current_state' => [
            'RUNNING' => ['de' => 'Läuft', 'en' => 'Running'],
            'PAUSE' => ['de' => 'Pausiert', 'en' => 'Paused'],
            'END' => ['de' => 'Fertig', 'en' => 'Finished'],
            'COMPLETE' => ['de' => 'Abgeschlossen', 'en' => 'Complete'],
            'ERROR' => ['de' => 'Fehler', 'en' => 'Error'],
            'INITIAL' => ['de' => 'Bereit', 'en' => 'Ready'],
            'RESERVED' => ['de' => 'Reserviert', 'en' => 'Reserved'],
            'RINSING' => ['de' => 'Spülen', 'en' => 'Rinsing'],
            'SPINNING' => ['de' => 'Schleudern', 'en' => 'Spinning'],
            'DRYING' => ['de' => 'Trocknen', 'en' => 'Drying'],
            'COOLING' => ['de' => 'Abkühlen', 'en' => 'Cooling'],
            'COOL_DOWN' => ['de' => 'Abkühlen', 'en' => 'Cool Down'],
            'SOAKING' => ['de' => 'Einweichen', 'en' => 'Soaking'],
            'PREWASH' => ['de' => 'Vorwäsche', 'en' => 'Pre-wash'],
            'DETECTING' => ['de' => 'Erkennung', 'en' => 'Detecting'],
            'FIRMWARE' => ['de' => 'Firmware-Update', 'en' => 'Firmware Update'],
            'FIRMWARE_UPDATE' => ['de' => 'Firmware-Update', 'en' => 'Firmware Update'],
            'POWER_OFF' => ['de' => 'Ausgeschaltet', 'en' => 'Power Off'],
            'OFF' => ['de' => 'Aus', 'en' => 'Off'],
            'ON' => ['de' => 'An', 'en' => 'On'],
            'REFRESHING' => ['de' => 'Auffrischen', 'en' => 'Refreshing'],
            'STEAM_SOFTENING' => ['de' => 'Dampferweichen', 'en' => 'Steam Softening'],
            'RINSE_HOLD' => ['de' => 'Spülstopp', 'en' => 'Rinse Hold'],
            'WRINKLE_CARE' => ['de' => 'Knitterschutz', 'en' => 'Wrinkle Care'],
            'SLEEP' => ['de' => 'Schlafmodus', 'en' => 'Sleep']
        ],
        
        // Air Conditioner Operation Modes
        'air_con_operation_mode' => [
            'POWER_ON' => ['de' => 'Einschalten', 'en' => 'Power On'],
            'POWER_OFF' => ['de' => 'Ausschalten', 'en' => 'Power Off'],
            'COOL' => ['de' => 'Kühlen', 'en' => 'Cool'],
            'HEAT' => ['de' => 'Heizen', 'en' => 'Heat'],
            'AUTO' => ['de' => 'Automatik', 'en' => 'Auto'],
            'FAN' => ['de' => 'Lüfter', 'en' => 'Fan'],
            'DRY' => ['de' => 'Entfeuchten', 'en' => 'Dry']
        ],
        
        // Air Purifier Operation Modes
        'air_purifier_operation_mode' => [
            'POWER_ON' => ['de' => 'Einschalten', 'en' => 'Power On'],
            'POWER_OFF' => ['de' => 'Ausschalten', 'en' => 'Power Off']
        ],
        
        // Door States
        'door_state' => [
            'OPEN' => ['de' => 'Offen', 'en' => 'Open'],
            'CLOSE' => ['de' => 'Geschlossen', 'en' => 'Closed'],
            'CLOSED' => ['de' => 'Geschlossen', 'en' => 'Closed']
        ],
        
        // Wind Strength (Fan Speed)
        'wind_strength' => [
            'LOW' => ['de' => 'Niedrig', 'en' => 'Low'],
            'MID' => ['de' => 'Mittel', 'en' => 'Medium'],
            'HIGH' => ['de' => 'Hoch', 'en' => 'High'],
            'AUTO' => ['de' => 'Automatik', 'en' => 'Auto'],
            'POWER' => ['de' => 'Maximum', 'en' => 'Power'],
            'TURBO' => ['de' => 'Turbo', 'en' => 'Turbo']
        ],
        
        // Battery Levels
        'battery_level' => [
            'HIGH' => ['de' => 'Hoch', 'en' => 'High'],
            'MID' => ['de' => 'Mittel', 'en' => 'Medium'],
            'LOW' => ['de' => 'Niedrig', 'en' => 'Low'],
            'CHARGING' => ['de' => 'Lädt', 'en' => 'Charging']
        ],
        
        // Temperature Units
        'temperature_unit' => [
            'CELSIUS' => ['de' => 'Celsius', 'en' => 'Celsius'],
            'FAHRENHEIT' => ['de' => 'Fahrenheit', 'en' => 'Fahrenheit'],
            'C' => ['de' => '°C', 'en' => '°C'],
            'F' => ['de' => '°F', 'en' => '°F']
        ],
        
        // Job Modes (AC comprehensive)
        'current_job_mode' => [
            'COOL' => ['de' => 'Kühlen', 'en' => 'Cool'],
            'HEAT' => ['de' => 'Heizen', 'en' => 'Heat'],
            'AUTO' => ['de' => 'Automatik', 'en' => 'Auto'],
            'FAN' => ['de' => 'Nur Lüfter', 'en' => 'Fan Only'],
            'DRY' => ['de' => 'Entfeuchten', 'en' => 'Dry'],
            'AIR_DRY' => ['de' => 'Entfeuchten', 'en' => 'Air Dry'],
            'AIR_CLEAN' => ['de' => 'Luftreinigung', 'en' => 'Air Clean'],
            'ACO' => ['de' => 'ACO-Modus', 'en' => 'ACO Mode'],
            'AROMA' => ['de' => 'Aroma', 'en' => 'Aroma'],
            'MANUAL' => ['de' => 'Manuell', 'en' => 'Manual'],
            'SLEEP' => ['de' => 'Schlafmodus', 'en' => 'Sleep'],
            'CUSTOM' => ['de' => 'Benutzerdefiniert', 'en' => 'Custom']
        ],
        
        // Air Clean Operation
        'air_clean_operation_mode' => [
            'START' => ['de' => 'Starten', 'en' => 'Start'],
            'STOP' => ['de' => 'Stoppen', 'en' => 'Stop']
        ],
        
        // Air Quality Pollution Levels
        'total_pollution_level' => [
            'INVALID' => ['de' => 'Ungültig', 'en' => 'Invalid'],
            'GOOD' => ['de' => 'Gut', 'en' => 'Good'],
            'NORMAL' => ['de' => 'Normal', 'en' => 'Normal'],
            'BAD' => ['de' => 'Schlecht', 'en' => 'Bad'],
            'VERY_BAD' => ['de' => 'Sehr schlecht', 'en' => 'Very Bad']
        ],
        
        // Odor Levels
        'odor_level' => [
            'GOOD' => ['de' => 'Gut', 'en' => 'Good'],
            'NORMAL' => ['de' => 'Normal', 'en' => 'Normal'],
            'BAD' => ['de' => 'Schlecht', 'en' => 'Bad']
        ],
        
        // Monitoring Enabled
        'monitoring_enabled' => [
            'ALWAYS' => ['de' => 'Immer', 'en' => 'Always'],
            'ON_WORKING' => ['de' => 'Bei Betrieb', 'en' => 'On Working']
        ],
        
        // Display Light
        'display_light' => [
            'ON' => ['de' => 'An', 'en' => 'On'],
            'OFF' => ['de' => 'Aus', 'en' => 'Off']
        ],
        
        // Timer Status
        'timer_status' => [
            'SET' => ['de' => 'Gesetzt', 'en' => 'Set'],
            'UNSET' => ['de' => 'Nicht gesetzt', 'en' => 'Unset']
        ]
    ];
    
    /**
     * Translate enum value
     * 
     * @param string $property Property name (e.g., 'current_state')
     * @param string $value Enum value (e.g., 'RUNNING')
     * @param string $lang Language code ('de' or 'en')
     * @return string Translated value or humanized fallback
     */
    public static function translate(string $property, string $value, string $lang = 'de'): string
    {
        $propKey = self::normalizePropertyName($property);
        // Try normalized property first
        if (isset(self::$ENUM_MAPS[$propKey][$value][$lang])) {
            return self::$ENUM_MAPS[$propKey][$value][$lang];
        }
        // Then try raw property key for backward compatibility
        if (isset(self::$ENUM_MAPS[$property][$value])) {
            $translations = self::$ENUM_MAPS[$property][$value];
            return $translations[$lang] ?? $translations['en'] ?? self::humanize($value);
        }
        // Check if normalized property exists with the value (any language)
        if (isset(self::$ENUM_MAPS[$propKey][$value])) {
            $translations = self::$ENUM_MAPS[$propKey][$value];
            return $translations[$lang] ?? $translations['en'] ?? self::humanize($value);
        }
        // Fallback: Humanize the value
        return self::humanize($value);
    }
    
    /**
     * Convert UPPER_SNAKE_CASE to readable format
     * 
     * @param string $value
     * @return string
     */
    private static function humanize(string $value): string
    {
        // Replace underscores with spaces
        $readable = str_replace('_', ' ', $value);
        
        // Capitalize first letter of each word, lowercase rest
        return ucwords(strtolower($readable));
    }
    
    /**
     * Get all translations for a specific property
     * 
     * @param string $property Property name
     * @param string $lang Language code
     * @return array<string, string> Map of value => translated label
     */
    public static function getTranslationsForProperty(string $property, string $lang = 'de'): array
    {
        $propKey = self::normalizePropertyName($property);
        if (!isset(self::$ENUM_MAPS[$propKey])) {
            return [];
        }
        
        $translations = [];
        foreach (self::$ENUM_MAPS[$propKey] as $value => $langs) {
            $translations[$value] = $langs[$lang] ?? $langs['en'] ?? self::humanize($value);
        }
        
        return $translations;
    }
    
    /**
     * Add custom enum translation
     * 
     * @param string $property Property name
     * @param string $value Enum value
     * @param string $translation_de German translation
     * @param string $translation_en English translation
     */
    public static function addTranslation(
        string $property, 
        string $value, 
        string $translation_de, 
        string $translation_en
    ): void {
        $propKey = self::normalizePropertyName($property);
        if (!isset(self::$ENUM_MAPS[$propKey])) {
            self::$ENUM_MAPS[$propKey] = [];
        }
        
        self::$ENUM_MAPS[$propKey][$value] = [
            'de' => $translation_de,
            'en' => $translation_en
        ];
    }

    /**
     * Normalize property name to snake_case key used in ENUM_MAPS.
     */
    private static function normalizePropertyName(string $property): string
    {
        // Replace hyphens with underscore and insert underscores before capitals
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', str_replace('-', '_', $property));
        $snake = strtolower((string)$snake);
        return $snake;
    }
}
