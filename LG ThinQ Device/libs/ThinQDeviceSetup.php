<?php

declare(strict_types=1);

/**
 * Setting up a device: profile and device type from LG, the variables with their presentations
 * and actions, the energy variables. Also knows whether the setup ever completed (only generic
 * variables) and reapplies presentations.
 */
final class ThinQDeviceSetup
{
    /** Variables that exist without a profile; a device with nothing else never finished its setup. */
    public const GENERIC = ['INFO', 'STATUS', 'LASTUPDATE', 'ENERGY_YESTERDAY', 'ENERGY_THIS_MONTH', 'ENERGY_LAST_MONTH'];

    /**
     * @param Closure $engine fn(): CapabilityEngine
     * @param Closure $energy fn(): ThinQEnergyManager
     * @param Closure $applyPresentation fn(int $vid, string $ident, array $presentation, array $flatProfile, string $type): void
     * @param Closure $updateEnergy fn(): void
     */
    public function __construct(
        private ThinQModuleContext $ctx,
        private ThinQDeviceProfileManager $profiles,
        private Closure $engine,
        private Closure $energy,
        private Closure $applyPresentation,
        private Closure $updateEnergy
    ) {
    }

    public function setupDevice(): void
    {
        $deviceId = trim($this->ctx->propertyString('DeviceID'));
        if ($deviceId === '') {
            throw new Exception('DeviceID missing');
        }

        $profile = $this->profiles->fetchDeviceProfile($deviceId);
        $status = $this->profiles->readLastStatus();
        $type = $this->profiles->resolveDeviceType($deviceId, $profile);

        // Diagnostic: an empty profile here is the root cause of "device created but only
        // generic variables" — GetProfile failed/returned nothing at this moment.
        $this->ctx->debug('setupDevice', sprintf('profile=%s (keys=%s), type=%s', empty($profile) ? 'EMPTY' : 'ok',
            implode(',', array_keys($profile)), $type !== '' ? $type : '(empty)'));

        $this->ctx->writeAttributeString('LastProfile', (string)json_encode($profile));
        $this->ctx->writeAttributeString('DeviceType', $type);

        $this->ensureVariables($profile, $status, $type);
        $this->setupEnergy($deviceId);
    }

