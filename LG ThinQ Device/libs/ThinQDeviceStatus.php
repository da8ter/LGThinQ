<?php

declare(strict_types=1);

/**
 * The device's state in Symcon: the stored LastStatus with STATUS and LASTUPDATE, and the
 * capability variables it feeds. A full status replaces it (UpdateStatus), an event report is
 * merged in by zone and selector (ReceiveData), a push notification only sets PUSH_LAST. An event
 * also heals a device whose setup never completed and fetches the profile again once for keys it
 * does not list.
 */
final class ThinQDeviceStatus
{
    /** At most one self-heal attempt per 5 minutes */
    private const HEAL_COOLDOWN = 300;

    /**
     * @param Closure(string, array<string, mixed>): string $sendAction ForwardData to the Bridge
     * @param Closure(): CapabilityEngine $engine a fresh engine
     * @param Closure(): ThinQDeviceSetup $setup
     */
    public function __construct(
        private readonly ThinQModuleContext $ctx,
        private readonly ThinQDeviceProfileManager $profiles,
        private readonly Closure $sendAction,
        private readonly Closure $engine,
        private readonly Closure $setup
    ) {
    }

    /** Fetches the full status; an empty answer carries no state and keeps the last known one. */
    public function update(string $deviceId): void
    {
        $status = json_decode(($this->sendAction)('GetStatus', ['DeviceID' => $deviceId]), true);
        if (!is_array($status)) {
            throw new Exception($this->ctx->t('Invalid status response'));
        }
        $status = ThinQShape::status($status);
        if ($status === []) {
            $this->ctx->debug('UpdateStatus', 'Empty status response, keeping the last known status');
            return;
        }
        $this->store($status);
        $engine = $this->engine();
        if ($engine !== null) {
            try {
                $engine->applyStatus($status);
            } catch (\Throwable $e) {
                $this->ctx->debug('UpdateStatus applyStatus', $e->getMessage());
            }
        }
    }

    /** A push notification (e.g. WASHING_IS_COMPLETE) is not device state: PUSH_LAST only. */
    public function push(string $code): void
    {
        $code = trim($code);
        if ($code !== '' && $this->varId('PUSH_LAST') > 0) {
            ThinQValue::write($this->varId('PUSH_LAST'), $code);
            @SetValueInteger($this->varId('LASTUPDATE'), ThinQClock::now());
        }
    }

