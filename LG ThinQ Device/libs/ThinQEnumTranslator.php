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
            'SLOW' => ['de' => 'Sehr niedrig', 'en' => 'Slow'],
            'LOW' => ['de' => 'Niedrig', 'en' => 'Low'],
            'MID' => ['de' => 'Mittel', 'en' => 'Medium'],
            'HIGH' => ['de' => 'Hoch', 'en' => 'High'],
            'AUTO' => ['de' => 'Automatik', 'en' => 'Auto'],
            'POWER' => ['de' => 'Maximum', 'en' => 'Power'],
            'TURBO' => ['de' => 'Turbo', 'en' => 'Turbo'],
            'WIND_1' => ['de' => 'Stufe 1', 'en' => 'Level 1'],
            'WIND_2' => ['de' => 'Stufe 2', 'en' => 'Level 2'],
            'WIND_3' => ['de' => 'Stufe 3', 'en' => 'Level 3'],
            'WIND_4' => ['de' => 'Stufe 4', 'en' => 'Level 4'],
            'WIND_5' => ['de' => 'Stufe 5', 'en' => 'Level 5'],
            'WIND_6' => ['de' => 'Stufe 6', 'en' => 'Level 6'],
            'WIND_7' => ['de' => 'Stufe 7', 'en' => 'Level 7'],
            'WIND_8' => ['de' => 'Stufe 8', 'en' => 'Level 8'],
            'WIND_9' => ['de' => 'Stufe 9', 'en' => 'Level 9'],
            'WIND_10' => ['de' => 'Stufe 10', 'en' => 'Level 10']
        ],
        // Wind Strength Detail / Level (fine-grained, device-specific)
        'wind_strength_detail' => [
            'SLOW' => ['de' => 'Sehr niedrig', 'en' => 'Slow'],
            'LOW' => ['de' => 'Niedrig', 'en' => 'Low'],
            'MID' => ['de' => 'Mittel', 'en' => 'Medium'],
            'HIGH' => ['de' => 'Hoch', 'en' => 'High'],
            'AUTO' => ['de' => 'Automatik', 'en' => 'Auto'],
            'POWER' => ['de' => 'Maximum', 'en' => 'Power'],
            'WIND_1' => ['de' => 'Stufe 1', 'en' => 'Level 1'],
            'WIND_2' => ['de' => 'Stufe 2', 'en' => 'Level 2'],
            'WIND_3' => ['de' => 'Stufe 3', 'en' => 'Level 3'],
            'WIND_4' => ['de' => 'Stufe 4', 'en' => 'Level 4'],
            'WIND_5' => ['de' => 'Stufe 5', 'en' => 'Level 5'],
            'WIND_6' => ['de' => 'Stufe 6', 'en' => 'Level 6'],
            'WIND_7' => ['de' => 'Stufe 7', 'en' => 'Level 7'],
            'WIND_8' => ['de' => 'Stufe 8', 'en' => 'Level 8'],
            'WIND_9' => ['de' => 'Stufe 9', 'en' => 'Level 9'],
            'WIND_10' => ['de' => 'Stufe 10', 'en' => 'Level 10']
        ],
        // Wind Strength Level (fine-grained, Level 2 and above)
        'wind_strength_level' => [
            'SLOW' => ['de' => 'Sehr niedrig', 'en' => 'Slow'],
            'LOW' => ['de' => 'Niedrig', 'en' => 'Low'],
            'MID' => ['de' => 'Mittel', 'en' => 'Medium'],
            'HIGH' => ['de' => 'Hoch', 'en' => 'High'],
            'AUTO' => ['de' => 'Automatik', 'en' => 'Auto'],
            'POWER' => ['de' => 'Maximum', 'en' => 'Power'],
            'WIND_1' => ['de' => 'Stufe 1', 'en' => 'Level 1'],
            'WIND_2' => ['de' => 'Stufe 2', 'en' => 'Level 2'],
            'WIND_3' => ['de' => 'Stufe 3', 'en' => 'Level 3'],
            'WIND_4' => ['de' => 'Stufe 4', 'en' => 'Level 4'],
            'WIND_5' => ['de' => 'Stufe 5', 'en' => 'Level 5'],
            'WIND_6' => ['de' => 'Stufe 6', 'en' => 'Level 6'],
            'WIND_7' => ['de' => 'Stufe 7', 'en' => 'Level 7'],
            'WIND_8' => ['de' => 'Stufe 8', 'en' => 'Level 8'],
            'WIND_9' => ['de' => 'Stufe 9', 'en' => 'Level 9'],
            'WIND_10' => ['de' => 'Stufe 10', 'en' => 'Level 10']
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
        
        // Job Modes (AC + Water Heater + universal)
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
            'CUSTOM' => ['de' => 'Benutzerdefiniert', 'en' => 'Custom'],
            // Water Heater modes (DEVICE_WATER_HEATER)
            'HEAT_PUMP' => ['de' => 'Wärmepumpe', 'en' => 'Heat Pump'],
            'VACATION' => ['de' => 'Urlaubsmodus', 'en' => 'Vacation'],
            'TURBO' => ['de' => 'Turbo', 'en' => 'Turbo'],
        ],

        // Water Heater Operation Mode
        'water_heater_operation_mode' => [
            'POWER_ON' => ['de' => 'Warmwasser Ein', 'en' => 'Hot Water On'],
            'POWER_OFF' => ['de' => 'Warmwasser Aus', 'en' => 'Hot Water Off'],
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
     * @param string $lang Language code ('de' or 'en', see ThinQNaming::systemLanguage())
     * @return string Translated value or humanized fallback
     */
    public static function translate(string $property, string $value, string $lang): string
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
