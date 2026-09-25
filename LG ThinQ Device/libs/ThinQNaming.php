<?php

declare(strict_types=1);

require_once __DIR__ . '/ThinQGenericProperties.php';
require_once __DIR__ . '/ThinQEnumTranslator.php';

/**
 * Names and captions of the capability variables. Names are English in the source and reach other
 * languages through locale.json (the module's Translate()); the prefix of a zone, part or element
 * is LG's identifier and stays as it is. Captions of enumerations depend on the property (POWER_OFF
 * is "Ausschalten" as a command and "Ausgeschaltet" as a state), so ThinQEnumTranslator supplies
 * them in the language Symcon runs in.
 */
final class ThinQNaming
{
    /** Properties too generic to name a variable; their resource names it (runState.currentState) */
    private const GENERIC = ['current_state', 'state', 'status', 'value', 'enabled', 'mode'];

    private readonly string $language;

    /** @param Closure(string): string|null $translate the module's Translate() */
    public function __construct(private readonly ?Closure $translate = null, ?string $language = null)
    {
        $this->language = $language ?? self::systemLanguage();
    }

    /** 'de' or 'en' from Symcon's system language (de_DE → de); every other language gets English */
    public static function systemLanguage(): string
    {
        $system = function_exists('IPS_GetSystemLanguage') ? (string)@IPS_GetSystemLanguage() : '';
        return strtolower(substr($system, 0, 2)) === 'de' ? 'de' : 'en';
    }

    public function language(): string
    {
        return $this->language;
    }

    public function t(string $text): string
    {
        return $this->translate !== null ? ($this->translate)($text) : $text;
    }

    /** Name of a property in Symcon's language; an element other than MAIN (FRIDGE) is put in front */
    public function property(string $property, string $resource, ?string $location): string
    {
        $timer = self::timerLabel($property, $resource);
        if ($timer !== null) {
            return $this->t($timer);
        }
        return self::elementPrefix($location) . $this->t(self::label($property, $resource));
    }

    /** Caption of an enumeration value in Symcon's language */
    public function caption(string $property, string $value): string
    {
        return ThinQEnumTranslator::translate($property, $value, $this->language);
    }

    /** English name of a property: from ThinQGenericProperties, else its LG name in words */
    public static function label(string $property, string $resource): string
    {
        $propertySnake = self::snake($property);
        if (in_array($propertySnake, self::GENERIC, true)) {
            return ThinQGenericProperties::NAMES[self::snake($resource)] ?? self::humanize($resource);
        }
        return ThinQGenericProperties::NAMES[$propertySnake] ?? self::humanize($property);
    }

    /** FRIDGE → "Fridge ", SWITCH_1 → "Switch_1 "; nothing for MAIN */
    public static function elementPrefix(?string $location): string
    {
        return $location !== null && strtoupper($location) !== 'MAIN' ? ucfirst(strtolower($location)) . ' ' : '';
    }

    /** LEFT_FRONT → "Left front " */
    public static function zonePrefix(string $zone): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $zone))) . ' ';
    }

    /** washer → "Washer " */
    public static function partPrefix(string $part): string
    {
        return ucfirst($part) . ' ';
    }

    /**
     * Timers (timer, sleepTimer) name their properties by what they set: relativeHourToStart is
     * "Start Time Relative (Hours)", sleepTimer.relativeStopTimer the "Sleep Timer" itself.
     */
    private static function timerLabel(string $property, string $resource): ?string
    {
        $resourceSnake = self::snake($resource);
        if (preg_match('/^(timer|sleep_timer)$/i', $resourceSnake) !== 1) {
            return null;
        }
        $sleep = stripos($resourceSnake, 'sleep') !== false;
        $fixed = $sleep
            ? ['/relative.*stop.*timer/i' => 'Sleep Timer']
            : ['/relative.*start.*timer/i' => 'Timer Relative Start', '/relative.*stop.*timer/i' => 'Timer Relative Stop',
                '/absolute.*start.*timer/i' => 'Timer Absolute Start', '/absolute.*stop.*timer/i' => 'Timer Absolute Stop'];
        foreach ($fixed as $pattern => $label) {
            if (preg_match($pattern, $property) === 1) {
                return $label;
            }
        }
        $type = stripos($property, 'relative') !== false ? 'Relative ' : (stripos($property, 'absolute') !== false ? 'Absolute ' : '');
        foreach (['/minutes?.*to.*start/i' => 'Start Time %s(Minutes)', '/hours?.*to.*start/i' => 'Start Time %s(Hours)',
            '/minutes?.*to.*stop/i' => 'Stop Time %s(Minutes)', '/hours?.*to.*stop/i' => 'Stop Time %s(Hours)'] as $pattern => $label) {
            if (preg_match($pattern, $property) === 1) {
                return ($sleep ? 'Sleep Timer ' : '') . sprintf($label, $type);
            }
        }
        return null;
    }

    /** remoteControlEnabled → "Remote Control Enabled" */
    public static function humanize(string $text): string
    {
        $text = preg_replace('/([a-z])([A-Z])/', '$1_$2', $text) ?? $text;
        return ucwords(strtolower(str_replace('_', ' ', $text)));
    }

    private static function snake(string $text): string
    {
        return strtolower((string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $text));
    }
}
