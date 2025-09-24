<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/CapabilityEngine.php';

class LGThinQDevice extends IPSModule
{
    private const GATEWAY_MODULE_GUID = '{FCD02091-9189-0B0A-0C70-D607F1941C05}';
    private const DATA_FLOW_GUID      = '{A1F438B3-2A68-4A2B-8FDB-7460F1B8B854}';
    // Neue Variablendarstellungen (Presentations)
    private const PRES_VALUE   = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const PRES_SWITCH  = '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}';
    private const PRES_SLIDER  = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const PRES_DATETIME= '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
    private const PRES_BUTTONS = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Alias', '');

        $this->RegisterAttributeString('LastStatus', '');
        $this->RegisterAttributeString('DeviceType', '');
        $this->RegisterAttributeString('LastProfile', '');
    }



    public function Destroy()
    {
        // Hinweis: Während Destroy ist die Parent-Schnittstelle typischerweise nicht verfügbar.
        // Kein Unsubscribe hier ausführen, um "InstanceInterface is not available" zu vermeiden.
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $rawAlias = $this->ReadPropertyString('Alias');
        $alias = is_string($rawAlias) ? trim($rawAlias) : '';
        if ($alias !== '' && IPS_GetName($this->InstanceID) !== $alias) {
            @IPS_SetName($this->InstanceID, $alias);
        }

        $this->ensureVariable($this->InstanceID, 'INFO', 'Info', VARIABLETYPE_STRING);
        $this->ensureVariable($this->InstanceID, 'STATUS', 'Status', VARIABLETYPE_STRING);
        $this->ensureVariable($this->InstanceID, 'LASTUPDATE', 'Last Update', VARIABLETYPE_INTEGER, '~UnixTimestamp');

        // Optional: Geräte-Info bei Erstkonfiguration setzen
        $devId = $this->ReadPropertyString('DeviceID');
        if ($devId !== '') {
            $info = ['deviceId' => $devId, 'alias' => $alias];
            @SetValueString($this->getVarId('INFO'), json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // (Polling entfernt)

        // Verbindung verzögert sicherstellen (Konfiguration könnte erst nach Create gesetzt sein)
        if (method_exists($this, 'RegisterOnceTimer')) {
            $this->RegisterOnceTimer('ConnectOnce', 'LGTQD_EnsureConnected($_IPS["TARGET"]);');
        }
        // Direkt versuchen zu verbinden, falls die Konfiguration bereits vollständig ist
        $this->EnsureConnected();

  
        $deviceIDCheck = (string)$this->ReadPropertyString('DeviceID');

        
        // Variablen/Profile gemäß Gerät anlegen
        try {
            $this->SetupDeviceVariables();

        } catch (\Throwable $e) {

        }

        // Auto-Subscribe für Event/Push am Bridge-Splitter
        try {
            $deviceID = (string)$this->ReadPropertyString('DeviceID');
            if ($deviceID !== '') {
                $this->sendAction('SubscribeDevice', ['DeviceID' => $deviceID, 'Push' => true, 'Event' => true]);
            }
        } catch (\Throwable $e) {
        }
    }

    // Formular-Button: LGTQD_UpdateStatus($id)
    public function UpdateStatus(): void
    {
        $deviceID  = (string)$this->ReadPropertyString('DeviceID');
        if ($deviceID === '') {
            $this->LogMessage($this->t('UpdateStatus: DeviceID is missing'), KL_WARNING);
            return;
        }
        try {
            // Sicherstellen, dass Parent verbunden ist
            if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
                $this->EnsureConnected();
            }
            $json = $this->sendAction('GetStatus', ['DeviceID' => $deviceID]);
            $status = json_decode((string)$json, true);
            if (!is_array($status)) {
                throw new \Exception($this->t('Invalid status response'));
            }
            @SetValueString($this->getVarId('STATUS'), json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            @SetValueInteger($this->getVarId('LASTUPDATE'), time());
            $this->WriteAttributeString('LastStatus', json_encode($status));

            // Dedizierte Variablen aktualisieren
            $this->updateFromStatus($status);
            // CapabilityEngine: Werte anwenden
            try {
                $this->getCapabilityEngine()->applyStatus($status);
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
        }
    }

    // Manuell aufrufbar: LGTQD_ControlDevice($id, $JSONPayload)
    public function ControlDevice(string $JSONPayload): bool
    {
        $deviceID  = (string)$this->ReadPropertyString('DeviceID');
        if ($deviceID === '') {
            throw new \Exception($this->t('ControlDevice: Missing DeviceID'));
        }
        $payload = json_decode($JSONPayload, true);
        if (!is_array($payload)) {
            throw new \Exception($this->t('ControlDevice: Invalid JSON payload'));
        }
        $resp = $this->sendAction('Control', ['DeviceID' => $deviceID, 'Payload' => $payload]);
        $dec = json_decode((string)$resp, true);
        return is_array($dec) && ($dec['success'] ?? false) === true;
    }

    // Variable steuern (WebFront/Konsole)
    public function RequestAction($ident, $value)
    {
        $payload = [];
        $fallbackPayload = null;
        try {
            // 1) Generic handling via CapabilityEngine (if descriptor exists)
            try {
                $eng = $this->getCapabilityEngine();
                $profRaw = (string)$this->ReadAttributeString('LastProfile');
                $prof = @json_decode($profRaw, true);
                $type = (string)$this->ReadAttributeString('DeviceType');
                if (!is_array($prof)) { $prof = []; }
                $eng->loadCapabilities($type, $prof);
                $gen = $eng->buildControlPayload((string)$ident, $value);
                if (is_array($gen)) {
                    $ok = $this->ControlDevice(json_encode($gen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if ($ok) {
                        // local feedback
                        $this->setValueByVarType((string)$ident, $value);
                        // update helper H/M for composite sliders
                        if ($ident === 'TIMER_START_REL_TIME' || $ident === 'TIMER_STOP_REL_TIME' || $ident === 'SLEEP_STOP_REL_TIME') {
                            $total = (int)$value;
                            if ($total < 1) { $total = 1; }
                            if ($total > 720) { $total = 720; }
                            $h = intdiv($total, 60);
                            $m = $total % 60;
                            if ($ident === 'TIMER_START_REL_TIME') {
                                if ($this->getVarId('TIMER_START_REL_HOUR') === 0) { $this->ensureVariable($this->InstanceID, 'TIMER_START_REL_HOUR', 'Timer Start Stunde', VARIABLETYPE_INTEGER, ''); }
                                if ($this->getVarId('TIMER_START_REL_MIN')  === 0) { $this->ensureVariable($this->InstanceID, 'TIMER_START_REL_MIN',  'Timer Start Minute', VARIABLETYPE_INTEGER, ''); }
                                @SetValueInteger($this->getVarId('TIMER_START_REL_HOUR'), $h);
                                @SetValueInteger($this->getVarId('TIMER_START_REL_MIN'),  $m);
                            } elseif ($ident === 'TIMER_STOP_REL_TIME') {
                                if ($this->getVarId('TIMER_STOP_REL_HOUR') === 0) { $this->ensureVariable($this->InstanceID, 'TIMER_STOP_REL_HOUR', 'Timer Stop Stunde', VARIABLETYPE_INTEGER, ''); }
                                if ($this->getVarId('TIMER_STOP_REL_MIN')  === 0) { $this->ensureVariable($this->InstanceID, 'TIMER_STOP_REL_MIN',  'Timer Stop Minute', VARIABLETYPE_INTEGER, ''); }
                                @SetValueInteger($this->getVarId('TIMER_STOP_REL_HOUR'), $h);
                                @SetValueInteger($this->getVarId('TIMER_STOP_REL_MIN'),  $m);
                            } else { // SLEEP_STOP_REL_TIME
                                if ($this->getVarId('SLEEP_STOP_REL_HOUR') === 0) { $this->ensureVariable($this->InstanceID, 'SLEEP_STOP_REL_HOUR', 'Sleep Stop Stunde', VARIABLETYPE_INTEGER, ''); }
                                if ($this->getVarId('SLEEP_STOP_REL_MIN')  === 0) { $this->ensureVariable($this->InstanceID, 'SLEEP_STOP_REL_MIN',  'Sleep Stop Minute', VARIABLETYPE_INTEGER, ''); }
                                @SetValueInteger($this->getVarId('SLEEP_STOP_REL_HOUR'), $h);
                                @SetValueInteger($this->getVarId('SLEEP_STOP_REL_MIN'),  $m);
                            }
                        }
                        // Optional: kurzer Auto-Refresh
                        if (method_exists($this, 'RegisterOnceTimer')) {
                            @ $this->RegisterOnceTimer('RefreshAfterControl', 'LGTQD_UpdateStatus($_IPS["TARGET"]);');
                        }
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                // ignore and fall back to handcrafted switch
            }
            switch ($ident) {
                case 'POWER':
                    $on = (bool)$value;
                    $payload = [
                        'operation' => ['airConOperationMode' => $on ? 'POWER_ON' : 'POWER_OFF']
                    ];
                    break;

                case 'HVAC_MODE':
                    $code = (int)$value;
                    if ($code === 0) {
                        // Off → Power Off
                        $payload = [
                            'operation' => ['airConOperationMode' => 'POWER_OFF']
                        ];
                    } else {
                        $job = $this->mapHvacCodeToJobMode($code);
                        $payload = [
                            'operation' => ['airConOperationMode' => 'POWER_ON'],
                            'airConJobMode' => ['currentJobMode' => $job]
                        ];
                    }
                    break;

                case 'FAN_MODE':
                    $code = (int)$value;
                    $wind = $this->mapFanCodeToWindStrength($code);
                    $payload = [
                        'airFlow' => ['windStrength' => $wind]
                    ];
                    break;

                case 'SET_TEMP':
                    // Robust float parsing (support locale comma)
                    $tempVal = is_numeric($value) ? (float)$value : (float)str_replace(',', '.', (string)$value);

                    // Clamp & round according to current slider presentation, if available
                    $vidSet = $this->getVarId('SET_TEMP');
                    $min = 16.0; $max = 30.0; $step = 1.0;
                    $varInfo = @IPS_GetVariable($vidSet);
                    if (is_array($varInfo) && isset($varInfo['VariableCustomPresentation']) && is_array($varInfo['VariableCustomPresentation'])) {
                        $pres = $varInfo['VariableCustomPresentation'];
                        if (isset($pres['MIN'])) $min = (float)$pres['MIN'];
                        if (isset($pres['MAX'])) $max = (float)$pres['MAX'];
                        if (isset($pres['STEP_SIZE'])) $step = (float)$pres['STEP_SIZE'];
                    }
                    // Enforce devices that only accept integer steps
                    if ($step < 1.0) { $step = 1.0; }
                    if ($step > 0) {
                        $tempVal = round(($tempVal - $min) / $step) * $step + $min;
                    }
                    $tempVal = max($min, min($max, $tempVal));

                    // Determine correct temperature key based on current job mode
                    $jobMode = $this->getCurrentJobMode(); // AUTO/COOL/HEAT/DRY/FAN/...
                    $normMode = $this->normalizeJobMode($jobMode);
                    if ($normMode === 'FAN') {
                        throw new \Exception($this->t('Temperature cannot be controlled in FAN mode'));
                    }
                    // Per LG docs: Temperature control only when POWER_ON and mode is COOL/HEAT/AUTO/AIR_DRY
                    $isOn = $this->isPowerOnFromStatus();
                    if (!$isOn) {
                        throw new \Exception($this->t('Temperature control not possible: device is POWER_OFF'));
                    }
                    if (!in_array($normMode, ['COOL','HEAT','AUTO','AIR_DRY'], true)) {
                        throw new \Exception($this->t('Temperature control not possible in mode') . ' ' . $normMode);
                    }
                    $container = 'temperature';
                    $key = $this->temperatureKeyForMode($normMode);
                    $unit = $this->detectTemperatureUnit();
                    $payload = [ $container => [ $key => $tempVal, 'unit' => $unit ] ];
                    // Fallback 1: generic targetTemperature
                    $fallbackPayload = [ 'temperature' => [ 'targetTemperature' => $tempVal, 'unit' => $unit ] ];
                    // Fallback 2: twoSetTemperature for HEAT/COOL
                    $fallbackPayload2 = null;
                    if (in_array($normMode, ['HEAT','COOL'], true)) {
                        $tsKey = ($normMode === 'HEAT') ? 'heatTargetTemperature' : 'coolTargetTemperature';
                        $fallbackPayload2 = [ 'twoSetTemperature' => [ $tsKey => $tempVal, 'unit' => $unit, 'twoSetEnabled' => true ] ];
                    }
                    break;

                case 'SWING':
                    $b = (bool)$value;
                    $payload = [
                        'windDirection' => [
                            'rotateUpDown' => $b,
                            'rotateLeftRight' => $b
                        ]
                    ];
                    break;

                case 'ECO':
                    $payload = ['ecoMode' => (bool)$value];
                    break;

                case 'ICE_PLUS':
                    $payload = ['expressFreeze' => (bool)$value];
                    break;

                // --- AC: Power Save / Display / Air Clean ---
                case 'POWER_SAVE':
                    $payload = [
                        'powerSave' => ['powerSaveEnabled' => (bool)$value]
                    ];
                    break;
                case 'DISPLAY_LIGHT':
                    $payload = [
                        'display' => ['light' => ((bool)$value ? 'ON' : 'OFF')]
                    ];
                    break;
                case 'AIR_CLEAN':
                    $payload = [
                        'operation' => ['airCleanOperationMode' => ((bool)$value ? 'START' : 'STOP')]
                    ];
                    break;

                // --- AC: Erweiterte Wind-Flags ---
                case 'FOREST_WIND':
                case 'AIR_GUIDE_WIND':
                case 'HIGH_CEILING_WIND':
                case 'AUTO_FIT_WIND':
                case 'CONCENTRATION_WIND':
                case 'SWIRL_WIND':
                    $map = [
                        'FOREST_WIND' => 'forestWind',
                        'AIR_GUIDE_WIND' => 'airGuideWind',
                        'HIGH_CEILING_WIND' => 'highCeilingWind',
                        'AUTO_FIT_WIND' => 'autoFitWind',
                        'CONCENTRATION_WIND' => 'concentrationWind',
                        'SWIRL_WIND' => 'swirlWind'
                    ];
                    $flag = $map[$ident] ?? '';
                    if ($flag === '') throw new \Exception('Unbekannte Wind-Flag');
                    $payload = [
                        'windDirection' => [ $flag => (bool)$value ]
                    ];
                    break;

                // --- AC: Timer relativ Start/Stop ---
                case 'TIMER_START_REL_TIME':
                    // Integer Minuten → Stunden/Minuten und Timer SET
                    $total = (int)$value;
                    if ($total < 1) { $total = 1; }
                    if ($total > 720) { $total = 720; }
                    $h = intdiv($total, 60);
                    $m = $total % 60;
                    $payload = ['timer' => [
                        'relativeHourToStart' => $h,
                        'relativeMinuteToStart' => $m
                    ]];
                    break;
                case 'TIMER_STOP_REL_TIME':
                    // Integer Minuten → Stunden/Minuten und Timer SET
                    $total = (int)$value;
                    if ($total < 1) { $total = 1; }
                    if ($total > 720) { $total = 720; }
                    $h = intdiv($total, 60);
                    $m = $total % 60;
                    $payload = ['timer' => [
                        'relativeHourToStop' => $h,
                        'relativeMinuteToStop' => $m
                    ]];
                    break;
                case 'TIMER_START_REL_HOUR':
                    $payload = ['timer' => ['relativeHourToStart' => (int)$value]]; break;
                case 'TIMER_START_REL_MIN':
                    $payload = ['timer' => ['relativeMinuteToStart' => (int)$value]]; break;
                case 'TIMER_START_REL_SET':
                    if ((bool)$value) {
                        // Auf SET → Stunden/Minuten mitsenden, kein 'SET' Feld
                        $h = 0; $m = 0;
                        $vid = $this->getVarId('TIMER_START_REL_TIME');
                        if ($vid > 0) {
                            $total = max(0, (int)@GetValueInteger($vid));
                            $h = intdiv($total, 60); $m = $total % 60;
                        } else {
                            $vh = $this->getVarId('TIMER_START_REL_HOUR');
                            $vm = $this->getVarId('TIMER_START_REL_MIN');
                            $h = ($vh > 0) ? (int)@GetValueInteger($vh) : 0;
                            $m = ($vm > 0) ? (int)@GetValueInteger($vm) : 0;
                        }
                        $payload = ['timer' => [
                            'relativeHourToStart' => $h,
                            'relativeMinuteToStart' => $m
                        ]];
                    } else {
                        $payload = ['timer' => ['relativeStartTimer' => 'UNSET']];
                    }
                    break;
                case 'TIMER_STOP_REL_HOUR':
                    $payload = ['timer' => ['relativeHourToStop' => (int)$value]]; break;
                case 'TIMER_STOP_REL_MIN':
                    $payload = ['timer' => ['relativeMinuteToStop' => (int)$value]]; break;
                case 'TIMER_STOP_REL_SET':
                    if ((bool)$value) {
                        $h = 0; $m = 0;
                        $vid = $this->getVarId('TIMER_STOP_REL_TIME');
                        if ($vid > 0) {
                            $total = max(0, (int)@GetValueInteger($vid));
                            $h = intdiv($total, 60); $m = $total % 60;
                        } else {
                            $vh = $this->getVarId('TIMER_STOP_REL_HOUR');
                            $vm = $this->getVarId('TIMER_STOP_REL_MIN');
                            $h = ($vh > 0) ? (int)@GetValueInteger($vh) : 0;
                            $m = ($vm > 0) ? (int)@GetValueInteger($vm) : 0;
                        }
                        $payload = ['timer' => [
                            'relativeHourToStop' => $h,
                            'relativeMinuteToStop' => $m
                        ]];
                    } else {
                        $payload = ['timer' => ['relativeStopTimer' => 'UNSET']];
                    }
                    break;

                // --- AC: Timer absolut Start/Stop ---
                case 'TIMER_START_ABS_HOUR':
                    $payload = ['timer' => ['absoluteHourToStart' => (int)$value]]; break;
                case 'TIMER_START_ABS_MIN':
                    $payload = ['timer' => ['absoluteMinuteToStart' => (int)$value]]; break;
                case 'TIMER_START_ABS_SET':
                    if ((bool)$value) {
                        $vh = $this->getVarId('TIMER_START_ABS_HOUR');
                        $vm = $this->getVarId('TIMER_START_ABS_MIN');
                        $h = ($vh > 0) ? (int)@GetValueInteger($vh) : 0;
                        $m = ($vm > 0) ? (int)@GetValueInteger($vm) : 0;
                        $payload = ['timer' => [
                            'absoluteHourToStart' => $h,
                            'absoluteMinuteToStart' => $m
                        ]];
                    } else {
                        $payload = ['timer' => ['absoluteStartTimer' => 'UNSET']];
                    }
                    break;
                case 'TIMER_STOP_ABS_HOUR':
                    $payload = ['timer' => ['absoluteHourToStop' => (int)$value]]; break;
                case 'TIMER_STOP_ABS_MIN':
                    $payload = ['timer' => ['absoluteMinuteToStop' => (int)$value]]; break;
                case 'TIMER_STOP_ABS_SET':
                    if ((bool)$value) {
                        $vh = $this->getVarId('TIMER_STOP_ABS_HOUR');
                        $vm = $this->getVarId('TIMER_STOP_ABS_MIN');
                        $h = ($vh > 0) ? (int)@GetValueInteger($vh) : 0;
                        $m = ($vm > 0) ? (int)@GetValueInteger($vm) : 0;
                        $payload = ['timer' => [
                            'absoluteHourToStop' => $h,
                            'absoluteMinuteToStop' => $m
                        ]];
                    } else {
                        $payload = ['timer' => ['absoluteStopTimer' => 'UNSET']];
                    }
                    break;

                // --- AC: SleepTimer ---
                case 'SLEEP_STOP_REL_HOUR':
                    $payload = ['sleepTimer' => ['relativeHourToStop' => (int)$value]]; break;
                case 'SLEEP_STOP_REL_MIN':
                    $payload = ['sleepTimer' => ['relativeMinuteToStop' => (int)$value]]; break;
                case 'SLEEP_STOP_REL_TIME':
                    // Integer Minuten → Stunden/Minuten und Timer SET
                    $total = (int)$value;
                    if ($total < 1) { $total = 1; }
                    if ($total > 720) { $total = 720; }
                    $h = intdiv($total, 60);
                    $m = $total % 60;
                    $payload = ['sleepTimer' => [
                        'relativeHourToStop' => $h,
                        'relativeMinuteToStop' => $m
                    ]];
                    break;
                case 'SLEEP_STOP_REL_SET':
                    if ((bool)$value) {
                        // Prefer combined slider value if present
                        $h = 0; $m = 0;
                        $vid = $this->getVarId('SLEEP_STOP_REL_TIME');
                        if ($vid > 0) {
                            $total = max(0, (int)@GetValueInteger($vid));
                            $h = intdiv($total, 60); $m = $total % 60;
                        } else {
                            $vh = $this->getVarId('SLEEP_STOP_REL_HOUR');
                            $vm = $this->getVarId('SLEEP_STOP_REL_MIN');
                            $h = ($vh > 0) ? (int)@GetValueInteger($vh) : 0;
                            $m = ($vm > 0) ? (int)@GetValueInteger($vm) : 0;
                        }
                        $payload = ['sleepTimer' => [
                            'relativeHourToStop' => $h,
                            'relativeMinuteToStop' => $m
                        ]];
                    } else {
                        $payload = ['sleepTimer' => ['relativeStopTimer' => 'UNSET']];
                    }
                    break;

                // --- AC: Two-Set ---
                case 'TWO_SET_ENABLED':
                    $payload = ['twoSetTemperature' => ['twoSetEnabled' => (bool)$value]]; break;
                case 'HEAT_SET_TEMP':
                    $payload = [
                        'temperature' => [
                            'heatTargetTemperature' => (float)$value,
                            'unit' => $this->detectTemperatureUnit()
                        ],
                        'airConJobMode' => ['currentJobMode' => 'HEAT']
                    ];
                    if (!$this->isPowerOnFromStatus()) {
                        $payload['operation'] = ['airConOperationMode' => 'POWER_ON'];
                    }
                    break;
                case 'COOL_SET_TEMP':
                    $payload = [
                        'temperature' => [
                            'coolTargetTemperature' => (float)$value,
                            'unit' => $this->detectTemperatureUnit()
                        ],
                        'airConJobMode' => ['currentJobMode' => 'COOL']
                    ];
                    if (!$this->isPowerOnFromStatus()) {
                        $payload['operation'] = ['airConOperationMode' => 'POWER_ON'];
                    }
                    break;
                case 'AUTO_SET_TEMP':
                    $payload = [
                        'temperature' => [
                            'autoTargetTemperature' => (float)$value,
                            'unit' => $this->detectTemperatureUnit()
                        ],
                        'airConJobMode' => ['currentJobMode' => 'AUTO']
                    ];
                    if (!$this->isPowerOnFromStatus()) {
                        $payload['operation'] = ['airConOperationMode' => 'POWER_ON'];
                    }
                    break;

                default:
                    throw new \Exception($this->t('Unknown action') . ': ' . (string)$ident);
            }

            $ok = $this->ControlDevice(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!$ok && $ident === 'SET_TEMP' && is_array($fallbackPayload)) {
                $ok = $this->ControlDevice(json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if (!$ok && isset($fallbackPayload2) && is_array($fallbackPayload2)) {
                    $ok = $this->ControlDevice(json_encode($fallbackPayload2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                if ($ok) {
                    // Lokales Feedback direkt mit Fallback-Wert
                    if ($this->getVarId('SET_TEMP') > 0) {
                        $this->SetValue('SET_TEMP', (float)$tempVal);
                    }
                }
            }
            if ($ok) {
                // Sofortiges lokales Feedback
                switch ($ident) {
                    case 'POWER':
                        $this->SetValue('POWER', (bool)$value);
                        // Bei Aus auch Modus auf Off
                        if (!(bool)$value && $this->getVarId('HVAC_MODE') > 0) {
                            $this->SetValue('HVAC_MODE', 0);
                        }
                        break;
                    case 'HVAC_MODE':
                        $this->SetValue('HVAC_MODE', (int)$value);
                        if ((int)$value === 0 && $this->getVarId('POWER') > 0) {
                            $this->SetValue('POWER', false);
                        } elseif ($this->getVarId('POWER') > 0) {
                            $this->SetValue('POWER', true);
                        }
                        break;
                    case 'FAN_MODE':
                        $this->SetValue('FAN_MODE', (int)$value);
                        break;
                    case 'SET_TEMP':
                        $this->SetValue('SET_TEMP', (float)$value);
                        break;
                    case 'SWING':
                        $this->SetValue('SWING', (bool)$value);
                        break;
                    case 'ECO':
                        $this->SetValue('ECO', (bool)$value);
                        break;
                    case 'ICE_PLUS':
                        $this->SetValue('ICE_PLUS', (bool)$value);
                        break;
                    case 'POWER_SAVE':
                        $this->SetValue('POWER_SAVE', (bool)$value);
                        break;
                    case 'DISPLAY_LIGHT':
                        $this->SetValue('DISPLAY_LIGHT', (bool)$value);
                        break;
                    case 'AIR_CLEAN':
                        $this->SetValue('AIR_CLEAN', (bool)$value);
                        break;
                    case 'FOREST_WIND':
                    case 'AIR_GUIDE_WIND':
                    case 'HIGH_CEILING_WIND':
                    case 'AUTO_FIT_WIND':
                    case 'CONCENTRATION_WIND':
                    case 'SWIRL_WIND':
                        $this->SetValue($ident, (bool)$value);
                        break;
                    case 'TIMER_START_REL_HOUR':
                    case 'TIMER_START_REL_MIN':
                    case 'TIMER_START_REL_TIME':
                    case 'TIMER_STOP_REL_HOUR':
                    case 'TIMER_STOP_REL_MIN':
                    case 'TIMER_STOP_REL_TIME':
                    case 'TIMER_START_ABS_HOUR':
                    case 'TIMER_START_ABS_MIN':
                    case 'TIMER_STOP_ABS_HOUR':
                    case 'TIMER_STOP_ABS_MIN':
                    case 'SLEEP_STOP_REL_HOUR':
                    case 'SLEEP_STOP_REL_MIN':
                    case 'SLEEP_STOP_REL_TIME':
                        $this->SetValue($ident, (int)$value);
                        // Wenn mit kombinierten Slider-Werten gearbeitet wurde, setze die (versteckten) H/M-Hilfsvariablen passend
                        if ($ident === 'TIMER_START_REL_TIME' || $ident === 'TIMER_STOP_REL_TIME' || $ident === 'SLEEP_STOP_REL_TIME') {
                            $total = (int)$value;
                            if ($total < 1) { $total = 1; }
                            if ($total > 720) { $total = 720; }
                            $h = intdiv($total, 60);
                            $m = $total % 60;
                            if ($ident === 'TIMER_START_REL_TIME') {
                                if ($this->getVarId('TIMER_START_REL_HOUR') === 0) { $this->ensureVariable($this->InstanceID, 'TIMER_START_REL_HOUR', 'Timer Start Stunde', VARIABLETYPE_INTEGER, ''); }
                                if ($this->getVarId('TIMER_START_REL_MIN')  === 0) { $this->ensureVariable($this->InstanceID, 'TIMER_START_REL_MIN',  'Timer Start Minute', VARIABLETYPE_INTEGER, ''); }
                                @SetValueInteger($this->getVarId('TIMER_START_REL_HOUR'), $h);
                                @SetValueInteger($this->getVarId('TIMER_START_REL_MIN'),  $m);
                            } elseif ($ident === 'TIMER_STOP_REL_TIME') {
                                if ($this->getVarId('TIMER_STOP_REL_HOUR') === 0) { $this->ensureVariable($this->InstanceID, 'TIMER_STOP_REL_HOUR', 'Timer Stop Stunde', VARIABLETYPE_INTEGER, ''); }
                                if ($this->getVarId('TIMER_STOP_REL_MIN')  === 0) { $this->ensureVariable($this->InstanceID, 'TIMER_STOP_REL_MIN',  'Timer Stop Minute', VARIABLETYPE_INTEGER, ''); }
                                @SetValueInteger($this->getVarId('TIMER_STOP_REL_HOUR'), $h);
                                @SetValueInteger($this->getVarId('TIMER_STOP_REL_MIN'),  $m);
                            } else { // SLEEP_STOP_REL_TIME
                                if ($this->getVarId('SLEEP_STOP_REL_HOUR') === 0) { $this->ensureVariable($this->InstanceID, 'SLEEP_STOP_REL_HOUR', 'Sleep Stop Stunde', VARIABLETYPE_INTEGER, ''); }
                                if ($this->getVarId('SLEEP_STOP_REL_MIN')  === 0) { $this->ensureVariable($this->InstanceID, 'SLEEP_STOP_REL_MIN',  'Sleep Stop Minute', VARIABLETYPE_INTEGER, ''); }
                                @SetValueInteger($this->getVarId('SLEEP_STOP_REL_HOUR'), $h);
                                @SetValueInteger($this->getVarId('SLEEP_STOP_REL_MIN'),  $m);
                            }
                        }
                        break;
                    case 'TIMER_START_REL_SET':
                    case 'TIMER_STOP_REL_SET':
                    case 'TIMER_START_ABS_SET':
                    case 'TIMER_STOP_ABS_SET':
                    case 'SLEEP_STOP_REL_SET':
                        $this->SetValue($ident, (bool)$value);
                        break;
                    case 'TWO_SET_ENABLED':
                        $this->SetValue('TWO_SET_ENABLED', (bool)$value);
                        break;
                    case 'HEAT_SET_TEMP':
                    case 'COOL_SET_TEMP':
                    case 'AUTO_SET_TEMP':
                        $this->SetValue($ident, (float)$value);
                        break;
                }
                // Optional: kurzer Auto-Refresh
                if (method_exists($this, 'RegisterOnceTimer')) {
                    @ $this->RegisterOnceTimer('RefreshAfterControl', 'LGTQD_UpdateStatus($_IPS["TARGET"]);');
                }
                return true;
            }
        } catch (\Throwable $e) {
        }
        return false;
    }

    private function isPowerOnFromStatus(): bool
    {
        try {
            $last = $this->ReadAttributeString('LastStatus');
            $arr = json_decode((string)$last, true);
            if (is_array($arr)) {
                $flat = $this->flatten($arr);
                $op = $flat['operation.airConOperationMode'] ?? null;
                if (is_string($op) && $op !== '') {
                    $u = strtoupper((string)$op);
                    if ($u === 'POWER_OFF') return false;
                    if ($u === 'POWER_ON') return true;
                }
                $rs = $flat['runState.currentState'] ?? null;
                if (is_string($rs) && $rs !== '') {
                    $u = strtoupper((string)$rs);
                    if (strpos($u, 'OFF') !== false) return false;
                    if (strpos($u, 'ON') !== false) return true;
                }
                $jm = $flat['airConJobMode.currentJobMode'] ?? null;
                if (is_string($jm) && $jm !== '') {
                    $u = strtoupper((string)$jm);
                    if ($u === 'POWER_OFF') return false;
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        // Fallback: Versuche aus aktueller Variable POWER zu lesen
        $vid = $this->getVarId('POWER');
        if ($vid > 0) {
            $v = @GetValueBoolean($vid);
            if (is_bool($v)) return $v;
        }
        // Konservativ: annehmen, dass Gerät AN ist, um verbotene POWER_ON-Kommandos zu vermeiden
        return true;
    }

    private function ensureVariable(int $parentId, string $ident, string $name, int $type, string $profile = ''): int
    {
        $vid = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($vid && IPS_ObjectExists($vid)) {
            // Translate variable name via locale.json
            $tname = method_exists($this, 'Translate') ? $this->Translate($name) : $name;
            if (IPS_GetName($vid) !== $tname) IPS_SetName($vid, $tname);
            if ($profile !== '' && IPS_GetVariable($vid)['VariableCustomProfile'] !== $profile) {
                IPS_SetVariableCustomProfile($vid, $profile);
            }
            // Ensure requested timer variables are hidden (even if they already existed)
            $hideIdents = [
                'TIMER_START_REL_HOUR','TIMER_STOP_REL_HOUR',
                'TIMER_START_REL_MIN','TIMER_STOP_REL_MIN',
                'SLEEP_STOP_REL_HOUR','SLEEP_STOP_REL_MIN',
                // Absolute timer helper vars also hidden
                'TIMER_START_ABS_HOUR','TIMER_START_ABS_MIN',
                'TIMER_STOP_ABS_HOUR','TIMER_STOP_ABS_MIN',
                // Hide info/status containers from UI
                'INFO','STATUS',
                // Hide info/status containers from UI
                'LASTUPDATE'
            ];
            if (in_array($ident, $hideIdents, true)) {
                @IPS_SetHidden($vid, true);
            }
            return $vid;
        }
        $vid = IPS_CreateVariable($type);
        IPS_SetParent($vid, $parentId);
        IPS_SetIdent($vid, $ident);
        // Translate variable name via locale.json
        $tname = method_exists($this, 'Translate') ? $this->Translate($name) : $name;
        IPS_SetName($vid, $tname);
        if ($profile !== '') IPS_SetVariableCustomProfile($vid, $profile);
        // Hide requested timer variables on creation
        $hideIdents = [
            'TIMER_START_REL_HOUR','TIMER_STOP_REL_HOUR',
            'TIMER_START_REL_MIN','TIMER_STOP_REL_MIN',
            'SLEEP_STOP_REL_HOUR','SLEEP_STOP_REL_MIN',
            // Absolute Timer H/M ebenfalls verstecken
            'TIMER_START_ABS_HOUR','TIMER_START_ABS_MIN',
            'TIMER_STOP_ABS_HOUR','TIMER_STOP_ABS_MIN',
            // Hide info/status containers from UI
            'INFO','STATUS'
        ];
        if (in_array($ident, $hideIdents, true)) {
            @IPS_SetHidden($vid, true);
        }
        return $vid;
    }

    private function getVarId(string $ident): int
    {
        return (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    }

    private function getCapabilityEngine(): CapabilityEngine
    {
        return new CapabilityEngine($this->InstanceID, __DIR__);
    }

    private function applyPresentationsFromCapabilities(array $profile, string $deviceType): void
    {
        try {
            $eng = $this->getCapabilityEngine();
            $eng->loadCapabilities($deviceType, $profile);
            $caps = $eng->getDescriptors();
            $flat = $this->flatten($profile);
            foreach ($caps as $cap) {
                $ident = (string)($cap['ident'] ?? '');
                if ($ident === '') continue;
                $pres = $cap['presentation'] ?? null;
                if (!is_array($pres)) continue;
                $kind = strtolower((string)($pres['kind'] ?? ''));
                $arr = [];
                if ($kind === 'switch') {
                    $arr['PRESENTATION'] = self::PRES_SWITCH;
                    if (isset($pres['captionOn']))  $arr['CAPTION_ON']  = (string)$pres['captionOn'];
                    if (isset($pres['captionOff'])) $arr['CAPTION_OFF'] = (string)$pres['captionOff'];
                } elseif ($kind === 'slider') {
                    $arr['PRESENTATION'] = self::PRES_SLIDER;
                    // static range
                    $min = $pres['range']['min']  ?? null;
                    $max = $pres['range']['max']  ?? null;
                    $step= $pres['range']['step'] ?? null;
                    // dynamic range from profile
                    if ((!is_numeric($min) || !is_numeric($max) || !is_numeric($step)) && isset($pres['rangeFromProfile'])) {
                        $rfp = $pres['rangeFromProfile'];
                        $min = $min ?? $this->firstNumericByPaths($flat, (array)($rfp['min'] ?? []));
                        $max = $max ?? $this->firstNumericByPaths($flat, (array)($rfp['max'] ?? []));
                        $step= $step?? $this->firstNumericByPaths($flat, (array)($rfp['step'] ?? []));
                    }
                    if (is_numeric($min))  $arr['MIN'] = (float)$min;
                    if (is_numeric($max))  $arr['MAX'] = (float)$max;
                    if (!is_numeric($step)) { $step = 1.0; } // default to 1.0 to avoid IPS default 5
                    $arr['STEP_SIZE'] = (float)$step;
                    // suffix: prefer top-level, else range.suffix
                    if (isset($pres['suffix'])) {
                        $arr['SUFFIX'] = (string)$pres['suffix'];
                    } elseif (isset($pres['range']['suffix'])) {
                        $arr['SUFFIX'] = (string)$pres['range']['suffix'];
                    }
                    // digits: prefer top-level, else range.digits, else derive from step
                    if (isset($pres['digits'])) {
                        $arr['DIGITS'] = (int)$pres['digits'];
                    } elseif (isset($pres['range']['digits'])) {
                        $arr['DIGITS'] = (int)$pres['range']['digits'];
                    } else {
                        $s = is_numeric($step) ? (float)$step : 1.0;
                        $arr['DIGITS'] = ($s >= 1.0) ? 0 : (($s >= 0.5) ? 1 : 2);
                    }
                } elseif ($kind === 'buttons') {
                    $arr['PRESENTATION'] = self::PRES_BUTTONS;
                    $options = [];
                    // Static options provided
                    if (isset($pres['options']) && is_array($pres['options'])) {
                        foreach ($pres['options'] as $op) {
                            if (!is_array($op)) continue;
                            $val = $op['value'] ?? null; $cap = $op['caption'] ?? null;
                            if ($val === null || $cap === null) continue;
                            
                            // Use color from JSON if available, otherwise default
                            $color = $op['color'] ?? -1;
                            
                            $options[] = [
                                'Value' => (int)$val,
                                'Caption' => (string)$cap,
                                'IconActive' => false,
                                'IconValue' => '',
                                'Color' => (int)$color
                            ];
                        }
                    } else {
                        // Try to derive from read.map (string->code)
                        $read = $cap['read'] ?? [];
                        $map  = is_array($read) ? ($read['map'] ?? []) : [];
                        if (is_array($map)) {
                            // Build options sorted by value
                            $pairs = [];
                            foreach ($map as $key => $code) {
                                if (is_numeric($code)) {
                                    $pairs[] = ['code'=>(int)$code,'key'=>(string)$key];
                                }
                            }
                            usort($pairs, fn($a,$b)=>$a['code']<=>$b['code']);
                            foreach ($pairs as $p) {
                                $code = (int)$p['code'];
                                $caption = (string)$p['key'];
                                $options[] = [
                                    'Value' => $code,
                                    'Caption' => $caption,
                                    'IconActive' => false,
                                    'IconValue' => '',
                                    'Color' => -1
                                ];
                            }
                        }
                    }
                    if (!empty($options)) {
                        $arr['LAYOUT'] = 1; // Use layout 1 for button groups
                        $arr['OPTIONS'] = json_encode($options, JSON_UNESCAPED_UNICODE); // Store as JSON string
                    } else {
                    }
                } elseif ($kind === 'value') {
                    $arr['PRESENTATION'] = self::PRES_VALUE;
                    if (isset($pres['suffix'])) $arr['SUFFIX'] = (string)$pres['suffix'];
                    if (isset($pres['digits'])) $arr['DIGITS'] = (int)$pres['digits'];
                }
                if (!empty($arr)) {
                    $this->setVarPresentation($ident, $arr);
                } else {
                }
                
                // Also check if variable exists and log its current state
                $vid = $this->getVarId($ident);
                if ($vid > 0) {
                    $varInfo = @IPS_GetVariable($vid);
                    if (is_array($varInfo)) {
                        $hasPresentation = isset($varInfo['VariableCustomPresentation']);
                        $hasAction = isset($varInfo['VariableCustomAction']) && $varInfo['VariableCustomAction'] > 0;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private function firstNumericByPaths(array $flat, array $paths): ?float
    {
        foreach ($paths as $p) {
            $p = (string)$p;
            // try exact
            $candidates = [$p];
            // also try under property.* (profile schemas may prefix with 'property')
            $candidates[] = 'property.' . $p;
            // and common array indices (property.0.*, property.1.*)
            for ($i = 0; $i <= 4; $i++) {
                $candidates[] = 'property.' . $i . '.' . $p;
            }
            // Many LG profile examples wrap content under value.property[*]
            $candidates[] = 'value.' . $p;
            $candidates[] = 'value.property.' . $p;
            for ($i = 0; $i <= 4; $i++) {
                $candidates[] = 'value.property.' . $i . '.' . $p;
            }
            // Gateway may return envelope { success: true, profile: {...} }
            $candidates[] = 'profile.' . $p;
            $candidates[] = 'profile.property.' . $p;
            for ($i = 0; $i <= 4; $i++) {
                $candidates[] = 'profile.property.' . $i . '.' . $p;
            }
            $candidates[] = 'profile.value.' . $p;
            $candidates[] = 'profile.value.property.' . $p;
            for ($i = 0; $i <= 4; $i++) {
                $candidates[] = 'profile.value.property.' . $i . '.' . $p;
            }
            foreach ($candidates as $cand) {
                $v = $flat[$cand] ?? null;
                if (is_numeric($v)) return (float)$v;
            }
        }
        return null;
    }

    private function setValueByVarType(string $ident, $value): void
    {
        $vid = $this->getVarId($ident);
        if ($vid > 0) {
            $var = @IPS_GetVariable($vid);
            if (!is_array($var)) return;
            $vt = (int)($var['VariableType'] ?? -1);
            switch ($vt) {
                case 0: @SetValueBoolean($vid, (bool)$value); break;
                case 1: @SetValueInteger($vid, (int)$value); break;
                case 2: @SetValueFloat($vid, (float)$value); break;
                case 3: @SetValueString($vid, (string)$value); break;
                default: /* ignore */ break;
            }
        }
    }

    // Timer-Callback: Stellt Parent-Verbindung her
    public function EnsureConnected(): void
    {
        // Always ensure parent of required type
        if (method_exists($this, 'HasActiveParent') && $this->HasActiveParent()) {
            return;
        }
        $this->ConnectParent(self::GATEWAY_MODULE_GUID);
    }

    private function sendAction(string $action, array $params = []): string
    {
        // Sicherstellen, dass Parent aktiv ist
        if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
            $this->EnsureConnected();
        }
        if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
            throw new \Exception('Kein aktiver Parent verbunden (LG ThinQ Bridge). Bitte Bridge-Instanz prüfen oder erstellen.');
        }
        $buffer = array_merge(['Action' => $action], $params);
        $packet = [
            'DataID' => self::DATA_FLOW_GUID,
            'Buffer' => json_encode($buffer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ];
        $result = $this->SendDataToParent(json_encode($packet));
        if (!is_string($result)) {
            return '';
        }
        // Im Gateway liefern wir JSON mit { success: bool, ... } oder direkt Nutzdaten
        // Für Bequemlichkeit extrahieren wir ggf. Felder
        $dec = json_decode($result, true);
        if (is_array($dec)) {
            if (($dec['success'] ?? null) === false) {
            }
            if (isset($dec['devices'])) return json_encode($dec['devices']);
            if (isset($dec['status']))  return json_encode($dec['status']);
        }
        return $result;
    }

    // -------------------- Gerätespezifische Variablen/Profiles --------------------
    private function SetupDeviceVariables(): void
    {
        $deviceID  = (string)$this->ReadPropertyString('DeviceID');
        if ($deviceID === '') {
            return;
        }
        // Ensure parent is connected - skip strict check during ApplyChanges
        try {
            if (method_exists($this, 'HasActiveParent') && !$this->HasActiveParent()) {
                $this->EnsureConnected();
            }
        } catch (\Throwable $e) {
            // Don't stop setup for bridge issues - continue with capability setup
        }

        // Device-Metadaten aus Gateway ermitteln
        $type = (string)$this->ReadAttributeString('DeviceType');
        if ($type === '') {
            try {
                $listJson = $this->sendAction('GetDevices');
                $list = json_decode((string)$listJson, true);
                if (is_array($list)) {
                    foreach ($list as $d) {
                        $id = $d['deviceId'] ?? ($d['id'] ?? null);
                        if (is_array($d) && ($d['deviceId'] ?? '') === $deviceID) {
                            $type = (string)($d['deviceType'] ?? '');
                            break;
                        }
                    }
                } else {
                }
            } catch (\Throwable $e) {
                $type = 'ac'; // Fallback to air conditioner
            }
            $this->WriteAttributeString('DeviceType', $type);
        }
        // Variable creation and presentations are now provided by Capability descriptors

        // Timer sliders are created and configured via capability descriptors (e.g., ac.json)

        // Hour/Minute timer helpers are handled by capability descriptors (with visibility: hidden)

        // Optional: Präsentationen dynamisch aus dem Geräteprofil ableiten
        try {
            $profileJson = $this->sendAction('GetProfile', ['DeviceID' => $deviceID]);
        } catch (\Throwable $e) {
            $profileJson = '{}'; // Empty profile as fallback
        }
        $profileResponse = json_decode((string)$profileJson, true);
        
        // Extract actual profile from response wrapper
        $profile = null;
        if (is_array($profileResponse)) {
            if (isset($profileResponse['profile']) && is_array($profileResponse['profile'])) {
                $profile = $profileResponse['profile'];
            } elseif (isset($profileResponse['property']) && is_array($profileResponse['property'])) {
                $profile = $profileResponse['property'];
            } elseif (isset($profileResponse['success']) && $profileResponse['success'] === false) {
                return;
            } else {
                // Maybe the response IS the profile directly
                $profile = $profileResponse;
            }
        }
        
        if (is_array($profile) && !empty($profile)) {
            // Speichere Profil zur späteren Auswertung/Steuerbarkeit
            $this->WriteAttributeString('LastProfile', json_encode($profile));
        } else {
            $profile = [];
        }
        
        // Präsentationen und Aktionen kommen ausschließlich aus den Capability-Deskriptoren
        // CapabilityEngine: Variablen/Aktionen gemäß Deskriptoren sicherstellen
        try {
            $statusArr = null;
            $lastRaw = (string)$this->ReadAttributeString('LastStatus');
            $tmp = @json_decode($lastRaw, true);
            if (is_array($tmp)) { $statusArr = $tmp; }
            $eng = $this->getCapabilityEngine();
            $eng->ensureVariables($profile, $statusArr, $type);
            
            // Namen aus Capabilities anwenden (mit Übersetzung)
            $this->applyNamesFromCapabilities($profile, (string)$type);
            // Präsentationen aus Capabilities anwenden (Buttons/Slider mit Range/Options)
            $this->applyPresentationsFromCapabilities($profile, (string)$type);
            
            // Reassert actions requested on setup from capability descriptors
            try {
                $eng2 = $this->getCapabilityEngine();
                $eng2->loadCapabilities((string)$type, $profile);
                $eng2->reassertActionsOnSetup();
            } catch (\Throwable $e2) {
            }
            
            // SIMPLIFIED ACTION ENABLING - Direct approach
            $directIdents = ['POWER', 'HVAC_MODE', 'FAN_MODE', 'SWING', 'SET_TEMP', 'POWER_SAVE', 'TIMER_START_REL_TIME', 'TIMER_STOP_REL_TIME'];
            foreach ($directIdents as $directIdent) {
                $vid = $this->getVarId($directIdent);
                if ($vid > 0) {
                    try {
                        $this->EnableAction($directIdent);
                    } catch (\Throwable $e) {
                    }
                }
            }
            
            try {
                // Additionally, ensure actions are attached via module API for all relevant idents
                try {
                    $idents = $eng2->listIdentsToEnableOnSetup();
                    if (is_array($idents)) {
                        foreach ($idents as $identToEnable) {
                            $vid = $this->getVarId((string)$identToEnable);
                            if (method_exists($this, 'EnableAction') && $vid > 0) {
                                
                                try {
                                    $this->EnableAction((string)$identToEnable);
                                    
                                    // Verify action was set
                                    $varInfo = @IPS_GetVariable($vid);
                                    if (is_array($varInfo)) {
                                        $customAction = $varInfo['VariableCustomAction'] ?? null;
                                        $variableAction = $varInfo['VariableAction'] ?? null;
                                    }
                                } catch (\Throwable $e) {
                                }
                            } else {
                            }
                        }
                    }
                } catch (\Throwable $e3) {
                }
                // New: Aggressive enable for all idents that currently request enablement
                try {
                    $eng3 = $this->getCapabilityEngine();
                    $eng3->loadCapabilities((string)$type, $profile);
                    $idents2 = $eng3->listIdentsToEnable();
                    if (is_array($idents2)) {
                        foreach ($idents2 as $identToEnable) {
                            $vid = $this->getVarId((string)$identToEnable);
                            if (method_exists($this, 'EnableAction') && $vid > 0) {
                                try {
                                    $this->EnableAction((string)$identToEnable);
                                } catch (\Throwable $e) {
                                }
                            }
                        }
                    }
                } catch (\Throwable $e4) {
                }
                // Final fallback: enable a well-known set of idents if present
                try {
                    $fallbackIdents = [
                        'POWER','HVAC_MODE','FAN_MODE','SWING','SET_TEMP','POWER_SAVE','DISPLAY_LIGHT','AIR_CLEAN',
                        'TIMER_START_REL_TIME','TIMER_STOP_REL_TIME','SLEEP_STOP_REL_TIME',
                        'TIMER_START_REL_HOUR','TIMER_START_REL_MIN','TIMER_STOP_REL_HOUR','TIMER_STOP_REL_MIN',
                        'SLEEP_STOP_REL_HOUR','SLEEP_STOP_REL_MIN'
                    ];
                    $countEnabled = 0;
                    foreach ($fallbackIdents as $fid) {
                        $vid = $this->getVarId($fid);
                        if ($vid > 0 && method_exists($this, 'EnableAction')) {
                            try {
                                $this->EnableAction($fid);
                                $countEnabled++;
                            } catch (\Throwable $e) {
                            }
                        }
                    }
                } catch (\Throwable $e5) {
                }
            } catch (\Throwable $e2) {
            }
        } catch (\Throwable $e) {
        }
        // Aktionsfähigkeit nach Profil setzen (nur für bereits vorhandene Variablen)
        try { $this->applyActionEnableRulesFromProfile($this->flatten($profile)); } catch (\Throwable $e) { $this->SendDebug('applyActionEnableRulesFromProfile Error', $e->getMessage(), 0); }
        // Device-specific variables are handled exclusively by capability descriptors

        // Ensure key AC variables are visible (fix legacy hidden state)
        foreach (['SET_TEMP','CUR_TEMP','HVAC_MODE','FAN_MODE'] as $identFix) {
            $vid = $this->getVarId($identFix);
            if ($vid > 0) { @IPS_SetHidden($vid, false); }
        }

        // Initialstatus laden und Variablen füllen
        $json = $this->sendAction('GetStatus', ['DeviceID' => $deviceID]);
        $status = json_decode((string)$json, true);
        if (is_array($status)) {
            $this->updateFromStatus($status);
        }
    }

    private function getCurrentJobMode(): ?string
    {
        // Versuche aus LastStatus zu lesen
        $last = $this->ReadAttributeString('LastStatus');
        $arr = json_decode((string)$last, true);
        if (is_array($arr)) {
            $flat = $this->flatten($arr);
            $mode = $this->readFirstKeyContains($flat, ['airConJobMode.currentJobMode']);
            if (is_string($mode) && $mode !== '') {
                return strtoupper((string)$mode);
            }
        }
        // Fallback: Live-Status abrufen
        try {
            $deviceID  = (string)$this->ReadPropertyString('DeviceID');
            if ($deviceID !== '') {
                $json = $this->sendAction('GetStatus', ['DeviceID' => $deviceID]);
                $status = json_decode((string)$json, true);
                if (is_array($status)) {
                    $flat = $this->flatten($status);
                    $mode = $this->readFirstKeyContains($flat, ['airConJobMode.currentJobMode']);
                    if (is_string($mode) && $mode !== '') {
                        return strtoupper((string)$mode);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    private function setVarPresentation(string $ident, array $presentation): void
    {
        $vid = $this->getVarId($ident);
        
        if ($vid <= 0) {
            return;
        }
        
        $presentationApiAvailable = function_exists('IPS_SetVariableCustomPresentation');
        if (!$presentationApiAvailable) {
        }
        
        // Get current variable info for debugging
        $varInfo = @IPS_GetVariable($vid);
        if (!is_array($varInfo)) {
            return;
        }
        
        
        // Wichtig: Profil leeren, da Profile und Darstellungen sich gegenseitig ausschließen (nur wenn Presentation API verfügbar)
        if ($presentationApiAvailable) {
            $profileCleared = @IPS_SetVariableCustomProfile($vid, '');
        }
        
        // OPTIONS bei Aufzählung als JSON-String übergeben
        try {
            // Translate captions, suffixes and options before applying
            $presentation = $this->translatePresentation($presentation);
            if (isset($presentation['PRESENTATION'])) {
                $pres = $presentation['PRESENTATION'];
                // Only encode OPTIONS as JSON for Enumeration, not for real Buttons
                $isEnumeration = (defined('VARIABLE_PRESENTATION_ENUMERATION') && $pres === VARIABLE_PRESENTATION_ENUMERATION);
                if ($isEnumeration && isset($presentation['OPTIONS']) && is_array($presentation['OPTIONS'])) {
                    $presentation['OPTIONS'] = json_encode($presentation['OPTIONS'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        } catch (\Throwable $e) {
        }
        

        if ($presentationApiAvailable) {
            $result = @IPS_SetVariableCustomPresentation($vid, $presentation);
        } else {
            // Fallback: emulate presentations via custom profiles
            $vt = (int)($varInfo['VariableType'] ?? -1);
            $pname = 'LGTQD.' . $ident;
            // Create or adjust profile
            if (!@IPS_VariableProfileExists($pname)) {
                @IPS_CreateVariableProfile($pname, $vt === VARIABLETYPE_BOOLEAN ? VARIABLETYPE_BOOLEAN : ($vt === VARIABLETYPE_FLOAT ? VARIABLETYPE_FLOAT : VARIABLETYPE_INTEGER));
            }
            // Reset basic profile props
            @IPS_SetVariableProfileText($pname, '', (string)($presentation['SUFFIX'] ?? ''));
            if (isset($presentation['DIGITS']) && $vt === VARIABLETYPE_FLOAT) {
                @IPS_SetVariableProfileDigits($pname, (int)$presentation['DIGITS']);
            }
            if (($presentation['PRESENTATION'] ?? '') === self::PRES_SLIDER) {
                $min = (float)($presentation['MIN'] ?? 0);
                $max = (float)($presentation['MAX'] ?? 100);
                $step= (float)($presentation['STEP_SIZE'] ?? 1);
                @IPS_SetVariableProfileValues($pname, $min, $max, $step);
            } elseif (($presentation['PRESENTATION'] ?? '') === self::PRES_BUTTONS) {
                // Clear and set associations (best-effort - IPS has no clear, we just overwrite common values)
                if (isset($presentation['OPTIONS']) && is_array($presentation['OPTIONS'])) {
                    foreach ($presentation['OPTIONS'] as $op) {
                        $val = (int)($op['Value'] ?? 0);
                        $cap = (string)($op['Caption'] ?? (string)$val);
                        @IPS_SetVariableProfileAssociation($pname, $val, $cap, '', -1);
                    }
                }
            }
            // Apply profile to variable
            @IPS_SetVariableCustomProfile($vid, $pname);
        }

        // Verify the presentation/profile was actually set
        $cur = @IPS_GetVariable($vid);
        if (is_array($cur)) {
            if ($presentationApiAvailable) {
                if (isset($cur['VariableCustomPresentation'])) {
                } else {
                }
            } else {
                $prof = $cur['VariableCustomProfile'] ?? '';
            }
            // Also check if there's a custom action set
            $customAction = $cur['VariableCustomAction'] ?? null;
            $varAction = $cur['VariableAction'] ?? null;
        } else {
        }
    }

    // --- i18n helpers ---
    private function t(string $s): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($s) : $s;
    }

    private function translatePresentation(array $presentation): array
    {
        // Switch captions
        if (isset($presentation['CAPTION_ON']))  { $presentation['CAPTION_ON']  = $this->t((string)$presentation['CAPTION_ON']); }
        if (isset($presentation['CAPTION_OFF'])) { $presentation['CAPTION_OFF'] = $this->t((string)$presentation['CAPTION_OFF']); }
        // Suffix
        if (isset($presentation['SUFFIX'])) { $presentation['SUFFIX'] = $this->t((string)$presentation['SUFFIX']); }
        // Options (array or JSON string prepared elsewhere)
        if (isset($presentation['OPTIONS'])) {
            if (is_string($presentation['OPTIONS'])) {
                $opts = @json_decode($presentation['OPTIONS'], true);
                if (is_array($opts)) {
                    foreach ($opts as &$o) {
                        if (isset($o['Caption'])) { $o['Caption'] = $this->t((string)$o['Caption']); }
                    }
                    unset($o);
                    $presentation['OPTIONS'] = $opts;
                }
            } elseif (is_array($presentation['OPTIONS'])) {
                foreach ($presentation['OPTIONS'] as &$o) {
                    if (isset($o['Caption'])) { $o['Caption'] = $this->t((string)$o['Caption']); }
                }
                unset($o);
            }
        }
        return $presentation;
    }

    private function setProfileAssociations(string $profile, array $map): void
    {
        // Entferne existierende Associations, indem wir Profil neu setzen
        // (IPS kennt keine direkte Clear-Funktion, also setzen wir überschreibend)
        foreach ($map as $value => $text) {
            @IPS_SetVariableProfileAssociation($profile, (int)$value, (string)$text, '', -1);
        }
    }

    private function updateFromStatus(array $status): void
    {
        $flat = $this->flatten($status);
        // Diagnose: Zeige verfügbare Schlüssel
        // Timer helper variable visibility is handled by capability descriptors

        // Power: aus operation.airConOperationMode ableiten (POWER_OFF => false, sonst true)
        $power = null;
        $opMode = $this->readFirstKeyContains($flat, ['operation.airConOperationMode']);
        if (is_string($opMode) && $opMode !== '') {
            $power = (strtoupper((string)$opMode) !== 'POWER_OFF');
        } else {
            // Fallbacks
            $powerKeys = ['power', 'is_on', 'isOn', 'airState.operation', 'air_state.operation', 'operation.on'];
            $power = $this->readFirstKeyAsBoolContains($flat, $powerKeys);
        }
        if ($power !== null) {
            @SetValueBoolean($this->getVarId('POWER'), $power);
        }

        // HVAC Mode: primär aus airConJobMode.currentJobMode, sonst opMode/mode
        $modeVal = $this->readFirstKeyContains($flat, ['airConJobMode.currentJobMode']);
        if ($modeVal === null) {
            $modeVal = $this->readFirstKeyContains($flat, ['airState.opMode', 'air_state.operation_mode', 'operation.mode', 'state.mode', 'opMode', 'mode']);
        }
        if ($modeVal !== null) {
            $mode = $this->mapHvacMode(strtolower((string)$modeVal));
            // Wenn POWER_OFF erkannt wurde, überschreibe Modus mit Off
            if (is_string($opMode) && strtoupper((string)$opMode) === 'POWER_OFF') {
                $mode = 0; // Off
            }
            @SetValueInteger($this->getVarId('HVAC_MODE'), $mode);
        }
        $jobModeUpper = is_string($modeVal) ? strtoupper((string)$modeVal) : null;

        // Fan Mode: airFlow.windStrength bevorzugen
        $fanVal = $this->readFirstKeyContains($flat, ['airFlow.windStrength']);
        if ($fanVal === null) {
            $fanVal = $this->readFirstKeyContains($flat, ['fanSpeed', 'windStrength', 'airState.windStrength', 'air_state.fan_mode', 'fan_mode', 'fan']);
        }
        if ($fanVal !== null) {
            $fan = $this->mapFanMode(strtolower((string)$fanVal));
            @SetValueInteger($this->getVarId('FAN_MODE'), $fan);
        }

        // Swing: Windrichtung Rotationen kombinieren
        $swingUpDown = $this->readFirstKeyAsBoolContains($flat, ['windDirection.rotateUpDown']);
        $swingLeftRight = $this->readFirstKeyAsBoolContains($flat, ['windDirection.rotateLeftRight']);
        $swing = null;
        if ($swingUpDown !== null || $swingLeftRight !== null) {
            $swing = (bool)($swingUpDown ?: false) || (bool)($swingLeftRight ?: false);
        } else {
            $swing = $this->readFirstKeyAsBoolContains($flat, ['swingMode', 'airState.swing', 'air_state.swing', 'swing']);
        }
        if ($swing !== null) {
            @SetValueBoolean($this->getVarId('SWING'), $swing);
        }

        // Temperatures: primär temperature.current/target
        $cur = $this->readFirstKeyAsFloatContains($flat, ['temperature.currentTemperature']);
        if ($cur === null) {
            $cur = $this->readFirstKeyAsFloatContains($flat, ['airState.tempState.current', 'air_state.current_temperature', 'currentTemperature', 'indoorTemperature', 'curr_temp', 'temperatureInUnits.0.currentTemperature']);
        }
        if ($cur !== null) {
            @SetValueFloat($this->getVarId('CUR_TEMP'), $cur);
        }
        // SetTemp abhängig vom aktuellen Job-Mode bevorzugt lesen
        $set = null;
        if ($jobModeUpper === 'HEAT') {
            $set = $this->readFirstKeyAsFloatContains($flat, ['temperature.heatTargetTemperature']);
        } elseif ($jobModeUpper === 'COOL' || $jobModeUpper === 'AIR_DRY') {
            $set = $this->readFirstKeyAsFloatContains($flat, ['temperature.coolTargetTemperature']);
        } elseif ($jobModeUpper === 'AUTO') {
            $set = $this->readFirstKeyAsFloatContains($flat, ['temperature.autoTargetTemperature']);
        }
        if ($set === null) {
            $set = $this->readFirstKeyAsFloatContains($flat, ['temperature.targetTemperature']);
        }
        if ($set === null) {
            $set = $this->readFirstKeyAsFloatContains($flat, ['airState.tempState.target', 'air_state.target_temperature', 'targetTemperature', 'setTemp', 'temperatureInUnits.0.targetTemperature', 'target_temp']);
        }
        if ($set !== null) {
            @SetValueFloat($this->getVarId('SET_TEMP'), $set);
        }

        // --- AC: Zusatzfelder ---
        // Power Save
        $ps = $this->readFirstKeyAsBoolContains($flat, ['powerSave.powerSaveEnabled']);
        if ($ps !== null) {
            $vid = $this->getVarId('POWER_SAVE');
            if ($vid > 0) { @SetValueBoolean($vid, $ps); }
        }
        // Display Light (ON/OFF -> bool)
        $dl = $this->readFirstKeyContains($flat, ['display.light']);
        if (is_string($dl) && $dl !== '') {
            $dlb = (strtoupper((string)$dl) === 'ON');
            $vid = $this->getVarId('DISPLAY_LIGHT');
            if ($vid > 0) { @SetValueBoolean($vid, $dlb); }
        }
        // Air Clean (START/STOP -> bool)
        $aclean = $this->readFirstKeyContains($flat, ['operation.airCleanOperationMode']);
        if (is_string($aclean) && $aclean !== '') {
            $acb = in_array(strtoupper((string)$aclean), ['START','ON'], true);
            $vid = $this->getVarId('AIR_CLEAN');
            if ($vid > 0) { @SetValueBoolean($vid, $acb); }
        }

        // Wind-Flags
        $windFlags = [
            'FOREST_WIND' => 'windDirection.forestWind',
            'AIR_GUIDE_WIND' => 'windDirection.airGuideWind',
            'HIGH_CEILING_WIND' => 'windDirection.highCeilingWind',
            'AUTO_FIT_WIND' => 'windDirection.autoFitWind',
            'CONCENTRATION_WIND' => 'windDirection.concentrationWind',
            'SWIRL_WIND' => 'windDirection.swirlWind'
        ];
        foreach ($windFlags as $ident => $key) {
            $val = $this->readFirstKeyAsBoolContains($flat, [$key]);
            if ($val !== null) {
                if ($this->getVarId($ident) === 0) {
                    $this->ensureVariable($this->InstanceID, $ident, str_replace('_',' ', $ident), VARIABLETYPE_BOOLEAN, '');
                    $this->setVarPresentation($ident, ['PRESENTATION' => self::PRES_SWITCH, 'CAPTION_ON'=>'On','CAPTION_OFF'=>'Off']);
                }
                @SetValueBoolean($this->getVarId($ident), $val);
            }
        }

        // Timer relativ/absolut & SleepTimer
        $mapBoolSet = function($s){ $u=strtoupper((string)$s); return $u==='SET' ? true : ($u==='UNSET' ? false : null); };
        $timerIntMap = [
            'TIMER_START_REL_HOUR' => ['timer.relativeHourToStart'],
            'TIMER_START_REL_MIN'  => ['timer.relativeMinuteToStart'],
            'TIMER_STOP_REL_HOUR'  => ['timer.relativeHourToStop'],
            'TIMER_STOP_REL_MIN'   => ['timer.relativeMinuteToStop'],
            'TIMER_START_ABS_HOUR' => ['timer.absoluteHourToStart'],
            'TIMER_START_ABS_MIN'  => ['timer.absoluteMinuteToStart'],
            'TIMER_STOP_ABS_HOUR'  => ['timer.absoluteHourToStop'],
            'TIMER_STOP_ABS_MIN'   => ['timer.absoluteMinuteToStop'],
            'SLEEP_STOP_REL_HOUR'  => ['sleepTimer.relativeHourToStop'],
            'SLEEP_STOP_REL_MIN'   => ['sleepTimer.relativeMinuteToStop']
        ];
        foreach ($timerIntMap as $ident => $keys) {
            $v = $this->readFirstKeyAsFloatContains($flat, $keys);
            if ($v !== null) {
                $vid = $this->getVarId($ident);
                if ($vid > 0) { @SetValueInteger($vid, (int)$v); }
            }
        }
        $timerSetMap = [
            'TIMER_START_REL_SET' => ['timer.relativeStartTimer'],
            'TIMER_STOP_REL_SET'  => ['timer.relativeStopTimer'],
            'TIMER_START_ABS_SET' => ['timer.absoluteStartTimer'],
            'TIMER_STOP_ABS_SET'  => ['timer.absoluteStopTimer'],
            'SLEEP_STOP_REL_SET'  => ['sleepTimer.relativeStopTimer']
        ];
        foreach ($timerSetMap as $ident => $keys) {
            $raw = $this->readFirstKeyContains($flat, $keys);
            if (is_string($raw) && $raw !== '') {
                $b = $mapBoolSet($raw);
                if ($b !== null) {
                    $vid = $this->getVarId($ident);
                    if ($vid > 0) { @SetValueBoolean($vid, $b); }
                }
            }
        }

        // Kombinierte Eingabe für relative Start/Stop-Zeit (Integer in Minuten)
        $hStart = $this->readFirstKeyAsFloatContains($flat, ['timer.relativeHourToStart']);
        $mStart = $this->readFirstKeyAsFloatContains($flat, ['timer.relativeMinuteToStart']);
        if ($hStart !== null || $mStart !== null) {
            $total = (int)($hStart ?? 0) * 60 + (int)($mStart ?? 0);
            $vid = $this->getVarId('TIMER_START_REL_TIME');
            if ($vid > 0 && $total >= 1) { @SetValueInteger($vid, $total); }
        }
        $hStop = $this->readFirstKeyAsFloatContains($flat, ['timer.relativeHourToStop']);
        $mStop = $this->readFirstKeyAsFloatContains($flat, ['timer.relativeMinuteToStop']);
        if ($hStop !== null || $mStop !== null) {
            $total = (int)($hStop ?? 0) * 60 + (int)($mStop ?? 0);
            $vid = $this->getVarId('TIMER_STOP_REL_TIME');
            if ($vid > 0 && $total >= 1) { @SetValueInteger($vid, $total); }
        }

        // Sleep Stop (relativ) als kombinierter Sliderwert
        $hSleep = $this->readFirstKeyAsFloatContains($flat, ['sleepTimer.relativeHourToStop']);
        $mSleep = $this->readFirstKeyAsFloatContains($flat, ['sleepTimer.relativeMinuteToStop']);
        if ($hSleep !== null || $mSleep !== null) {
            $total = (int)($hSleep ?? 0) * 60 + (int)($mSleep ?? 0);
            $vid = $this->getVarId('SLEEP_STOP_REL_TIME');
            if ($vid > 0 && $total >= 1) { @SetValueInteger($vid, $total); }
        }

        // Two-Set
        $twoEn = $this->readFirstKeyAsBoolContains($flat, ['twoSetTemperature.twoSetEnabled']);
        if ($twoEn !== null) {
            if ($this->getVarId('TWO_SET_ENABLED') === 0) {
                $this->ensureVariable($this->InstanceID, 'TWO_SET_ENABLED', 'Two-Set Enabled', VARIABLETYPE_BOOLEAN, '');
                $this->setVarPresentation('TWO_SET_ENABLED', ['PRESENTATION'=> self::PRES_SWITCH, 'CAPTION_ON'=>'On','CAPTION_OFF'=>'Off']);
            }
            @SetValueBoolean($this->getVarId('TWO_SET_ENABLED'), $twoEn);
        }
        $hset = $this->readFirstKeyAsFloatContains($flat, ['twoSetTemperature.heatTargetTemperature']);
        if ($hset !== null) {
            if ($this->getVarId('HEAT_SET_TEMP') === 0) {
                $this->ensureVariable($this->InstanceID, 'HEAT_SET_TEMP', 'Heat Set Temperature', VARIABLETYPE_FLOAT, '');
            }
            @SetValueFloat($this->getVarId('HEAT_SET_TEMP'), $hset);
        }
        $cset = $this->readFirstKeyAsFloatContains($flat, ['twoSetTemperature.coolTargetTemperature']);
        if ($cset !== null) {
            if ($this->getVarId('COOL_SET_TEMP') === 0) {
                $this->ensureVariable($this->InstanceID, 'COOL_SET_TEMP', 'Kühlen Solltemperatur', VARIABLETYPE_FLOAT, '');
            }
            @SetValueFloat($this->getVarId('COOL_SET_TEMP'), $cset);
        }
        $aset = $this->readFirstKeyAsFloatContains($flat, ['temperature.autoTargetTemperature']);
        if ($aset !== null) {
            if ($this->getVarId('AUTO_SET_TEMP') === 0) {
                $this->ensureVariable($this->InstanceID, 'AUTO_SET_TEMP', 'Auto Solltemperatur', VARIABLETYPE_FLOAT, '');
            }
            @SetValueFloat($this->getVarId('AUTO_SET_TEMP'), $aset);
        }

        // Air Quality
        $aqMapFloat = [
            'PM1' => ['airQualitySensor.PM1'],
            'PM2' => ['airQualitySensor.PM2'],
            'PM10'=> ['airQualitySensor.PM10'],
            'HUMIDITY' => ['airQualitySensor.humidity'],
            'TOTAL_POLLUTION' => ['airQualitySensor.totalPollution'],
            'ODOR' => ['airQualitySensor.odor', 'airQualitySensor.oder']
        ];
        foreach ($aqMapFloat as $ident => $keys) {
            $v = $this->readFirstKeyAsFloatContains($flat, $keys);
            if ($v !== null) {
                if ($this->getVarId($ident) === 0) {
                    $type = ($ident === 'HUMIDITY' || $ident === 'TOTAL_POLLUTION' || $ident === 'ODOR') ? VARIABLETYPE_FLOAT : VARIABLETYPE_FLOAT;
                    $this->ensureVariable($this->InstanceID, $ident, $ident, $type, '');
                }
                @SetValueFloat($this->getVarId($ident), (float)$v);
            }
        }
        $aqMapString = [
            'TOTAL_POLLUTION_LEVEL' => ['airQualitySensor.totalPollutionLevel'],
            'ODOR_LEVEL' => ['airQualitySensor.odorLevel'],
            'MONITORING_ENABLED' => ['airQualitySensor.monitoringEnabled']
        ];
        foreach ($aqMapString as $ident => $keys) {
            $v = $this->readFirstKeyContains($flat, $keys);
            if (is_scalar($v)) {
                if ($this->getVarId($ident) === 0) {
                    $this->ensureVariable($this->InstanceID, $ident, $ident, VARIABLETYPE_STRING, '');
                }
                @SetValueString($this->getVarId($ident), (string)$v);
            }
        }

        // Filter
        $fUsed = $this->readFirstKeyAsFloatContains($flat, ['filterInfo.usedTime']);
        if ($fUsed !== null) {
            if ($this->getVarId('FILTER_USED_TIME') === 0) {
                $this->ensureVariable($this->InstanceID, 'FILTER_USED_TIME', 'Filter Nutzungszeit', VARIABLETYPE_INTEGER, '');
            }
            @SetValueInteger($this->getVarId('FILTER_USED_TIME'), (int)$fUsed);
        }
        $fLife = $this->readFirstKeyAsFloatContains($flat, ['filterInfo.filterLifetime']);
        if ($fLife !== null) {
            if ($this->getVarId('FILTER_LIFETIME') === 0) {
                $this->ensureVariable($this->InstanceID, 'FILTER_LIFETIME', 'Filter Restzeit', VARIABLETYPE_INTEGER, '');
            }
            @SetValueInteger($this->getVarId('FILTER_LIFETIME'), (int)$fLife);
        }
        $fRemain = $this->readFirstKeyAsFloatContains($flat, ['filterInfo.filterRemainPercent']);
        if ($fRemain !== null) {
            if ($this->getVarId('FILTER_REMAIN_PERCENT') === 0) {
                $this->ensureVariable($this->InstanceID, 'FILTER_REMAIN_PERCENT', 'Filter Rest (%)', VARIABLETYPE_INTEGER, '');
                $this->setVarPresentation('FILTER_REMAIN_PERCENT', ['PRESENTATION'=> self::PRES_VALUE, 'SUFFIX'=>' %']);
            }
            @SetValueInteger($this->getVarId('FILTER_REMAIN_PERCENT'), (int)$fRemain);
        }

        // Washer/Dryer/Dishwasher
        $runState = $this->readFirstKeyContains($flat, ['runState.currentState', 'processState.currentState', 'currentState', 'runState', 'processState', 'washState', 'state']);
        if ($runState !== null) {
            if ($this->getVarId('RUN_STATE') === 0) {
                $this->ensureVariable($this->InstanceID, 'RUN_STATE', 'Programmstatus', VARIABLETYPE_STRING, '');
                $this->setVarPresentation('RUN_STATE', ['PRESENTATION' => self::PRES_VALUE]);
            }
            @SetValueString($this->getVarId('RUN_STATE'), (string)$runState);
        }
        $remainMin = null;
        if (($h = $this->readFirstKeyAsFloatContains($flat, ['remainTimeHour', 'remainingTimeHour', 'remainHour'])) !== null || ($m = $this->readFirstKeyAsFloatContains($flat, ['remainTimeMinute', 'remainingTimeMinute', 'remainMinute'])) !== null) {
            $remainMin = (int)((int)($h ?? 0) * 60 + (int)($m ?? 0));
        } else {
            $remainMin = (int)($this->readFirstKeyAsFloatContains($flat, ['remainingTime', 'remainMinute']) ?? 0);
        }
        if ($remainMin !== null) {
            if ($this->getVarId('REMAIN_MIN') === 0) {
                $this->ensureVariable($this->InstanceID, 'REMAIN_MIN', 'Restzeit', VARIABLETYPE_INTEGER, '');
                $this->setVarPresentation('REMAIN_MIN', ['PRESENTATION' => self::PRES_VALUE, 'SUFFIX' => ' min']);
            }
            @SetValueInteger($this->getVarId('REMAIN_MIN'), (int)$remainMin);
        }
        $progress = $this->readFirstKeyAsFloatContains($flat, ['process.progress', 'progress']);
        if ($progress !== null) {
            if ($this->getVarId('PROGRESS') === 0) {
                $this->ensureVariable($this->InstanceID, 'PROGRESS', 'Fortschritt', VARIABLETYPE_INTEGER, '');
                $this->setVarPresentation('PROGRESS', ['PRESENTATION' => self::PRES_VALUE, 'SUFFIX' => ' %']);
            }
            @SetValueInteger($this->getVarId('PROGRESS'), (int)round($progress));
        }
        $doorOpen = $this->readFirstKeyAsBoolContains($flat, ['door_state.open', 'door.open', 'doorOpen', 'doorlock', 'doorLock', 'door']);
        if ($doorOpen !== null) {
            if ($this->getVarId('DOOR_OPEN') === 0) {
                $this->ensureVariable($this->InstanceID, 'DOOR_OPEN', 'Door Open', VARIABLETYPE_BOOLEAN, '');
                $this->setVarPresentation('DOOR_OPEN', ['PRESENTATION' => self::PRES_SWITCH, 'CAPTION_ON' => 'Open', 'CAPTION_OFF' => 'Closed']);
            }
            @SetValueBoolean($this->getVarId('DOOR_OPEN'), $doorOpen);
        }
        $child = $this->readFirstKeyAsBoolContains($flat, ['childLock', 'child_lock']);
        if ($child !== null) {
            if ($this->getVarId('CHILD_LOCK') === 0) {
                $this->ensureVariable($this->InstanceID, 'CHILD_LOCK', 'Child Lock', VARIABLETYPE_BOOLEAN, '');
                $this->setVarPresentation('CHILD_LOCK', ['PRESENTATION' => self::PRES_SWITCH, 'CAPTION_ON' => 'On', 'CAPTION_OFF' => 'Off']);
            }
            @SetValueBoolean($this->getVarId('CHILD_LOCK'), $child);
        }
        $wat = $this->readFirstKeyContains($flat, ['waterTemp', 'waterTemperature']);
        if ($wat !== null) {
            if ($this->getVarId('WATER_TEMP') === 0) {
                $this->ensureVariable($this->InstanceID, 'WATER_TEMP', 'Water Temperature', VARIABLETYPE_INTEGER, '');
                $this->setVarPresentation('WATER_TEMP', ['PRESENTATION' => self::PRES_VALUE, 'SUFFIX' => ' °C']);
            }
            @SetValueInteger($this->getVarId('WATER_TEMP'), (int)$wat);
        }
        $spin = $this->readFirstKeyContains($flat, ['spinSpeed', 'spin']);
        if ($spin !== null) {
            if ($this->getVarId('SPIN') === 0) {
                $this->ensureVariable($this->InstanceID, 'SPIN', 'Schleudern', VARIABLETYPE_INTEGER, '');
                $this->setVarPresentation('SPIN', ['PRESENTATION' => self::PRES_VALUE]);
            }
            @SetValueInteger($this->getVarId('SPIN'), (int)$spin);
        }
        $prog = $this->readFirstKeyContains($flat, ['washCourse', 'course', 'program', 'cycle']);
        if ($prog !== null) {
            if ($this->getVarId('PROGRAM') === 0) {
                $this->ensureVariable($this->InstanceID, 'PROGRAM', 'Programm', VARIABLETYPE_STRING, '');
                $this->setVarPresentation('PROGRAM', ['PRESENTATION' => self::PRES_VALUE]);
            }
            @SetValueString($this->getVarId('PROGRAM'), (string)$prog);
        }

        // Fridge
        $ft = $this->readFirstKeyAsFloatContains($flat, ['fridgeTemperature', 'refTemp', 'tempRefrigerator']);
        if ($ft !== null) {
            if ($this->getVarId('FRIDGE_TEMP') === 0) {
                $this->ensureVariable($this->InstanceID, 'FRIDGE_TEMP', 'Kühlschrank Temp', VARIABLETYPE_FLOAT, '');
                $this->setVarPresentation('FRIDGE_TEMP', ['PRESENTATION' => self::PRES_VALUE, 'SUFFIX' => ' °C', 'DIGITS' => 1]);
            }
            @SetValueFloat($this->getVarId('FRIDGE_TEMP'), (float)$ft);
        }
        $fz = $this->readFirstKeyAsFloatContains($flat, ['freezerTemperature', 'frzTemp', 'tempFreezer']);
        if ($fz !== null) {
            if ($this->getVarId('FREEZER_TEMP') === 0) {
                $this->ensureVariable($this->InstanceID, 'FREEZER_TEMP', 'Gefrierschrank Temp', VARIABLETYPE_FLOAT, '');
                $this->setVarPresentation('FREEZER_TEMP', ['PRESENTATION' => self::PRES_VALUE, 'SUFFIX' => ' °C', 'DIGITS' => 0]);
            }
            @SetValueFloat($this->getVarId('FREEZER_TEMP'), (float)$fz);
        }
        $df = $this->readFirstKeyAsBoolContains($flat, ['doorRefrigerator', 'door_fridge', 'refrigeratorDoorOpen']);
        if ($df !== null) {
            if ($this->getVarId('DOOR_FRIDGE') === 0) {
                $this->ensureVariable($this->InstanceID, 'DOOR_FRIDGE', 'Tür Kühlschrank', VARIABLETYPE_BOOLEAN, '');
                $this->setVarPresentation('DOOR_FRIDGE', ['PRESENTATION' => self::PRES_SWITCH, 'CAPTION_ON' => 'Offen', 'CAPTION_OFF' => 'Zu']);
            }
            @SetValueBoolean($this->getVarId('DOOR_FRIDGE'), $df);
        }
        $dz = $this->readFirstKeyAsBoolContains($flat, ['doorFreezer', 'door_freezer', 'freezerDoorOpen']);
        if ($dz !== null) {
            if ($this->getVarId('DOOR_FREEZER') === 0) {
                $this->ensureVariable($this->InstanceID, 'DOOR_FREEZER', 'Door Freezer', VARIABLETYPE_BOOLEAN, '');
                $this->setVarPresentation('DOOR_FREEZER', ['PRESENTATION' => self::PRES_SWITCH, 'CAPTION_ON' => 'Open', 'CAPTION_OFF' => 'Closed']);
            }
            @SetValueBoolean($this->getVarId('DOOR_FREEZER'), $dz);
        }
        $eco = $this->readFirstKeyAsBoolContains($flat, ['ecoMode', 'eco']);
        if ($eco !== null) {
            if ($this->getVarId('ECO') === 0) {
                $this->ensureVariable($this->InstanceID, 'ECO', 'Eco', VARIABLETYPE_BOOLEAN, '');
                $this->setVarPresentation('ECO', ['PRESENTATION' => self::PRES_SWITCH, 'CAPTION_ON' => 'On', 'CAPTION_OFF' => 'Off']);
            }
            @SetValueBoolean($this->getVarId('ECO'), $eco);
        }
        $ice = $this->readFirstKeyAsBoolContains($flat, ['expressFreeze', 'icePlus', 'express_frz']);
        if ($ice !== null) {
            if ($this->getVarId('ICE_PLUS') === 0) {
                $this->ensureVariable($this->InstanceID, 'ICE_PLUS', 'Ice Plus', VARIABLETYPE_BOOLEAN, '');
                $this->setVarPresentation('ICE_PLUS', ['PRESENTATION' => self::PRES_SWITCH, 'CAPTION_ON' => 'On', 'CAPTION_OFF' => 'Off']);
            }
            @SetValueBoolean($this->getVarId('ICE_PLUS'), $ice);
        }

        // Re-assert actions for dynamic booleans after status update
        foreach (['POWER_SAVE','DISPLAY_LIGHT','AIR_CLEAN','ECO','ICE_PLUS'] as $actIdent) {
            $vid = $this->getVarId($actIdent);
            if ($vid > 0 && method_exists($this, 'EnableAction')) {
                $this->EnableAction($actIdent);
            }
        }
    }

    private function flatten(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += $this->flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    private function readFirstKey(array $flat, array $candidates): mixed
    {
        foreach ($candidates as $k) {
            if (array_key_exists($k, $flat)) {
                return $flat[$k];
            }
        }
        return null;
    }

    private function readFirstKeyContains(array $flat, array $candidates): mixed
    {
        foreach ($candidates as $sub) {
            foreach ($flat as $k => $v) {
                if (strpos($k, $sub) !== false) {
                    return $v;
                }
            }
        }
        return null;
    }

    private function readFirstKeyAsBool(array $flat, array $candidates): ?bool
    {
        $v = $this->readFirstKey($flat, $candidates);
        if ($v === null) return null;
        if (is_bool($v)) return $v;
        $s = strtolower((string)$v);
        if ($s === 'true' || $s === '1' || $s === 'on') return true;
        if ($s === 'false' || $s === '0' || $s === 'off') return false;
        return null;
    }

    private function readFirstKeyAsBoolContains(array $flat, array $candidates): ?bool
    {
        $v = $this->readFirstKeyContains($flat, $candidates);
        if ($v === null) return null;
        if (is_bool($v)) return $v;
        $s = strtolower((string)$v);
        if ($s === 'true' || $s === '1' || $s === 'on') return true;
        if ($s === 'false' || $s === '0' || $s === 'off') return false;
        return null;
    }

    private function readFirstKeyAsFloat(array $flat, array $candidates): ?float
    {
        $v = $this->readFirstKey($flat, $candidates);
        if ($v === null) return null;
        if (is_numeric($v)) return (float)$v;
        return null;
    }

    private function readFirstKeyAsFloatContains(array $flat, array $candidates): ?float
    {
        $v = $this->readFirstKeyContains($flat, $candidates);
        if ($v === null) return null;
        if (is_numeric($v)) return (float)$v;
        return null;
    }

    private function mapHvacMode(string $s): int
    {
        $s = strtolower($s);
        return match ($s) {
            'off' => 0,
            'auto' => 1,
            'cool' => 2,
            'heat' => 3,
            'dry', 'air_dry' => 4,
            'fan', 'fan_only' => 5,
            'air_clean', 'airclean' => 5,
            'energy_saving' => 1,
            'aroma' => 1,
            default => 0
        };
    }

    private function mapFanMode(string $s): int
    {
        $s = strtolower($s);
        return match ($s) {
            'auto' => 10,
            'low', 'slow' => 11,
            'mid', 'medium' => 12,
            'high' => 13,
            'turbo', 'super', 'power' => 14,
            default => 10
        };
    }

    private function mapHvacCodeToJobMode(int $code): string
    {
        // Gegenstück zu mapHvacMode()
        return match ($code) {
            1 => 'AUTO',
            2 => 'COOL',
            3 => 'HEAT',
            4 => 'AIR_DRY',
            5 => 'FAN',
            default => 'AUTO'
        };
    }

    private function mapFanCodeToWindStrength(int $code): string
    {
        // Gegenstück zu mapFanMode()
        return match ($code) {
            10 => 'AUTO',
            11 => 'LOW',
            12 => 'MID',
            13 => 'HIGH',
            14 => 'POWER',
            default => 'AUTO'
        };
    }

    private function getHvacCaptionByCode_DEPRECATED(int $code): string
    {
        // Kept for backward-compatibility; use English getHvacCaptionByCode() below
        return match ($code) {
            0 => 'Off',
            1 => 'Auto',
            2 => 'Cool',
            3 => 'Heat',
            4 => 'Dry',
            5 => 'Fan',
            default => 'Auto'
        };
    }


    private function getFanCaptionByCode(int $code): string
    {
        return match ($code) {
            10 => 'Auto',
            11 => 'Low',
            12 => 'Mid',
            13 => 'High',
            14 => 'Power',
            default => 'Auto'
        };
    }

    private function getHvacCaptionByCode(int $code): string
    {
        return match ($code) {
            0 => 'Off',
            1 => 'Auto',
            2 => 'Cool',
            3 => 'Heat',
            4 => 'Dry',
            5 => 'Fan',
            default => 'Auto'
        };
    }

    private function normalizeOnOffCaption(string $s, bool $isOn): string
    {
        $u = trim($s);
        // Map common German captions to English defaults
        if ($isOn) {
            if (strcasecmp($u, 'An') === 0 || strcasecmp($u, 'Ein') === 0) return 'On';
        } else {
            if (strcasecmp($u, 'Aus') === 0) return 'Off';
        }
        // Keep original if not recognized
        return $u;
    }

    private function applyNamesFromCapabilities(array $profile, string $deviceType): void
    {
        try {
            $eng = $this->getCapabilityEngine();
            $eng->loadCapabilities($deviceType, $profile);
            $caps = $eng->getDescriptors();
            // Map of ident -> default English name
            $map = [
                // Common
                'POWER' => 'Power',
                'HVAC_MODE' => 'HVAC Mode',
                'FAN_MODE' => 'Fan Mode',
                'SET_TEMP' => 'Set Temperature',
                'CUR_TEMP' => 'Current Temperature',
                'RUN_STATE' => 'Run State',
                'REMAIN_MIN' => 'Remaining Time',
                'PROGRESS' => 'Progress',
                'DOOR_OPEN' => 'Door Open',
                'CHILD_LOCK' => 'Child Lock',
                'PROGRAM' => 'Program',
                'DISPLAY_LIGHT' => 'Display Light',
                'AIR_CLEAN' => 'Air Clean',
                'POWER_SAVE' => 'Power Save',
                'HUMIDITY' => 'Humidity',
                'FILTER_REMAIN_PERCENT' => 'Filter Remaining',

                // Washer / Dryer
                'SPIN_SPEED' => 'Spin Speed',
                'WASH_WATER_TEMP' => 'Water Temperature',
                'DRY_LEVEL' => 'Dry Level',
                'DELAY_START_HOUR' => 'Delay Start (h)',
                'REMOTE_CONTROL_ENABLED' => 'Remote Control',
                'WTW_WASHER_OPERATION' => 'Washtower Washer Operation',
                'WTD_DRYER_OPERATION' => 'Washtower Dryer Operation',
                'COMBO_OPERATION' => 'Wash/Dry Operation',
                'MINI_COMBO_OPERATION' => 'Mini Wash/Dry Operation',

                // Fridge
                'FRIDGE_TEMP' => 'Fridge Temp',
                'FREEZER_TEMP' => 'Freezer Temp',
                'FRIDGE_SET_TEMP' => 'Fridge Set Temp',
                'FREEZER_SET_TEMP' => 'Freezer Set Temp',
                'ICE_PLUS' => 'Ice Plus',
                'ECO' => 'Eco',
                'DOOR_FRIDGE' => 'Door Fridge',
                'DOOR_FREEZER' => 'Door Freezer',

                // Hood
                'HOOD_LIGHT' => 'Light',

                // Microwave
                'MICROWAVE_OPERATION' => 'Microwave Operation',
                'MW_MODE' => 'Mode',
                'MW_POWER_LEVEL' => 'Power Level',
                'COOK_TIME_MIN' => 'Cook Time (min)',
                'LIGHT' => 'Light',
                'TURNTABLE' => 'Turntable',
                'BEEP' => 'Beep',

                // Cooktop
                'COOKTOP_LOCK' => 'Lock',
                'LEFT_FRONT_LEVEL' => 'Left Front Level',
                'RIGHT_FRONT_LEVEL' => 'Right Front Level',
                'LEFT_REAR_LEVEL' => 'Left Rear Level',
                'RIGHT_REAR_LEVEL' => 'Right Rear Level',
                'CENTER_LEVEL' => 'Center Level',

                // Purifier Fan
                'PURIFIER_MODE' => 'Mode',
                'AIR_QUALITY_INDEX' => 'Air Quality Index',
                'ODOR' => 'Odor',
                'IONIZER' => 'Ionizer',
                'PM1' => 'PM1',
                'PM2_5' => 'PM2.5',
                'PM10' => 'PM10',

                // Boiler / Water Heater
                'BOILER_OPERATION' => 'Operation',
                'HEAT_MODE' => 'Heat Mode',
                'TARGET_ROOM_TEMP' => 'Target Room Temp',
                'TARGET_WATER_TEMP' => 'Target Water Temp',
                'ROOM_TEMP' => 'Room Temperature',
                'WATER_TEMP' => 'Water Temperature',
                'PUMP_ON' => 'Pump',
                'BURNER_ON' => 'Burner',
                'PRESSURE_BAR' => 'Pressure',
                'ERROR_CODE' => 'Error Code',
                'TANK_LEVEL_PERCENT' => 'Tank Level',
                'ANODE_REMAIN_PERCENT' => 'Anode Remaining',
                'STERILIZE' => 'Sterilize',

                // Humidifier
                'HUMIDIFIER_OPERATION' => 'Humidifier Operation',
                'TARGET_HUMIDITY' => 'Target Humidity',
                'CURRENT_HUMIDITY' => 'Current Humidity',
                'HUM_MODE' => 'Mode',
                'OSCILLATE' => 'Oscillation',

                // Ventilator
                'VENT_OPERATION' => 'Ventilator Operation',
                'TIMER_REMAIN_MIN' => 'Timer Remaining',

                // Stick Cleaner
                'STICK_OPERATION' => 'Operation',
                'BATTERY_LEVEL' => 'Battery',
                'CHARGING' => 'Charging',
                'SUCTION_LEVEL' => 'Suction Level',
                'TURBO' => 'Turbo',
                'BRUSH' => 'Brush',
                'DUSTBIN_FULL' => 'Dustbin Full',
            ];
            foreach ($caps as $cap) {
                $ident = (string)($cap['ident'] ?? '');
                if ($ident === '') continue;
                $vid = $this->getVarId($ident);
                if ($vid <= 0) continue;
                $name = $map[$ident] ?? ((string)($cap['name'] ?? $ident));
                $tname = $this->t($name);
                if (IPS_GetName($vid) !== $tname) {
                    @IPS_SetName($vid, $tname);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    // Hilfsmethoden für Temperatursteuerung
    private function normalizeJobMode(?string $mode): string
    {
        $m = strtoupper(trim((string)$mode));
        if ($m === '') return 'AUTO';
        return match ($m) {
            'DRY', 'AIR_DRY', 'DEHUMIDIFY' => 'AIR_DRY',
            'COOL', 'COOLING' => 'COOL',
            'HEAT', 'HEATING' => 'HEAT',
            'FAN', 'FAN_ONLY' => 'FAN',
            'AUTO' => 'AUTO',
            default => 'AUTO'
        };
    }

    private function temperatureKeyForMode(string $mode): string
    {
        return match ($mode) {
            'COOL', 'AIR_DRY' => 'coolTargetTemperature',
            'HEAT' => 'heatTargetTemperature',
            'AUTO' => 'autoTargetTemperature',
            default => 'targetTemperature'
        };
    }

    private function detectTemperatureUnit(): string
    {
        try {
            $last = $this->ReadAttributeString('LastStatus');
            $arr = json_decode((string)$last, true);
            if (is_array($arr)) {
                $flat = $this->flatten($arr);
                $u = $flat['temperature.unit'] ?? ($flat['twoSetTemperature.unit'] ?? null);
                if ($u === null) {
                    foreach ([0, 1] as $i) {
                        $k = 'temperatureInUnits.' . $i . '.unit';
                        if (isset($flat[$k])) { $u = $flat[$k]; break; }
                    }
                    if ($u === null) {
                        foreach ([0, 1] as $i) {
                            $k = 'twoSetTemperatureInUnits.' . $i . '.unit';
                            if (isset($flat[$k])) { $u = $flat[$k]; break; }
                        }
                    }
                }
                if (is_string($u) && $u !== '') {
                    $up = strtoupper((string)$u);
                    if ($up === 'F' || $up === 'FAHRENHEIT') return 'F';
                    return 'C';
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return 'C';
    }

    private function twoSetKeyForMode(string $mode): string
    {
        return match ($mode) {
            'HEAT' => 'heatTargetTemperature',
            'COOL' => 'coolTargetTemperature',
            default => 'targetTemperature'
        };
    }

    private function getTwoSetTargetsFromStatus(string $unit): array
    {
        $heat = null; $cool = null;
        try {
            $last = $this->ReadAttributeString('LastStatus');
            $arr = json_decode((string)$last, true);
            if (is_array($arr)) {
                $flat = $this->flatten($arr);
                // Prefer twoSetTemperatureInUnits matching desired unit
                foreach ([0, 1, 2] as $i) {
                    $u = $flat['twoSetTemperatureInUnits.' . $i . '.unit'] ?? null;
                    if (is_string($u) && strtoupper((string)$u) === strtoupper($unit)) {
                        $h = $flat['twoSetTemperatureInUnits.' . $i . '.heatTargetTemperature'] ?? null;
                        $c = $flat['twoSetTemperatureInUnits.' . $i . '.coolTargetTemperature'] ?? null;
                        if (is_numeric($h)) $heat = (float)$h;
                        if (is_numeric($c)) $cool = (float)$c;
                        break;
                    }
                }
                if ($heat === null && $cool === null) {
                    // Fallback to base container
                    $h = $flat['twoSetTemperature.heatTargetTemperature'] ?? null;
                    $c = $flat['twoSetTemperature.coolTargetTemperature'] ?? null;
                    if (is_numeric($h)) $heat = (float)$h;
                    if (is_numeric($c)) $cool = (float)$c;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return ['heat' => $heat, 'cool' => $cool];
    }

    private function isTwoSetEnabledFromStatus(): bool
    {
        try {
            $last = $this->ReadAttributeString('LastStatus');
            $arr = json_decode((string)$last, true);
            if (is_array($arr)) {
                $flat = $this->flatten($arr);
                $v = $flat['twoSetTemperature.twoSetEnabled'] ?? null;
                if (is_bool($v)) return $v;
                if (is_string($v)) {
                    $s = strtolower((string)$v);
                    return $s === 'true' || $s === '1' || $s === 'on';
                }
                if (is_numeric($v)) return ((int)$v) !== 0;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return false;
    }

    // Empfang von Events vom Bridge-Splitter (MQTT weitergeleitet)
    public function ReceiveData($JSONString)
    {
        $outer = json_decode((string)$JSONString, true);
        if (!is_array($outer)) {
            return '';
        }
        $buf = $outer['Buffer'] ?? null;
        if (is_string($buf)) {
            $buf = json_decode((string)$buf, true);
        }
        if (!is_array($buf)) {
            return '';
        }
        $action = (string)($buf['Action'] ?? '');
        if ($action !== 'Event') {
            return '';
        }
        $devId = (string)($buf['DeviceID'] ?? '');
        $myId  = (string)$this->ReadPropertyString('DeviceID');
        if ($devId === '' || strcasecmp($devId, $myId) !== 0) {
            return '';
        }
        $event = $buf['Event'] ?? null;
        if (!is_array($event)) {
            return '';
        }
        // Deep-Merge Event in LastStatus
        $current = [];
        try {
            $last = $this->ReadAttributeString('LastStatus');
            $arr = json_decode((string)$last, true);
            if (is_array($arr)) { $current = $arr; }
        } catch (\Throwable $e) {
            // ignore
        }
        $merged = $this->deepMerge($current, $event);
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->WriteAttributeString('LastStatus', $json);
        @SetValueString($this->getVarId('STATUS'), $json);
        @SetValueInteger($this->getVarId('LASTUPDATE'), time());
        try {
            $this->updateFromStatus($merged);
        } catch (\Throwable $e) {
        }
        // CapabilityEngine: Werte anwenden
        try {
            $this->getCapabilityEngine()->applyStatus($merged);
        } catch (\Throwable $e) {
        }
        return '';
    }

    private function deepMerge(array $base, array $patch): array
    {
        foreach ($patch as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = $this->deepMerge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private function redactDeep($data)
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $ks = (string)$k;
                if ($this->shouldRedactKey($ks)) {
                    if (is_string($v)) { $out[$k] = $this->maskText($v); }
                    elseif (is_scalar($v)) { $out[$k] = '***'; }
                    else { $out[$k] = $this->redactDeep($v); }
                } else {
                    $out[$k] = $this->redactDeep($v);
                }
            }
            return $out;
        }
        if (is_string($data)) {
            // Light PII patterns (emails, tokens-like long hex)
            if (strpos($data, '@') !== false) return $this->maskText($data);
            if (preg_match('/[A-Fa-f0-9]{24,}/', $data)) return $this->maskText($data);
        }
        return $data;
    }

    private function shouldRedactKey(string $key): bool
    {
        $needles = ['token','email','user','account','serial','mac','ssid','ip','location','latitude','longitude','address'];
        $k = strtolower($key);
        foreach ($needles as $n) { if (strpos($k, $n) !== false) return true; }
        return false;
    }

    private function maskId(string $id): string
    {
        $id = trim($id);
        if ($id === '') return '';
        $len = strlen($id);
        if ($len <= 4) return str_repeat('*', max(0, $len - 1)) . substr($id, -1);
        return str_repeat('*', $len - 4) . substr($id, -4);
    }

    private function maskText(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (strlen($s) <= 4) return substr($s, 0, 1) . '***' . substr($s, -1);
        return substr($s, 0, 2) . '***' . substr($s, -2);
    }


    public function UIExportSupportBundle(): string
    {
        // Generate a support bundle on-the-fly and return a Data-URL for direct download
        try {
            $deviceID = (string)$this->ReadPropertyString('DeviceID');
            $alias    = (string)$this->ReadPropertyString('Alias');
            $type     = (string)$this->ReadAttributeString('DeviceType');

            $ts = date('Ymd_His');
            $devShort = $this->maskId($deviceID);

            // Collect metadata
            $meta = [
                'instanceId'    => $this->InstanceID,
                'module'        => 'LG ThinQ Device',
                'moduleGUID'    => '{B5CF9E2D-7B7C-4A0A-9C0E-7E5A0B8E2E9A}',
                'timestamp'     => date('c'),
                'phpVersion'    => PHP_VERSION,
                'deviceIdMasked'=> $devShort,
                'deviceIdHash'  => $deviceID !== '' ? sha1($deviceID) : '',
                'aliasMasked'   => $alias !== '' ? $this->maskText($alias) : '',
                'deviceType'    => $type,
            ];

            // GetDevices and filter current device
            $deviceInfo = null;
            try {
                $listJson = $this->sendAction('GetDevices');
                $devs = @json_decode((string)$listJson, true);
                if (is_array($devs)) {
                    foreach ($devs as $d) {
                        if (is_array($d) && ($d['deviceId'] ?? '') === $deviceID) { $deviceInfo = $d; break; }
                    }
                }
            } catch (\Throwable $e) {
                $deviceInfo = ['error' => $e->getMessage()];
            }
            $deviceInfoSan = is_array($deviceInfo) ? $this->redactDeep($deviceInfo) : null;

            // Profile (raw response + extracted profile/property)
            $profileResponse = null; $extractedProfile = [];
            try {
                $profileRaw = ($deviceID !== '') ? $this->sendAction('GetProfile', ['DeviceID' => $deviceID]) : '{}';
            } catch (\Throwable $e) {
                $profileRaw = '{}';
            }
            $profileResponse = @json_decode((string)$profileRaw, true);
            if (is_array($profileResponse)) {
                if (isset($profileResponse['property']) && is_array($profileResponse['property'])) {
                    $extractedProfile = $profileResponse['property'];
                } elseif (isset($profileResponse['profile']) && is_array($profileResponse['profile'])) {
                    $extractedProfile = $profileResponse['profile'];
                } else {
                    $extractedProfile = $profileResponse; // may already be the profile
                }
            }
            $profileSan = $this->redactDeep($extractedProfile);

            // Status (live or last attribute)
            $statusArr = null;
            try {
                if ($deviceID !== '') {
                    $statusRaw = $this->sendAction('GetStatus', ['DeviceID' => $deviceID]);
                    $tmp = @json_decode((string)$statusRaw, true);
                    if (is_array($tmp)) { $statusArr = $tmp; }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            if (!is_array($statusArr)) {
                $last = (string)$this->ReadAttributeString('LastStatus');
                $tmp = @json_decode($last, true);
                $statusArr = is_array($tmp) ? $tmp : [];
            }
            $statusSan = $this->redactDeep($statusArr);

            // Capability descriptors summary
            $capsSummary = [];
            try {
                $profRaw = (string)$this->ReadAttributeString('LastProfile');
                $prof = @json_decode($profRaw, true);
                if (!is_array($prof)) { $prof = []; }
                $eng = $this->getCapabilityEngine();
                $eng->loadCapabilities((string)$type, $prof);
                $descs = $eng->getDescriptors();
                foreach ($descs as $cap) {
                    if (!is_array($cap)) continue;
                    $capsSummary[] = [
                        'ident' => (string)($cap['ident'] ?? ''),
                        'type'  => (string)($cap['type'] ?? ''),
                        'presentation' => $cap['presentation']['kind'] ?? null,
                        'create' => $cap['create']['when'] ?? null,
                        'actionEnableWhen' => $cap['action']['enableWhen'] ?? null
                    ];
                }
            } catch (\Throwable $e) {
                $capsSummary = [['error' => $e->getMessage()]];
            }

            // Variables snapshot
            $vars = [];
            $children = @IPS_GetChildrenIDs($this->InstanceID);
            if (is_array($children)) {
                foreach ($children as $cid) {
                    $obj = @IPS_GetObject($cid);
                    if (!is_array($obj) || (int)($obj['ObjectType'] ?? -1) !== 2) continue; // only variables
                    $var = @IPS_GetVariable($cid);
                    if (!is_array($var)) continue;
                    $vt = (int)($var['VariableType'] ?? -1);
                    $val = null;
                    if ($vt === VARIABLETYPE_BOOLEAN) { $val = @GetValueBoolean($cid); }
                    elseif ($vt === VARIABLETYPE_INTEGER) { $val = @GetValueInteger($cid); }
                    elseif ($vt === VARIABLETYPE_FLOAT) { $val = @GetValueFloat($cid); }
                    elseif ($vt === VARIABLETYPE_STRING) { $val = @GetValueString($cid); }
                    $vars[] = [
                        'vid' => (int)$cid,
                        'ident' => (string)($obj['ObjectIdent'] ?? ''),
                        'name' => (string)($obj['ObjectName'] ?? ''),
                        'type' => $vt,
                        'profile' => (string)($var['VariableCustomProfile'] ?? ''),
                        'presentation' => isset($var['VariableCustomPresentation']) ? $var['VariableCustomPresentation'] : null,
                        'customAction' => $var['VariableCustomAction'] ?? null,
                        'value' => $val
                    ];
                }
            }

            // Try to create ZIP on-the-fly (preferred)
            if (class_exists('ZipArchive')) {
                $tmpZip = @tempnam(sys_get_temp_dir(), 'lgtqd_zip_');
                if (is_string($tmpZip) && $tmpZip !== '') {
                    // Ensure .zip extension (some environments require it)
                    $zipPath = $tmpZip . '.zip';
                    @rename($tmpZip, $zipPath);
                    $zip = new \ZipArchive();
                    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                        @$zip->addFromString('00_meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('10_device_info.json', json_encode($deviceInfoSan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('20_profile_response.json', json_encode($this->redactDeep($profileResponse), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('21_profile_extracted.json', json_encode($profileSan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('22_profile_keys.txt', implode("\n", array_keys($this->flatten($extractedProfile))));
                        @$zip->addFromString('30_status.json', json_encode($statusSan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('40_variables.json', json_encode($vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        @$zip->addFromString('50_capabilities_summary.json', json_encode($capsSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $zip->close();

                        $bin = @file_get_contents($zipPath);
                        @unlink($zipPath);
                        if (is_string($bin) && $bin !== '') {
                            return 'data:application/zip;base64,' . base64_encode($bin);
                        }
                    } else {
                        // Cleanup renamed tmp if open failed
                        @unlink($zipPath);
                    }
                }
            }

            // Fallback: return a single JSON with aggregated data
            $bundle = [
                'meta' => $meta,
                'device' => $deviceInfoSan,
                'profile_raw' => $this->redactDeep($profileResponse),
                'profile_extracted' => $profileSan,
                'profile_keys' => array_keys($this->flatten($extractedProfile)),
                'status' => $statusSan,
                'variables' => $vars,
                'capabilities_summary' => $capsSummary
            ];
            return 'data:application/json;base64,' . base64_encode(json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // Last resort: return error as JSON
            $err = [ 'error' => $e->getMessage() ];
            return 'data:application/json;base64,' . base64_encode(json_encode($err, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    public function GetConfigurationForm()
    {
        // Load base form.json and append a dynamic label with the last bundle URL
        $formPath = __DIR__ . '/form.json';
        $raw = @file_get_contents($formPath);
        $form = is_string($raw) ? @json_decode($raw, true) : null;
        if (!is_array($form)) {
            $form = ['elements' => [], 'actions' => [], 'status' => []];
        }
        if (!isset($form['actions']) || !is_array($form['actions'])) {
            $form['actions'] = [];
        }


        // Provide a direct download button (Data-URL) with a suggested filename
        $dlExt = class_exists('ZipArchive') ? 'zip' : 'json';
        $dlName = sprintf('lgtqd_support_%d.%s', $this->InstanceID, $dlExt);
        $form['actions'][] = [
            'type' => 'Button',
            'label' => $this->t('Support-Paket herunterladen (ZIP)'),
            'download' => $dlName,
            'onClick' => 'echo LGTQD_UIExportSupportBundle($id);'
        ];

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
