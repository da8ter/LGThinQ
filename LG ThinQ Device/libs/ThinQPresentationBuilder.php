<?php

declare(strict_types=1);

/**
 * ThinQPresentationBuilder
 *
 * Extracted from LG ThinQ Device/module.php.
 * Builds and applies the presentation of a variable for the kinds the parser produces:
 * switch, slider, buttons and value.
 */
class ThinQPresentationBuilder
{
    private const PRES_VALUE    = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const PRES_SWITCH   = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    private const PRES_SLIDER   = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const PRES_BUTTONS  = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

    /** @var callable */
    private $translateCallback;
    /** @var callable */
    private $debugCallback;

    public function __construct(
        private int $instanceId,
        callable $translateCallback,
        callable $debugCallback
    ) {
        $this->translateCallback = $translateCallback;
        $this->debugCallback = $debugCallback;
    }

    private function t(string $s): string
    {
        return ($this->translateCallback)($s);
    }

    private function dbg(string $tag, string $msg): void
    {
        ($this->debugCallback)($tag, $msg);
    }

    /**
     * The presentation array of a plan entry for MaintainVariable (OPTIONS/INTERVALS JSON-encoded),
     * [] when the entry brings none.
     *
     * @param array<string, mixed> $presentation
     * @param array<string, mixed> $flatProfile
     * @return array<string, mixed>
     */
    public function build(int $vid, string $ident, array $presentation, array $flatProfile, string $type): array
    {
        $kind = strtolower((string)($presentation['kind'] ?? ''));
        if ($kind === '') {
            return [];
        }

        $payload = [];
        if ($kind === 'switch') {
            // Symcon's switch has no captions (only icons, glow and usage type); the plan's captionOn/Off are not sent
            $payload['PRESENTATION'] = self::PRES_SWITCH;
            if (array_key_exists('usageType', $presentation) || array_key_exists('usage_type', $presentation)) {
                $payload['USAGE_TYPE'] = (int)($presentation['usageType'] ?? $presentation['usage_type']);
            }
            if (isset($presentation['iconTrue']) && (string)$presentation['iconTrue'] !== '') {
                $payload['ICON_TRUE'] = (string)$presentation['iconTrue'];
                if (isset($presentation['iconFalse']) && (string)$presentation['iconFalse'] !== '') {
                    $payload['ICON_FALSE'] = (string)$presentation['iconFalse'];
                    $payload['USE_ICON_FALSE'] = true;
                }
            }
        } elseif ($kind === 'slider') {
            $payload['PRESENTATION'] = self::PRES_SLIDER;
            $range = $presentation['range'] ?? [];
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;
            $step = $range['step'] ?? null;
            if (is_numeric($min)) { $payload['MIN'] = (float)$min; }
            if (is_numeric($max)) { $payload['MAX'] = (float)$max; }
            if (!is_numeric($step) || (float)$step === 0.0) { $step = 1.0; }
            $isInteger = ThinQValue::typeOf($vid) === VARIABLETYPE_INTEGER; // an older INTEGER variable of a 0.5 range
            $payload['STEP_SIZE'] = $isInteger ? max(1.0, (float)$step) : (float)$step;
            if (isset($presentation['suffix'])) {
                $payload['SUFFIX'] = $this->t((string)$presentation['suffix']);
            } elseif (isset($range['suffix'])) {
                $payload['SUFFIX'] = $this->t((string)$range['suffix']);
            }
            if ($isInteger) {
                $payload['DIGITS'] = 0;
            } elseif (isset($presentation['digits'])) {
                $payload['DIGITS'] = (int)$presentation['digits'];
            } elseif (isset($range['digits'])) {
                $payload['DIGITS'] = (int)$range['digits'];
            } else {
                $stepVal = (float)$payload['STEP_SIZE'];
                $payload['DIGITS'] = ($stepVal >= 1.0) ? 0 : (($stepVal >= 0.5) ? 1 : 2);
            }
            if (array_key_exists('usageType', $presentation) || array_key_exists('usage_type', $presentation)) {
                $payload['USAGE_TYPE'] = (int)($presentation['usageType'] ?? $presentation['usage_type']);
            }
            if (array_key_exists('gradientType', $presentation) || array_key_exists('gradient_type', $presentation)) {
                $payload['GRADIENT_TYPE'] = (int)($presentation['gradientType'] ?? $presentation['gradient_type']);
            }
        } elseif ($kind === 'buttons') {
            $payload['PRESENTATION'] = self::PRES_BUTTONS;
            $payload['ICON'] = '';
            $payload['LAYOUT'] = isset($presentation['layout']) ? (int)$presentation['layout'] : 1;
            $options = [];
            if (isset($presentation['options']) && is_array($presentation['options'])) {
                foreach ($presentation['options'] as $op) {
                    if (!is_array($op)) continue;
                    if (!array_key_exists('value', $op) || !array_key_exists('caption', $op)) continue;
                    $value = $op['value'];
                    if ($type === 'STRING' && !is_string($value)) { $value = (string)$value; }
                    elseif ($type !== 'STRING' && !is_int($value)) { $value = (int)$value; }
                    $options[] = [
                        'Value'      => $value,
                        'Caption'    => $this->t((string)$op['caption']),
                        'IconActive' => false,
                        'IconValue'  => '',
                        'Color'      => isset($op['color']) ? (int)$op['color'] : -1
                    ];
                }
            }
            if (!empty($options)) { $payload['OPTIONS'] = $options; }
        } elseif ($kind === 'value') {
            $payload['PRESENTATION'] = self::PRES_VALUE;
            if (isset($presentation['suffix'])) { $payload['SUFFIX'] = $this->t((string)$presentation['suffix']); }
            if (isset($presentation['digits'])) { $payload['DIGITS'] = (int)$presentation['digits']; }
            if (array_key_exists('min', $presentation)) {
                $payload['MIN'] = is_numeric($presentation['min']) ? (float)$presentation['min'] : $presentation['min'];
            }
            if (array_key_exists('max', $presentation)) {
                $payload['MAX'] = is_numeric($presentation['max']) ? (float)$presentation['max'] : $presentation['max'];
            }
            if (array_key_exists('prefix', $presentation)) { $payload['PREFIX'] = $this->t((string)$presentation['prefix']); }
            if (array_key_exists('percentage', $presentation)) { $payload['PERCENTAGE'] = (bool)$presentation['percentage']; }
            if (array_key_exists('usageType', $presentation) || array_key_exists('usage_type', $presentation)) {
                $payload['USAGE_TYPE'] = (int)($presentation['usageType'] ?? $presentation['usage_type']);
            }
            if (array_key_exists('decimalSeparator', $presentation)) { $payload['DECIMAL_SEPARATOR'] = (string)$presentation['decimalSeparator']; }
            if (array_key_exists('thousandsSeparator', $presentation)) { $payload['THOUSANDS_SEPARATOR'] = (string)$presentation['thousandsSeparator']; }
            if (array_key_exists('multiline', $presentation)) { $payload['MULTILINE'] = (bool)$presentation['multiline']; }
            if (array_key_exists('icon', $presentation)) { $payload['ICON'] = (string)$presentation['icon']; }
            if (array_key_exists('color', $presentation)) { $payload['COLOR'] = (int)$presentation['color']; }
            if (array_key_exists('intervalsActive', $presentation)) { $payload['INTERVALS_ACTIVE'] = (bool)$presentation['intervalsActive']; }
            if (array_key_exists('intervals', $presentation) && is_array($presentation['intervals'])) {
                $payload['INTERVALS'] = $presentation['intervals'];
            }
            if (strtoupper((string)$type) === 'BOOLEAN' && (isset($presentation['captionOn']) || isset($presentation['captionOff'])) && !isset($payload['OPTIONS'])) {
                $payload['OPTIONS'] = [
                    ['Value' => false, 'Caption' => $this->t((string)($presentation['captionOff'] ?? $this->t('Off'))),
                     'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                    ['Value' => true,  'Caption' => $this->t((string)($presentation['captionOn'] ?? $this->t('On'))),
                     'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ];
            }
            if (isset($presentation['options']) && is_array($presentation['options'])) {
                $options = [];
                foreach ($presentation['options'] as $op) {
                    if (!is_array($op) || !array_key_exists('value', $op) || !array_key_exists('caption', $op)) continue;
                    // Sub-parameters of a value-presentation option (Symcon 9.1 rejects others such as Color/ColorDisplay)
                    $colorValue = isset($op['colorValue']) ? (int)$op['colorValue'] : (isset($op['color']) ? (int)$op['color'] : -1);
                    $options[] = [
                        'Value'   => is_numeric($op['value']) ? (float)$op['value'] + 0 :
                                     ((is_bool($op['value'])) ? ((bool)$op['value'] ? 1 : 0) : (string)$op['value']),
                        'Caption' => $this->t((string)$op['caption']),
                        'IconActive' => isset($op['iconActive']) ? (bool)$op['iconActive'] : false,
                        'IconValue'  => isset($op['iconValue']) ? (string)$op['iconValue'] : '',
                        'ColorActive' => array_key_exists('colorActive', $op) ? (bool)$op['colorActive'] : $colorValue !== -1,
                        'ColorValue'  => $colorValue,
                        'ContentColorActive' => false,
                        'ContentColorValue'  => -1,
                    ];
                }
                if (!empty($options)) { $payload['OPTIONS'] = $options; }
            }
            // Symcon 9.1 rejects the whole call when a parameter is not defined for the presentation of
            // this variable type ("Der Parameter MULTILINE ist für diese Darstellung nicht definiert",
            // measured 01.10.2026): only the keys of the type are sent, foreign ones are dropped.
            $defaults = self::valueDefaults($type);
            foreach ($defaults as $k => $v) {
                if (!array_key_exists($k, $payload)) { $payload[$k] = $v; }
            }
            foreach (array_keys($payload) as $k) {
                if ($k !== 'PRESENTATION' && !array_key_exists($k, $defaults)) {
                    $this->dbg('Presentation', 'Dropping ' . $k . ' for ident=' . $ident . ': not defined for ' . strtoupper($type) . ' value presentation');
                    unset($payload[$k]);
                }
            }
            if (isset($payload['OPTIONS']) && is_array($payload['OPTIONS'])) {
                foreach ($payload['OPTIONS'] as &$op) {
                    if (!is_array($op)) { $op = []; }
                    if (!array_key_exists('Value', $op)) { $op['Value'] = ''; }
                    if (!array_key_exists('Caption', $op)) { $op['Caption'] = ''; }
                    if (!array_key_exists('IconActive', $op)) { $op['IconActive'] = false; }
                    if (!array_key_exists('IconValue', $op)) { $op['IconValue'] = ''; }
                    if (!array_key_exists('ColorActive', $op)) { $op['ColorActive'] = false; }
                    if (!array_key_exists('ColorValue', $op)) { $op['ColorValue'] = -1; }
                    if (!array_key_exists('ContentColorActive', $op)) { $op['ContentColorActive'] = false; }
                    if (!array_key_exists('ContentColorValue', $op)) { $op['ContentColorValue'] = -1; }
                    unset($op['Color'], $op['ColorDisplay']);
                }
                unset($op);
            }
        }

        if (empty($payload)) {
            return [];
        }
        $payload = $this->translatePresentationPayload($payload);
        // An INTEGER variable takes MIN, MAX and a whole STEP_SIZE only as integers (Symcon 9.1: "falscher Typ")
        if (ThinQValue::typeOf($vid) === VARIABLETYPE_INTEGER) {
            foreach (['MIN', 'MAX', 'STEP_SIZE'] as $key) {
                if (isset($payload[$key]) && is_float($payload[$key]) && floor($payload[$key]) === $payload[$key]) {
                    $payload[$key] = (int)$payload[$key];
                }
            }
        }
        // Symcon takes OPTIONS and INTERVALS only as JSON strings (an array fails the whole call)
        foreach (['OPTIONS', 'INTERVALS'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = json_encode($payload[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        $this->dbg('Presentation', 'ident=' . $ident . ' kind=' . $kind . ' payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $payload;
    }

    /**
     * Parameters of the value presentation per variable type, as Symcon 9.1 accepts them
     * (every other parameter makes IPS_SetVariableCustomPresentation fail).
     *
     * @return array<string, mixed> parameter => default
     */
    public static function valueDefaults(string $type): array
    {
        $type = strtoupper($type);
        $defaults = ['ICON' => '', 'COLOR' => -1, 'PERCENTAGE' => false, 'PREFIX' => '', 'SUFFIX' => '', 'USAGE_TYPE' => 0];
        if ($type === 'INTEGER' || $type === 'FLOAT') {
            $defaults += ['DIGITS' => 2, 'DECIMAL_SEPARATOR' => 'Client', 'THOUSANDS_SEPARATOR' => '', 'MIN' => 0, 'MAX' => 100,
                'INTERVALS_ACTIVE' => false, 'INTERVALS' => []];
        } elseif ($type === 'STRING') {
            $defaults += ['MULTILINE' => false, 'OPTIONS' => []];
        } else { // BOOLEAN
            $defaults += ['OPTIONS' => []];
        }
        return $defaults;
    }

    public function translatePresentationPayload(array $payload): array
    {
        if (isset($payload['SUFFIX'])) { $payload['SUFFIX'] = $this->t((string)$payload['SUFFIX']); }
        if (isset($payload['OPTIONS']) && is_array($payload['OPTIONS'])) {
            foreach ($payload['OPTIONS'] as &$option) {
                if (isset($option['Caption'])) { $option['Caption'] = $this->t((string)$option['Caption']); }
            }
            unset($option);
        }
        if (isset($payload['INTERVALS']) && is_array($payload['INTERVALS'])) {
            foreach ($payload['INTERVALS'] as &$interval) {
                if (isset($interval['ConstantValue'])) { $interval['ConstantValue'] = $this->t((string)$interval['ConstantValue']); }
            }
            unset($interval);
        }
        return $payload;
    }
}
