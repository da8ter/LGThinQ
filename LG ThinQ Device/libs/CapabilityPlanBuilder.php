<?php

declare(strict_types=1);

/**
 * CapabilityPlanBuilder
 *
 * Extracted from CapabilityEngine: auto-discovery phase of plan building.
 * Handles profile parsing → capability descriptor population including
 * companion pattern wiring (AC two-set temp, Oven, Cooktop).
 *
 * This class populates $caps by reference. The coordinator retains
 * the plan-compilation loop that requires shouldCreate/shouldEnableAction/readValue.
 */
class CapabilityPlanBuilder
{
    /** @var callable|null */
    private $debugCallback;

    public function __construct(
        private array &$caps,
        private array $flatProfile,
        ?callable $debugCallback,
        private CapabilityProfileExtractor $extractor,
        private ThinQProfileParser $parser
    ) {
        $this->debugCallback = $debugCallback;
    }

    private function dbg(string $message): void
    {
        if ($this->debugCallback !== null) {
            ($this->debugCallback)($message);
        }
    }

    /**
     * Run the auto-discovery phase: parse profile, convert entries to capability
     * descriptors, apply companion patterns, add ERROR_LAST / PUSH_LAST stubs.
     *
     * @param array<string, mixed> $profile
     */
    public function run(array $profile): void
    {
        // Auto-discover from profile
        try {
            $autoPlan = $this->parser->parseProfile($profile);
            $this->dbg(sprintf('Auto-discovery found %d properties', count($autoPlan)));

            foreach ($autoPlan as $ident => $autoEntry) {
                // Skip UNIT variables (e.g., TEMPERATURE_UNIT, TIME_UNIT)
                if (preg_match('/_UNIT$/i', $ident)) {
                    $this->dbg(sprintf('Skipping UNIT variable: %s', $ident));
                    continue;
                }

                if (!isset($this->caps[$ident])) {
                    $this->caps[$ident] = $this->convertAutoPlanToCapability($autoEntry);
                    $this->dbg(sprintf('Auto-added: %s (%s)', $ident, $autoEntry['name']));
                }
            }
        } catch (\Throwable $e) {
            $this->dbg('Auto-discovery failed: ' . $e->getMessage());
        }

        // Post-process: detect companion patterns
        try {
            $this->applyCompanionPatterns();
        } catch (\Throwable $e) {
            $this->dbg('CompanionPatterns failed: ' . $e->getMessage());
        }

        // Always-create variables for error and push notifications
        try {
            if (!isset($this->caps['ERROR_LAST'])) {
                $errorOptions = $this->extractor->extractErrorOptions($profile);
                $pres = ['kind' => 'value'];
                if (!empty($errorOptions)) { $pres['options'] = $errorOptions; }
                $this->caps['ERROR_LAST'] = [
                    'ident' => 'ERROR_LAST',
                    'name' => 'Letzter Fehler',
                    'type' => 'string',
                    'read' => [],
                    'create' => ['when' => 'always', 'keys' => []],
                    'action' => ['enableWhen' => 'never', 'writeableKeys' => [], 'reassertOn' => []],
                    'presentation' => $pres
                ];
            }
            if (!isset($this->caps['PUSH_LAST'])) {
                $pushOptions = $this->extractor->extractPushOptions($profile);
                $pres = ['kind' => 'value'];
                if (!empty($pushOptions)) { $pres['options'] = $pushOptions; }
                $this->caps['PUSH_LAST'] = [
                    'ident' => 'PUSH_LAST',
                    'name' => 'Letzte Push-Nachricht',
                    'type' => 'string',
                    'read' => [],
                    'create' => ['when' => 'always', 'keys' => []],
                    'action' => ['enableWhen' => 'never', 'writeableKeys' => [], 'reassertOn' => []],
                    'presentation' => $pres
                ];
            }
        } catch (\Throwable $e) {
            $this->dbg('buildPlan special sections failed: ' . $e->getMessage());
        }
    }