    /** An event report: merged into the stored status, then applied to the variables. */
    public function event(array $event): void
    {
        // Zones by location, element lists by selector (a report may carry just one compartment)
        $event = ThinQShape::status($event);
        $merged = ThinQShape::merge($this->profiles->readLastStatus(), $event);
        $this->store($merged);

        $profile = $this->profiles->readStoredProfile();
        $type = trim($this->ctx->attributeString('DeviceType'));
        // A device with only INFO/STATUS/LASTUPDATE never got its capability variables, or its
        // profile or type is missing (the parent was not ready at create time): heal it
        if (($this->setup)()->hasOnlyGenericVariables() || $profile === [] || $type === '') {
            [$profile, $type] = $this->heal($profile, $type, $merged);
        }

        // Keys of this report the profile does not list: one fresh profile; keys LG reports but never
        // lists (AC airQualitySensor) are remembered, so they do not cost a profile call per push.
        $needsSetup = false;
        $unknown = $profile !== [] ? $this->unknownReportKeys($event, $profile) : [];
        if ($unknown !== []) {
            $this->ctx->debug('ReceiveData', 'Report has keys the profile does not list (' . implode(', ', $unknown) . '), refreshing it');
            $freshProfile = $this->profiles->fetchProfileFromAPI();
            if ($freshProfile !== []) {
                $profile = $freshProfile;
                $this->ctx->writeAttributeString('LastProfile', (string)json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $needsSetup = true;
                $this->rememberUnprofiledKeys(array_keys(array_diff_key(array_flip($unknown), ThinQShape::profileKeys($profile))));
            }
        }

        if ($type !== '' && $profile !== []) {
            $engine = ($this->engine)();
            $engine->buildPlan($type, $profile, $merged);
            if ($needsSetup || $engine->missingStatusVariables() !== []) {
                // New properties, or variables that only appear with a status value (timer SET)
                ($this->setup)()->ensureVariables($profile, $merged, $type);
            } else {
                $engine->applyStatus($merged);
            }
        }
    }

    /** The engine planned from the stored profile, type and status; null while the type is unknown. */
    public function engine(): ?CapabilityEngine
    {
        $type = trim($this->ctx->attributeString('DeviceType'));
        if ($type === '') {
            return null;
        }
        $engine = ($this->engine)();
        $engine->buildPlan($type, $this->profiles->readStoredProfile(), $this->profiles->readLastStatus());
        return $engine;
    }

    /**
     * Rebuilds the variables from the stored profile, or runs the full setup when profile or type
     * are missing (it fetches them again). Throttled through a buffer, not an attribute: an
     * attribute that did not exist yet (module updated without reload) made every push heal.
     *
     * @return array{0: array<string, mixed>, 1: string} profile and type after the attempt
     */
    private function heal(array $profile, string $type, array $status): array
    {
        $elapsed = ThinQClock::now() - (int)$this->ctx->buffer('SelfHealTs') >= self::HEAL_COOLDOWN;
        $this->ctx->debug('ReceiveData', sprintf('self-heal check: type=%s profileEmpty=%s cooldown=%s',
            $type !== '' ? $type : '(empty)', $profile === [] ? 'yes' : 'no', $elapsed ? 'elapsed' : 'active'));
        if (!$elapsed || !$this->ctx->hasActiveParent() || trim($this->ctx->propertyString('DeviceID')) === '') {
            return [$profile, $type];
        }
        $this->ctx->setBuffer('SelfHealTs', (string)ThinQClock::now());
        try {
            if ($profile !== [] && $type !== '') {
                $this->ctx->debug('ReceiveData', 'Self-heal: recreating variables from cached profile');
                ($this->setup)()->ensureVariables($profile, $status, $type);
            } else {
                $this->ctx->debug('ReceiveData', 'Self-heal: profile/type missing -> full setup (re-fetch from API)');
                ($this->setup)()->run();
            }
        } catch (\Throwable $e) {
            $this->ctx->debug('SelfHeal', $e->getMessage());
        }
        return [$this->profiles->readStoredProfile(), trim($this->ctx->attributeString('DeviceType'))];
    }

    private function store(array $status): void
    {
        $encoded = (string)json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->ctx->writeAttributeString('LastStatus', $encoded);
        @SetValueString($this->varId('STATUS'), $encoded);
        @SetValueInteger($this->varId('LASTUPDATE'), ThinQClock::now());
    }

    /** @return array<int, string> "resource.property" keys of a report that neither the profile nor the remembered list knows */
    private function unknownReportKeys(array $report, array $profile): array
    {
        $known = ThinQShape::profileKeys($profile) + array_fill_keys($this->unprofiledKeys(), true);
        return array_keys(array_diff_key(ThinQShape::statusKeys($report), $known));
    }

    /** @return array<int, string> keys LG reports without listing them in the profile (kept until the next kernel start) */
    private function unprofiledKeys(): array
    {
        $keys = json_decode($this->ctx->buffer('UnprofiledKeys'), true);
        return is_array($keys) ? $keys : [];
    }

    /** @param array<int, string> $keys */
    private function rememberUnprofiledKeys(array $keys): void
    {
        if ($keys !== []) {
            $this->ctx->setBuffer('UnprofiledKeys', (string)json_encode(array_values(array_unique(array_merge($this->unprofiledKeys(), $keys)))));
        }
    }

    private function varId(string $ident): int
    {
        return (int)@IPS_GetObjectIDByIdent($ident, $this->ctx->instanceId);
    }
}
