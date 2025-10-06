<?php

declare(strict_types=1);

/**
 * ThinQGenericProperties
 * 
 * Translations for generic property names used across multiple LG ThinQ devices.
 * Auto-Discovery handles type mapping and presentation inference automatically.
 * This class only provides human-readable translations for property names.
 */
class ThinQGenericProperties
{
    /**
     * Get translations for a specific language
     * 
     * @param string $lang Language code ('de' or 'en')
     * @return array<string, string>
     */
    public static function getTranslations(string $lang = 'de'): array
    {
        $translations = [
            'de' => [
                // Remote & Operations
                'remote_control_enabled' => 'Fernsteuerung',
                'operation' => 'Power',
                
                // State
                'current_state' => 'Status',
                'run_state' => 'Betriebszustand',
                'door_state' => 'Türstatus',
                
                // Timer - Remain
                'remain_hour' => 'Verbleibende Stunden',
                'remain_minute' => 'Verbleibende Minuten',
                'remain_second' => 'Verbleibende Sekunden',
                
                // Timer - Total
                'total_hour' => 'Gesamtdauer (Stunden)',
                'total_minute' => 'Gesamtdauer (Minuten)',
                
                // Timer - Relative Start
                'relative_hour_to_start' => 'Startzeit (Stunden)',
                'relative_minute_to_start' => 'Startzeit (Minuten)',
                
                // Timer - Relative Stop
                'relative_hour_to_stop' => 'Stoppzeit (Stunden)',
                'relative_minute_to_stop' => 'Stoppzeit (Minuten)',
                
                // Temperature
                'target_temperature' => 'Zieltemperatur',
                'current_temperature' => 'Ist-Temperatur',
                'cool_target_temperature' => 'Kühl-Zieltemperatur',
                'heat_target_temperature' => 'Heiz-Zieltemperatur',
                'min_target_temperature' => 'Min. Temperatur',
                'max_target_temperature' => 'Max. Temperatur',
                'temperature_unit' => 'Temperatur-Einheit',
                
                // Humidity
                'current_humidity' => 'Aktuelle Luftfeuchtigkeit',
                'target_humidity' => 'Ziel-Luftfeuchtigkeit',
                
                // Air Quality
                'pm1' => 'Feinstaub PM1',
                'pm2' => 'Feinstaub PM2.5',
                'pm10' => 'Feinstaub PM10',
                'total_pollution' => 'Gesamtverschmutzung',
                'total_pollution_level' => 'Verschmutzungsstufe',
                'monitoring_enabled' => 'Überwachung aktiv',
                
                // Air Flow
                'wind_strength' => 'Windstärke',
                
                // Battery
                'battery_level' => 'Akkustand',
                'battery_percent' => 'Akku',
                
                // Display
                'display_light' => 'Display-Beleuchtung',
                
                // Power
                'power_save_enabled' => 'Energiesparmodus',
                
                // Filter
                'filter_remain_percent' => 'Filter-Restlaufzeit',
                'used_time' => 'Nutzungszeit',
                
                // Operation Modes
                'operation_mode' => 'Betriebsmodus',
                'air_con_operation_mode' => 'Power',
                'current_job_mode' => 'Betriebsmodus',
                
                // Wind Direction
                'air_guide_wind' => 'Luftführung',
                'swirl_wind' => 'Wirbelwind',
                'high_ceiling_wind' => 'Hohe Decke',
                'concentration_wind' => 'Konzentrierter Wind',
                'auto_fit_wind' => 'Auto-Anpassung',
                'forest_wind' => 'Waldwind',
                'rotate_up_down' => 'Vertikal schwenken',
                'rotate_left_right' => 'Horizontal schwenken',
            ],
            'en' => [
                // Remote & Operations
                'remote_control_enabled' => 'Remote Control',
                'operation' => 'Power',
                
                // State
                'current_state' => 'Current State',
                'run_state' => 'Run State',
                'door_state' => 'Door State',
                
                // Timer - Remain
                'remain_hour' => 'Remaining Hours',
                'remain_minute' => 'Remaining Minutes',
                'remain_second' => 'Remaining Seconds',
                
                // Timer - Total
                'total_hour' => 'Total Hours',
                'total_minute' => 'Total Minutes',
                
                // Timer - Relative Start
                'relative_hour_to_start' => 'Start Time (Hours)',
                'relative_minute_to_start' => 'Start Time (Minutes)',
                
                // Timer - Relative Stop
                'relative_hour_to_stop' => 'Stop Time (Hours)',
                'relative_minute_to_stop' => 'Stop Time (Minutes)',
                
                // Temperature
                'target_temperature' => 'Target Temperature',
                'current_temperature' => 'Current Temperature',
                'cool_target_temperature' => 'Cool Target Temperature',
                'heat_target_temperature' => 'Heat Target Temperature',
                'min_target_temperature' => 'Min. Temperature',
                'max_target_temperature' => 'Max. Temperature',
                'temperature_unit' => 'Temperature Unit',
                
                // Humidity
                'current_humidity' => 'Current Humidity',
                'target_humidity' => 'Target Humidity',
                
                // Air Quality
                'pm1' => 'PM1',
                'pm2' => 'PM2.5',
                'pm10' => 'PM10',
                'total_pollution' => 'Total Pollution',
                'total_pollution_level' => 'Pollution Level',
                'monitoring_enabled' => 'Monitoring',
                
                // Air Flow
                'wind_strength' => 'Fan Speed',
                
                // Battery
                'battery_level' => 'Battery Level',
                'battery_percent' => 'Battery',
                
                // Display
                'display_light' => 'Display Light',
                
                // Power
                'power_save_enabled' => 'Power Save',
                
                // Filter
                'filter_remain_percent' => 'Filter Remaining',
                'used_time' => 'Used Time',
                
                // Operation Modes
                'operation_mode' => 'Operation Mode',
                'air_con_operation_mode' => 'Power',
                'current_job_mode' => 'Operation Mode',
                
                // Wind Direction
                'air_guide_wind' => 'Air Guide',
                'swirl_wind' => 'Swirl Wind',
                'high_ceiling_wind' => 'High Ceiling',
                'concentration_wind' => 'Concentration Wind',
                'auto_fit_wind' => 'Auto Fit',
                'forest_wind' => 'Forest Wind',
                'rotate_up_down' => 'Rotate Up Down',
                'rotate_left_right' => 'Rotate Left Right',
            ]
        ];
        
        return $translations[$lang] ?? [];
    }
}