    /**
     * Convert auto-discovered plan entry to capability descriptor
     *
     * @param array<string, mixed> $autoEntry
     * @return array<string, mixed>
     */
    private function convertAutoPlanToCapability(array $autoEntry): array
    {
        $ident = $autoEntry['ident'];

        // Special handling for Timer Control variables (*_STOP_TIMER, *_START_TIMER)
        // According to official SDK: These are READ-ONLY status fields, not writable controls
        $isTimerControl = preg_match('/_(?:STOP|START)_TIMER$/i', $ident);

        if ($isTimerControl && ($autoEntry['meta']['type'] ?? '') === 'enum') {
            return [
                'ident' => $ident,
                'name' => $autoEntry['name'],
                'type' => 'boolean',
                'location' => $autoEntry['location'] ?? null,
                'read' => [
                    'sources' => [$autoEntry['path']],
                    'valueMap' => ['SET' => true, 'UNSET' => false]
                ],
                'create' => [
                    'when' => 'statusHasAny',
                    'keys' => [$autoEntry['path']]
                ],
                'action' => [
                    'enableWhen' => 'never',
                    'writeableKeys' => [],
                    'reassertOn' => []
                ]
            ];
        }

        // Build read config
        if (isset($autoEntry['location']) && $autoEntry['location'] !== null && $autoEntry['location'] !== '') {
            $readCfg = [
                'array' => [
                    'container' => $autoEntry['resource'],
                    'path' => $autoEntry['property'],
                    'where' => ['locationName' => (string)$autoEntry['location']]
                ]
            ];
        } else {
            $readCfg = ['sources' => [$autoEntry['path']]];
        }

        $capability = [
            'ident' => $ident,
            'name' => $autoEntry['name'],
            'type' => $this->ipsTypeToCapType($autoEntry['type']),
            'location' => $autoEntry['location'] ?? null,
            'read' => $readCfg,
            'create' => ['when' => 'always', 'keys' => []],
            'action' => [
                'enableWhen' => $autoEntry['writeable'] ? 'profileWriteableAny' : 'never',
                'writeableKeys' => $autoEntry['writeable'] ? [$autoEntry['path'] . '.mode'] : [],
                'reassertOn' => $autoEntry['writeable'] ? ['setup', 'status'] : []
            ]
        ];

        if (!$autoEntry['writeable']) {
            $this->dbg(sprintf(
                'Auto-plan %s: READ-ONLY (mode=%s) → enableWhen=never',
                $autoEntry['ident'],
                json_encode($autoEntry['meta']['mode'] ?? 'unknown')
            ));
        }

        if (isset($autoEntry['presentation'])) {
            $capability['presentation'] = $autoEntry['presentation'];
        }

        if ($autoEntry['writeable']) {
            $capability['write'] = $this->inferWriteConfig($autoEntry);
            if (isset($capability['write']['attribute']) && is_array($capability['write']['attribute'])) {
                $wa = $capability['write']['attribute'];
                $wRes = (string)($wa['resource'] ?? '');
                $wProp = (string)($wa['property'] ?? '');
                if ($wRes !== '' && $wProp !== '') {
                    $capability['action']['writeableKeys'] = [$wRes . '.' . $wProp . '.mode'];
                }
            }
        }

        return $capability;
    }

    /**
     * Infer write configuration from auto-plan entry
     *
     * @param array<string, mixed> $autoEntry
     * @return array<string, mixed>
     */
    private function inferWriteConfig(array $autoEntry): array
    {
        $meta = $autoEntry['meta'];
        $type = $meta['type'] ?? '';
        $resource = (string)$autoEntry['resource'];
        $property = (string)$autoEntry['property'];
        $location = (isset($autoEntry['location']) && $autoEntry['location'] !== null && $autoEntry['location'] !== '')
            ? (string)$autoEntry['location'] : null;

        $coSend = null;
        $redirectedToTIU = false;
        if (strcasecmp($resource, 'temperature') === 0 && strcasecmp($property, 'targetTemperature') === 0) {
            $tiuRes = 'temperatureInUnits';
            $tiuProp = 'targetTemperatureC';
            foreach ($this->flatProfile as $k => $_v) {
                if (strpos((string)$k, 'property.' . $tiuRes . '.') === 0 && strpos((string)$k, '.' . $tiuProp . '.') !== false) {
                    $resource = $tiuRes;
                    $property = $tiuProp;
                    $redirectedToTIU = true;
                    break;
                }
            }
            if (!$redirectedToTIU) {
                $hasUnitField = false;
                foreach ($this->flatProfile as $k => $_v) {
                    if (strpos((string)$k, 'property.temperature.unit.') === 0) {
                        $hasUnitField = true;
                        break;
                    }
                }
                if ($hasUnitField) {
                    $coSend = ['unit' => 'C'];
                }
            }
        }
        if (preg_match('/temperatureInUnits$/i', $resource)) {
            $unit = 'C';
            if (preg_match('/F$/i', $property)) {
                $unit = 'F';
            }
            $coSend = ['unit' => $unit];
        }

        if ($type === 'enum' && isset($autoEntry['enum'])) {
            $topLevelLoc = (isset($autoEntry['topLevelLocation']) && is_string($autoEntry['topLevelLocation']) && $autoEntry['topLevelLocation'] !== '')
                ? $autoEntry['topLevelLocation'] : null;

            $enumMap = [];
            $path = $autoEntry['path'];
            foreach ($autoEntry['enum'] as $val) {
                $enumMap[(string)$val] = [$path => (string)$val];
            }

            $result = ['enumMap' => $enumMap];
            if ($topLevelLoc !== null) {
                $result['enumMapLocationWrap'] = $topLevelLoc;
            }
            return $result;
        }

        $topLevelLoc = (isset($autoEntry['topLevelLocation']) && is_string($autoEntry['topLevelLocation']) && $autoEntry['topLevelLocation'] !== '')
            ? $autoEntry['topLevelLocation'] : null;

        $buildAttr = function(array $base) use ($location, $topLevelLoc, $coSend) {
            if ($location !== null) {
                if (!isset($base['extras'])) { $base['extras'] = []; }
                $base['extras']['locationName'] = $location;
            }
            if ($topLevelLoc !== null) {
                $base['locationWrap'] = $topLevelLoc;
            }
            if ($coSend !== null) {
                $base['coSend'] = $coSend;
            }
            return ['attribute' => $base];
        };

        if ($type === 'range' || $type === 'number') {
            return $buildAttr(['resource' => $resource, 'property' => $property, 'clampFromProfile' => true]);
        }

        if ($type === 'boolean') {
            return $buildAttr(['resource' => $resource, 'property' => $property]);
        }

        return $buildAttr(['resource' => $resource, 'property' => $property]);
    }

