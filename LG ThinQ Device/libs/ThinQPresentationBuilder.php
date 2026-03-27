<?php

declare(strict_types=1);

/**
 * ThinQPresentationBuilder
 *
 * Extracted from LG ThinQ Device/module.php.
 * Handles applyPresentation, translatePresentationPayload, applyProfileFallback.
 */
class ThinQPresentationBuilder
{
    private const PRES_VALUE    = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const PRES_SWITCH   = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    private const PRES_SLIDER   = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const PRES_DATETIME = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
    private const PRES_BUTTONS  = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

    private const PROFILE_PREFIX = 'LGTQD.';

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

    public function applyPresentation(int $vid, string $ident, array $presentation, array $flatProfile, string $type): void
    {
        $kind = strtolower((string)($presentation['kind'] ?? ''));
        if ($kind === '') {
            return;
        }

        $payload = [];
        if ($kind === 'switch') {
            $payload['PRESENTATION'] = self::PRES_SWITCH;
            $payload['CAPTION_ON'] = $this->t((string)($presentation['captionOn'] ?? $this->t('On')));
            $payload['CAPTION_OFF'] = $this->t((string)($presentation['captionOff'] ?? $this->t('Off')));
        } elseif ($kind === 'slider') {
            $payload['PRESENTATION'] = self::PRES_SLIDER;
            $range = $presentation['range'] ?? [];
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;
            $step = $range['step'] ?? null;
            if ((!is_numeric($min) || !is_numeric($max) || !is_numeric($step)) && isset($presentation['rangeFromProfile'])) {
                $rfp = $presentation['rangeFromProfile'];
                $min = $min ?? $this->firstNumericByPaths($flatProfile, (array)($rfp['min'] ?? []));
                $max = $max ?? $this->firstNumericByPaths($flatProfile, (array)($rfp['max'] ?? []));
                $step = $step ?? $this->firstNumericByPaths($flatProfile, (array)($rfp['step'] ?? []));
            }
            if (is_numeric($min)) { $payload['MIN'] = (float)$min; }
            if (is_numeric($max)) { $payload['MAX'] = (float)$max; }
            if (!is_numeric($step) || (float)$step === 0.0) { $step = 1.0; }
            $payload['STEP_SIZE'] = (float)$step;
            if (isset($presentation['suffix'])) {
                $payload['SUFFIX'] = $this->t((string)$presentation['suffix']);
            } elseif (isset($range['suffix'])) {
                $payload['SUFFIX'] = $this->t((string)$range['suffix']);
            }
            if (isset($presentation['digits'])) {
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
        } elseif ($kind === 'enumeration') {
            if (!defined('VARIABLE_PRESENTATION_ENUMERATION')) {
                $this->dbg('Presentation', 'Enumeration presentation not available in this IP-Symcon version; ident=' . $ident);
                return;
            }
            $payload['PRESENTATION'] = VARIABLE_PRESENTATION_ENUMERATION;
            $options = [];
            if (isset($presentation['options']) && is_array($presentation['options'])) {
                foreach ($presentation['options'] as $op) {
                    if (!is_array($op)) continue;
                    if (!array_key_exists('value', $op) || !array_key_exists('caption', $op)) continue;
                    $options[] = [
                        'Value'      => (int)$op['value'],
                        'Caption'    => $this->t((string)$op['caption']),
                        'IconActive' => false,
                        'IconValue'  => '',
                        'Color'      => isset($op['color']) ? (int)$op['color'] : -1
                    ];
                }
            }
            if (!empty($options)) { $payload['OPTIONS'] = $options; }
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
            if (isset($presentation['options']) && is_array($presentation['options']) && !isset($payload['OPTIONS'])) {
                $options = [];
                foreach ($presentation['options'] as $op) {
                    if (!is_array($op) || !array_key_exists('value', $op) || !array_key_exists('caption', $op)) continue;
                    $value = $op['value'];
                    if ($type === 'STRING' && !is_string($value)) { $value = (string)$value; }
                    elseif ($type !== 'STRING' && !is_int($value)) { $value = (int)$value; }
                    $options[] = ['Value' => $value, 'Caption' => $this->t((string)$op['caption']),
                        'IconActive' => false, 'IconValue' => '', 'ColorActive' => false,
                        'ColorValue' => -1, 'Color' => -1, 'ColorDisplay' => -1];
                }
                if (!empty($options)) { $payload['OPTIONS'] = $options; }
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
                    $opt = [
                        'Value'   => is_numeric($op['value']) ? (float)$op['value'] + 0 :
                                     ((is_bool($op['value'])) ? ((bool)$op['value'] ? 1 : 0) : (string)$op['value']),
                        'Caption' => $this->t((string)$op['caption']),
                        'IconActive' => isset($op['iconActive']) ? (bool)$op['iconActive'] : false,
                        'IconValue'  => isset($op['iconValue']) ? (string)$op['iconValue'] : '',
                        'Color'      => isset($op['color']) ? (int)$op['color'] : -1,
                    ];
                    if (array_key_exists('colorActive', $op)) { $opt['ColorActive'] = (bool)$op['colorActive']; }
                    if (array_key_exists('colorValue', $op)) { $opt['ColorValue'] = (int)$op['colorValue']; }
                    if (array_key_exists('colorDisplay', $op)) { $opt['ColorDisplay'] = (int)$op['colorDisplay']; }
                    $options[] = $opt;
                }
                if (!empty($options)) { $payload['OPTIONS'] = $options; }
            }
            $defaults = [
                'DIGITS' => 2, 'SUFFIX' => '', 'INTERVALS_ACTIVE' => false, 'INTERVALS' => [],
                'ICON' => '', 'DECIMAL_SEPARATOR' => 'Client', 'COLOR' => -1, 'MULTILINE' => false,
                'MAX' => 100, 'THOUSANDS_SEPARATOR' => '', 'MIN' => 0, 'PERCENTAGE' => false,
                'PREFIX' => '', 'USAGE_TYPE' => 0,
            ];
            foreach ($defaults as $k => $v) {
                if (!array_key_exists($k, $payload)) { $payload[$k] = $v; }
            }
            if (!isset($payload['OPTIONS']) || !is_array($payload['OPTIONS'])) { $payload['OPTIONS'] = []; }
            if (isset($payload['OPTIONS']) && is_array($payload['OPTIONS'])) {
                foreach ($payload['OPTIONS'] as &$op) {
                    if (!is_array($op)) { $op = []; }
                    if (!array_key_exists('Value', $op)) { $op['Value'] = ''; }
                    if (!array_key_exists('Caption', $op)) { $op['Caption'] = ''; }
                    if (!array_key_exists('IconActive', $op)) { $op['IconActive'] = false; }
                    if (!array_key_exists('IconValue', $op)) { $op['IconValue'] = ''; }
                    if (!array_key_exists('ColorActive', $op)) { $op['ColorActive'] = false; }
                    if (!array_key_exists('ColorValue', $op)) { $op['ColorValue'] = -1; }
                    if (!array_key_exists('Color', $op)) { $op['Color'] = -1; }
                    if (!array_key_exists('ColorDisplay', $op)) { $op['ColorDisplay'] = -1; }
                }
                unset($op);
            }
        }

        if (empty($payload)) {
            return;
        }

        $payload = $this->translatePresentationPayload($payload);
        $this->dbg('Presentation', 'Preparing ident=' . $ident . ' kind=' . $kind . ' payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        @IPS_SetVariableCustomProfile($vid, '');

        if (function_exists('IPS_SetVariableCustomPresentation')) {
            $payloadEncoded = $payload;
            $hadOptionsArray = isset($payload['OPTIONS']) && is_array($payload['OPTIONS']);
            if ($hadOptionsArray) {
                $payloadEncoded['OPTIONS'] = json_encode($payload['OPTIONS'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $hadIntervalsArray = isset($payload['INTERVALS']) && is_array($payload['INTERVALS']);
            if ($hadIntervalsArray) {
                $payloadEncoded['INTERVALS'] = json_encode($payload['INTERVALS'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            @IPS_SetVariableCustomPresentation($vid, $payloadEncoded);
            $varInfo = @IPS_GetVariable($vid);
            $post = is_array($varInfo) && array_key_exists('VariableCustomPresentation', $varInfo) ? $varInfo['VariableCustomPresentation'] : null;
            $this->dbg('Presentation', 'Applied ident=' . $ident . ' post=' . (is_string($post) ? $post : json_encode($post)));
            $notApplied = ($post === null || $post === '' || $post === false);
            if ($notApplied && ($hadOptionsArray || $hadIntervalsArray)) {
                $this->dbg('Presentation', 'Retry ident=' . $ident . ' with array fields (OPTIONS/INTERVALS) unencoded (compatibility attempt)');
                @IPS_SetVariableCustomPresentation($vid, $payload);
            }
        } else {
            $this->dbg('Presentation', 'IPS_SetVariableCustomPresentation not available; skipping ident=' . $ident);
        }
    }

    public function translatePresentationPayload(array $payload): array
    {
        if (isset($payload['CAPTION_ON'])) { $payload['CAPTION_ON'] = $this->t((string)$payload['CAPTION_ON']); }
        if (isset($payload['CAPTION_OFF'])) { $payload['CAPTION_OFF'] = $this->t((string)$payload['CAPTION_OFF']); }
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

    public function applyProfileFallback(int $vid, string $ident, array $presentation, string $type): void
    {
        $varInfo = @IPS_GetVariable($vid);
        if (!is_array($varInfo)) {
            return;
        }
        $profileName = self::PROFILE_PREFIX . $this->instanceId . '.' . $ident;
        $vt = match (strtoupper($type)) {
            'BOOLEAN' => VARIABLETYPE_BOOLEAN,
            'INTEGER' => VARIABLETYPE_INTEGER,
            'FLOAT'   => VARIABLETYPE_FLOAT,
            default   => VARIABLETYPE_STRING
        };
        if (@IPS_VariableProfileExists($profileName)) {
            @IPS_SetVariableCustomProfile($vid, '');
            @IPS_DeleteVariableProfile($profileName);
        }
        @IPS_CreateVariableProfile($profileName, $vt);
        @IPS_SetVariableProfileIcon($profileName, '');
        @IPS_SetVariableProfileText($profileName, '', '');
        if ($vt === VARIABLETYPE_BOOLEAN) {
            @IPS_SetVariableProfileAssociation($profileName, 0, $presentation['CAPTION_OFF'] ?? $this->t('Off'), '', -1);
            @IPS_SetVariableProfileAssociation($profileName, 1, $presentation['CAPTION_ON'] ?? $this->t('On'), '', -1);
        } elseif ($vt === VARIABLETYPE_INTEGER || $vt === VARIABLETYPE_FLOAT) {
            $min  = isset($presentation['MIN']) ? (float)$presentation['MIN'] : 0.0;
            $max  = isset($presentation['MAX']) ? (float)$presentation['MAX'] : 0.0;
            $step = isset($presentation['STEP_SIZE']) ? (float)$presentation['STEP_SIZE'] : 1.0;
            @IPS_SetVariableProfileValues($profileName, $min, $max, $step);
            if (isset($presentation['DIGITS'])) { @IPS_SetVariableProfileDigits($profileName, (int)$presentation['DIGITS']); }
            if (isset($presentation['SUFFIX'])) { @IPS_SetVariableProfileText($profileName, '', (string)$presentation['SUFFIX']); }
            if (isset($presentation['OPTIONS']) && is_array($presentation['OPTIONS'])) {
                foreach ($presentation['OPTIONS'] as $option) {
                    if (!isset($option['Value'], $option['Caption'])) continue;
                    @IPS_SetVariableProfileAssociation($profileName, (int)$option['Value'], (string)$option['Caption'], '', (int)($option['Color'] ?? -1));
                }
            }
        }
        @IPS_SetVariableCustomProfile($vid, $profileName);
    }

    private function firstNumericByPaths(array $flat, array $paths): ?float
    {
        foreach ($paths as $path) {
            $path = (string)$path;
            if ($path === '') continue;
            $candidates = [$path, 'property.' . $path, 'value.' . $path, 'profile.' . $path];
            for ($i = 0; $i <= 4; $i++) {
                $candidates[] = 'property.' . $i . '.' . $path;
                $candidates[] = 'value.property.' . $i . '.' . $path;
                $candidates[] = 'profile.property.' . $i . '.' . $path;
                $candidates[] = 'profile.value.property.' . $i . '.' . $path;
            }
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $flat) && is_numeric($flat[$candidate])) {
                    return (float)$flat[$candidate];
                }
            }
        }
        return null;
    }
}
