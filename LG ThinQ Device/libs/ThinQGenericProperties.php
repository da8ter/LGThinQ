<?php

declare(strict_types=1);

/**
 * English names of properties used across many LG ThinQ devices, keyed by the property in
 * snake_case. German and other languages come from locale.json (see ThinQNaming); properties
 * missing here are named after their LG name (targetTemperatureC → "Target Temperature C").
 */
final class ThinQGenericProperties
{
    public const NAMES = [
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
        'wind_strength_level' => 'Wind Strength Level',
        'wind_strength_detail' => 'Wind Strength Detail',

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
    ];
}