    /**
     * Post-process auto-discovered capabilities to detect known companion patterns.
     */
    private function applyCompanionPatterns(): void
    {
        $byResProperty = [];
        $byIdent = [];
        foreach ($this->caps as $ident => $cap) {
            $w = $cap['write'] ?? null;
            if (!is_array($w)) continue;
            $attr = $w['attribute'] ?? null;
            if (is_array($attr)) {
                $res = (string)($attr['resource'] ?? '');
                $prop = (string)($attr['property'] ?? '');
                if ($res !== '' && $prop !== '') {
                    $key = $res . '.' . $prop;
                    $byResProperty[$key] = $ident;
                    $byIdent[$ident] = ['resource' => $res, 'property' => $prop, 'location' => $cap['location'] ?? null];
                }
            }
        }

        $modified = 0;

        // Pattern 1: AC Two-Set Temperature
        $twoSetPairs = [
            ['twoSetTemperatureInUnits.heatTargetTemperatureC', 'twoSetTemperatureInUnits.coolTargetTemperatureC'],
            ['twoSetTemperatureInUnits.heatTargetTemperatureF', 'twoSetTemperatureInUnits.coolTargetTemperatureF'],
        ];
        foreach ($twoSetPairs as [$heatKey, $coolKey]) {
            $heatIdent = $byResProperty[$heatKey] ?? null;
            $coolIdent = $byResProperty[$coolKey] ?? null;
            if ($heatIdent !== null && $coolIdent !== null) {
                $hInfo = $byIdent[$heatIdent];
                $cInfo = $byIdent[$coolIdent];
                $this->addCompanionFromStatus($heatIdent, $coolIdent, $cInfo['resource'], $cInfo['property']);
                $this->addCompanionFromStatus($coolIdent, $heatIdent, $hInfo['resource'], $hInfo['property']);
                $modified += 2;
                $this->dbg(sprintf('CompanionPattern: Two-Set Temperature paired %s ↔ %s', $heatIdent, $coolIdent));
            }
        }

        // Pattern 2: Oven cook/temp/timer → always send ovenOperationMode="START"
        $ovenOpIdent = $byResProperty['operation.ovenOperationMode'] ?? null;
        if ($ovenOpIdent !== null) {
            $ovenCompanionResources = ['cook', 'temperature', 'timer'];
            $ovenModified = 0;
            foreach ($this->caps as $ident => $cap) {
                if ($ident === $ovenOpIdent) continue;
                $w = $cap['write'] ?? null;
                if (!is_array($w)) continue;
                $attr = $w['attribute'] ?? null;
                if (is_array($attr)) {
                    $res = strtolower((string)($attr['resource'] ?? ''));
                    if (in_array($res, $ovenCompanionResources, true)) {
                        $this->addCompanionConst($ident, 'operation', 'ovenOperationMode', 'START');
                        $ovenModified++;
                        continue;
                    }
                }
                if (isset($w['enumMap']) && is_array($w['enumMap'])) {
                    $firstMap = reset($w['enumMap']);
                    if (is_array($firstMap)) {
                        $firstPath = (string)array_key_first($firstMap);
                        $dotPos = strpos($firstPath, '.');
                        $enumRes = $dotPos !== false ? strtolower(substr($firstPath, 0, $dotPos)) : '';
                        if (in_array($enumRes, $ovenCompanionResources, true)) {
                            $this->addEnumMapCompanionConst($ident, 'operation', 'ovenOperationMode', 'START');
                            $ovenModified++;
                        }
                    }
                }
            }
            if ($ovenModified > 0) {
                $this->dbg(sprintf('CompanionPattern: Oven ovenOperationMode=START added to %d capabilities', $ovenModified));
            }
            $modified += $ovenModified;
        }

        // Pattern 3: Cooktop power↔timer cross-resource bundling
        $cooktopGroups = [];
        foreach ($byIdent as $ident => $info) {
            $res = $info['resource'];
            if (!in_array($res, ['power', 'timer'], true)) continue;
            $resUpper = strtoupper($res);
            $pos = strpos($ident, $resUpper . '_');
            $locPrefix = ($pos !== false && $pos > 0) ? substr($ident, 0, $pos - 1) : '';
            $cooktopGroups[$locPrefix][$ident] = $info;
        }
        foreach ($cooktopGroups as $locPrefix => $group) {
            $powerIdents = [];
            $timerIdents = [];
            foreach ($group as $ident => $info) {
                if ($info['resource'] === 'power') $powerIdents[$ident] = $info;
                if ($info['resource'] === 'timer') $timerIdents[$ident] = $info;
            }
            if (empty($powerIdents) || empty($timerIdents)) continue;
            foreach ($powerIdents as $pIdent => $pInfo) {
                foreach ($timerIdents as $tIdent => $tInfo) {
                    $this->addCompanionFromStatus($pIdent, $tIdent, $tInfo['resource'], $tInfo['property']);
                }
            }
            foreach ($timerIdents as $tIdent => $tInfo) {
                foreach ($powerIdents as $pIdent => $pInfo) {
                    $this->addCompanionFromStatus($tIdent, $pIdent, $pInfo['resource'], $pInfo['property']);
                }
            }
            $this->dbg(sprintf('CompanionPattern: Cooktop %s linked %d power + %d timer', $locPrefix ?: 'MAIN', count($powerIdents), count($timerIdents)));
        }
    }