    /** Energy profile, ENERGY_* variables and timer; the first values right away. */
    public function setupEnergy(string $deviceId): void
    {
        try {
            $energy = ($this->energy)();
            $energyProfile = $energy->fetchEnergyProfile($deviceId);
            if ($energyProfile !== null) { // null: the call failed, keep the known state and the ENERGY_* variables
                $this->ctx->writeAttributeString('EnergyProfile', (string)json_encode($energyProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $energy->setupEnergyVariables();
            $energy->scheduleEnergyTimer();
            if ($energy->getEnergyProperties() !== [] && ThinQClock::now() - (int)$this->ctx->buffer('EnergyFetchedAt') >= 6 * 3600) {
                ($this->updateEnergy)();
            }
        } catch (\Throwable $e) {
            $this->ctx->debug('SetupEnergy', $e->getMessage());
        }
    }

    /**
     * Variables of the plan with their presentations and actions, then the status values.
     * Presentations and actions go to new variables; existing ones only get a missing presentation,
     * missing enum options or whole steps for an older INTEGER variable.
     *
     * @param array<string, mixed> $profile
     * @param array<mixed> $status
     */
    public function ensureVariables(array $profile, array $status, string $type): void
    {
        $engine = ($this->engine)();
        try {
            $plan = $engine->buildPlan($type, $profile, $status);
            $preExisting = [];
            foreach ($plan as $ident => $_) {
                $preExisting[(string)$ident] = $this->varId((string)$ident) > 0;
            }
            $engine->ensureVariables($profile, $status, $type);
            // Per-capability failures are collected so one bad variable does not abort the rest
            $failures = $engine->getCreateFailures();
            if (!empty($failures)) {
                $this->ctx->log(sprintf('%s: %s', $this->ctx->t('Variable creation failed'), implode('; ', $failures)), KL_ERROR);
            }
        } catch (\Throwable $e) {
            $this->ctx->log(sprintf('%s (type=%s): %s', $this->ctx->t('Variable setup failed'), $type, $e->getMessage()), KL_ERROR);
            $this->ctx->debug('EnsureDeviceVariables', $e->getMessage());
            return;
        }

        $flatProfile = ThinQDeviceUtil::flatten($profile);
        foreach ($plan as $ident => $entry) {
            $ident = (string)$ident;
            $vid = $this->varId($ident);
            if ($vid <= 0) {
                continue;
            }
            $justCreated = !($preExisting[$ident] ?? false);
            $presentation = $entry['presentation'] ?? null;
            if (is_array($presentation) && ($justCreated || $this->presentationOutdated($vid, $presentation))) {
                ($this->applyPresentation)($vid, $ident, $presentation, $flatProfile, (string)($entry['type'] ?? 'STRING'));
            }
            if ($justCreated && ($entry['enableAction'] ?? false)) {
                $this->enableAction($ident);
            }
        }
        // Writeable capabilities get their action even if the variable pre-existed (reassertOn: setup)
        try {
            foreach ($engine->listIdentsToEnableOnSetup() as $ident) {
                if ($this->varId((string)$ident) > 0) {
                    $this->enableAction((string)$ident);
                }
            }
        } catch (\Throwable $e) {
            $this->ctx->debug('EnableActions(Reassert)', $e->getMessage());
        }

        $engine->applyStatus($status);
    }

    /** True while the instance has only the generic variables: the setup never created the device's own. */
    public function hasOnlyGenericVariables(): bool
    {
        foreach ((array)@IPS_GetChildrenIDs($this->ctx->instanceId) as $cid) {
            if (!is_array(@IPS_GetVariable((int)$cid))) {
                continue;
            }
            $object = @IPS_GetObject((int)$cid);
            $ident = is_array($object) ? (string)($object['ObjectIdent'] ?? '') : '';
            if ($ident !== '' && !in_array($ident, self::GENERIC, true)) {
                return false;
            }
        }
        return true;
    }

    /** Current presentations of the plan applied to the existing variables again. */
    public function reapplyPresentations(): void
    {
        $profile = $this->profiles->readStoredProfile();
        $type = trim($this->ctx->attributeString('DeviceType'));
        if ($type === '') {
            return;
        }
        $plan = ($this->engine)()->buildPlan($type, $profile, $this->profiles->readLastStatus());
        $flatProfile = ThinQDeviceUtil::flatten($profile);
        foreach ($plan as $ident => $entry) {
            $vid = $this->varId((string)$ident);
            if ($vid > 0 && is_array($entry['presentation'] ?? null) && $entry['presentation'] !== []) {
                ($this->applyPresentation)($vid, (string)$ident, $entry['presentation'], $flatProfile, (string)($entry['type'] ?? 'STRING'));
            }
        }
    }

    /**
     * A pre-existing variable needs its presentation (again): it has none, the plan brings enum
     * options it lacks, or it is an older INTEGER variable whose slider still has steps below 1.
     *
     * @param array<string, mixed> $presentation
     */
    private function presentationOutdated(int $vid, array $presentation): bool
    {
        $var = @IPS_GetVariable($vid);
        $custom = is_array($var) ? ($var['VariableCustomPresentation'] ?? null) : null;
        if (empty($custom)) {
            return true;
        }
        $current = is_string($custom) ? json_decode($custom, true) : $custom;
        if (!is_array($current)) {
            return is_string($custom) && !empty($presentation['options']) && strpos($custom, 'OPTIONS') === false;
        }
        if (!empty($presentation['options']) && empty($current['OPTIONS'])) {
            return true;
        }
        return ($presentation['kind'] ?? '') === 'slider' && is_numeric($current['STEP_SIZE'] ?? null) && (float)$current['STEP_SIZE'] < 1
            && ThinQValue::typeOf($vid) === VARIABLETYPE_INTEGER;
    }

    private function enableAction(string $ident): void
    {
        try {
            $this->ctx->enableAction($ident);
        } catch (\Throwable $e) {
            // a variable without action support; nothing to do
        }
    }

    private function varId(string $ident): int
    {
        return (int)@IPS_GetObjectIDByIdent($ident, $this->ctx->instanceId);
    }
}
