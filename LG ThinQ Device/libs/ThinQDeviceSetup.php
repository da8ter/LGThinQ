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
    /** Longest wait between two setup attempts (seconds); they start at 5 s and double. */
    public const RETRY_MAX = 300;

    /**
     * @param Closure $engine fn(): CapabilityEngine
     * @param Closure $energy fn(): ThinQEnergyManager
     * @param Closure $applyPresentation fn(int $vid, string $ident, array $presentation, array $flatProfile, string $type): void
     * @param Closure $updateEnergy fn(): void
     * @param Closure $updateStatus fn(): void — fetches the status, keeps the old one on failure
     * @param Closure $subscribe fn(): void — event and push subscription via the Bridge, throws on failure
     */
    public function __construct(
        private ThinQModuleContext $ctx,
        private ThinQDeviceProfileManager $profiles,
        private Closure $engine,
        private Closure $energy,
        private Closure $applyPresentation,
        private Closure $updateEnergy,
        private Closure $updateStatus,
        private Closure $subscribe
    ) {
    }

    /**
     * The whole setup: status, profile, device type, variables, energy, subscription. A failed
     * profile or type fetch keeps what is stored instead of overwriting it; without both the setup
     * is repeated (5 s, 10 s, 20 s … up to RETRY_MAX) until it completes. True when complete.
     */
    public function run(): bool
    {
        $this->ctx->setTimerInterval('InitialUpdateStatus', 0);
        $deviceId = trim($this->ctx->propertyString('DeviceID'));
        if ($deviceId === '') {
            return false;
        }
        if (!$this->ctx->hasActiveParent()) {
            return $this->retryLater('Bridge not active');
        }
        ($this->updateStatus)();

        $fresh = $this->profiles->fetchDeviceProfile($deviceId);
        if ($fresh !== []) {
            $freshJson = (string)json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($freshJson !== $this->ctx->attributeString('LastProfile')) {
                (new ThinQControlGuard($this->ctx))->clear(); // a changed profile may provide what LG refused
            }
            $this->ctx->writeAttributeString('LastProfile', $freshJson);
        }
        $profile = $fresh !== [] ? $fresh : $this->profiles->readStoredProfile();
        $type = $this->profiles->resolveDeviceType($deviceId, $profile);
        if ($type !== '') {
            $this->ctx->writeAttributeString('DeviceType', $type);
        } else {
            $type = trim($this->ctx->attributeString('DeviceType'));
        }
        $this->ctx->debug('Setup', sprintf('profile=%s, type=%s', $fresh !== [] ? 'fresh' : ($profile !== [] ? 'stored' : 'none'), $type !== '' ? $type : '(none)'));
        if ($profile === [] || $type === '') {
            return $this->retryLater('no profile or device type from LG yet');
        }

        $this->ensureVariables($profile, $this->profiles->readLastStatus(), $type);
        $this->setupEnergy($deviceId);
        try {
            ($this->subscribe)();
        } catch (\Throwable $e) {
            // The Bridge's renewal subscribes devices without a subscription; no need for a full setup again
            $this->ctx->debug('Setup', 'Subscription failed: ' . $e->getMessage());
        }
        $this->ctx->setBuffer('SetupAttempt', '0');
        $this->ctx->setBuffer('SetupState', 'done');
        return true;
    }

    /** Setup incomplete: next attempt after 5 s, doubled per attempt, at most RETRY_MAX. */
    private function retryLater(string $reason): bool
    {
        $attempt = (int)$this->ctx->buffer('SetupAttempt') + 1;
        $this->ctx->setBuffer('SetupAttempt', (string)$attempt);
        $this->ctx->setBuffer('SetupState', 'pending');
        $seconds = min(self::RETRY_MAX, 5 * 2 ** min($attempt - 1, 10));
        $this->ctx->debug('Setup', sprintf('Incomplete (%s), next attempt in %d s', $reason, $seconds));
        $this->ctx->setTimerInterval('InitialUpdateStatus', $seconds * 1000);
        return false;
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
            self::migrateLegacyIdents($this->ctx->instanceId, $plan);
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

    /**
     * Earlier versions parsed only the first zone of a multi-zone device, without zone prefix
     * (POWER_POWER_LEVEL); such a variable gets the new ident and keeps its ID and history.
     *
     * @param array<string, array<string, mixed>> $plan
     */
    public static function migrateLegacyIdents(int $instanceId, array $plan): void
    {
        foreach ($plan as $ident => $entry) {
            $legacy = (string)($entry['legacyIdent'] ?? '');
            if ($legacy === '' || (int)@IPS_GetObjectIDByIdent((string)$ident, $instanceId) > 0) {
                continue;
            }
            $vid = (int)@IPS_GetObjectIDByIdent($legacy, $instanceId);
            if ($vid > 0) {
                @IPS_SetIdent($vid, (string)$ident);
            }
        }
    }

    /**
     * True while the instance has none of its device's own variables. ERROR_LAST and PUSH_LAST do
     * not count: earlier versions created them even from an empty profile during an outage.
     */
    public function hasOnlyGenericVariables(): bool
    {
        $notOwn = array_merge(self::GENERIC, ['ERROR_LAST', 'PUSH_LAST']);
        foreach ((array)@IPS_GetChildrenIDs($this->ctx->instanceId) as $cid) {
            if (!is_array(@IPS_GetVariable((int)$cid))) {
                continue;
            }
            $object = @IPS_GetObject((int)$cid);
            $ident = is_array($object) ? (string)($object['ObjectIdent'] ?? '') : '';
            if ($ident !== '' && !in_array($ident, $notOwn, true)) {
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
        ($this->energy)()->reapplyPresentations();
    }

    /**
     * Sets the names of the existing variables to the current names of the plan, the ENERGY_*
     * and the generic variables (current language). Setup never renames: a variable keeps the
     * name it got when created, so renamed or translated names reach older instances only here.
     * Returns the number of renamed variables.
     */
    public function reapplyNames(): int
    {
        $names = [
            'INFO'       => $this->ctx->t('Info'),
            'STATUS'     => $this->ctx->t('Status'),
            'LASTUPDATE' => $this->ctx->t('Last Update'),
        ];
        $type = trim($this->ctx->attributeString('DeviceType'));
        if ($type !== '') {
            $profile = $this->profiles->readStoredProfile();
            foreach (($this->engine)()->buildPlan($type, $profile, $this->profiles->readLastStatus()) as $ident => $entry) {
                $name = trim((string)($entry['name'] ?? ''));
                if ($name !== '') {
                    $names[(string)$ident] = $name;
                }
            }
        }
        $names += ($this->energy)()->names();
        $renamed = 0;
        foreach ($names as $ident => $name) {
            $vid = $this->varId($ident);
            if ($vid > 0 && IPS_GetName($vid) !== $name) {
                IPS_SetName($vid, $name);
                $renamed++;
            }
        }
        $this->ctx->debug('ReapplyNames', sprintf('%d renamed of %d', $renamed, count($names)));
        return $renamed;
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
        $custom = is_array($var) ? ($var['VariablePresentation'] ?? null) : null; // module presentation (MaintainVariable)
        if (empty($custom)) {
            return true;
        }
        $current = is_string($custom) ? json_decode($custom, true) : $custom;
        if (!is_array($current)) {
            return is_string($custom) && !empty($presentation['options']) && strpos($custom, 'OPTIONS') === false;
        }
        if (!empty($presentation['options'])) {
            $have = array_column(json_decode((string)($current['OPTIONS'] ?? '[]'), true) ?: [], 'Caption', 'Value');
            foreach ($presentation['options'] as $option) {
                $value = (string)($option['value'] ?? '');
                if (!array_key_exists($value, $have) || (string)$have[$value] !== $this->ctx->t((string)($option['caption'] ?? ''))) {
                    return true; // the plan brings an option the variable lacks, or a caption changed (translation)
                }
            }
        }
        return ($presentation['kind'] ?? '') === 'slider' && is_numeric($current['STEP_SIZE'] ?? null) && (float)$current['STEP_SIZE'] < 1
            && ThinQValue::typeOf($vid) === VARIABLETYPE_INTEGER;
    }

    private function enableAction(string $ident): void
    {
        if ((new ThinQControlGuard($this->ctx))->isBlocked($ident)) {
            return; // LG refused this control for the device (2201); stays read-only until the profile changes
        }
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