    /**
     * Add a coSendFromStatus entry to a capability's write.attribute config
     */
    private function addCompanionFromStatus(string $targetIdent, string $partnerIdent, string $partnerResource, string $partnerProperty): void
    {
        if (!isset($this->caps[$targetIdent]['write']['attribute'])) return;
        if (!isset($this->caps[$targetIdent]['write']['attribute']['coSendFromStatus'])) {
            $this->caps[$targetIdent]['write']['attribute']['coSendFromStatus'] = [];
        }
        $this->caps[$targetIdent]['write']['attribute']['coSendFromStatus'][] = [
            'ident' => $partnerIdent,
            'resource' => $partnerResource,
            'property' => $partnerProperty
        ];
    }

    /**
     * Add a coSendConst entry to a capability's write.attribute config
     */
    private function addCompanionConst(string $targetIdent, string $resource, string $property, $value): void
    {
        if (!isset($this->caps[$targetIdent]['write']['attribute'])) return;
        if (!isset($this->caps[$targetIdent]['write']['attribute']['coSendConst'])) {
            $this->caps[$targetIdent]['write']['attribute']['coSendConst'] = [];
        }
        $this->caps[$targetIdent]['write']['attribute']['coSendConst'][] = [
            'resource' => $resource,
            'property' => $property,
            'value' => $value
        ];
    }

    /**
     * Add a coSendConst entry to a capability's write.enumMap config
     */
    private function addEnumMapCompanionConst(string $targetIdent, string $resource, string $property, $value): void
    {
        if (!isset($this->caps[$targetIdent]['write']['enumMap'])) return;
        if (!isset($this->caps[$targetIdent]['write']['enumMapCoSendConst'])) {
            $this->caps[$targetIdent]['write']['enumMapCoSendConst'] = [];
        }
        $this->caps[$targetIdent]['write']['enumMapCoSendConst'][] = [
            'resource' => $resource,
            'property' => $property,
            'value' => $value
        ];
    }

    /**
     * Convert Symcon type constant to capability type string
     */
    private function ipsTypeToCapType(int $ipsType): string
    {
        return match($ipsType) {
            VARIABLETYPE_BOOLEAN => 'boolean',
            VARIABLETYPE_INTEGER => 'integer',
            VARIABLETYPE_FLOAT => 'float',
            default => 'string'
        };
    }
}
