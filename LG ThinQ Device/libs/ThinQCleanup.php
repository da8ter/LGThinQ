<?php

declare(strict_types=1);

/** Variables of the device that are not in its current plan: listing them and deleting them. */
final class ThinQCleanup
{
    /** @param Closure $engine fn(): CapabilityEngine */
    public function __construct(private ThinQModuleContext $ctx, private ThinQDeviceProfileManager $profiles, private Closure $engine)
    {
    }

    public function preview(): string
    {
        $candidates = $this->candidates();
        if ($candidates === null) {
            return $this->ctx->t('No device type or profile yet – aborting.');
        }
        if ($candidates === []) {
            return $this->ctx->t('Keine Variablen zum Löschen gefunden.');
        }
        $lines = array_map(static fn(array $c): string => sprintf('%s (ID %d, Name "%s")', $c['ident'], $c['id'], $c['name']), $candidates);
        return $this->ctx->t('Folgende Variablen würden gelöscht werden') . ":\n" . implode("\n", $lines) . "\n\n"
            . sprintf($this->ctx->t('Summe: %d'), count($candidates));
    }

    /** Deletes (or, with $delete false, only counts) the variables outside the plan. */
    public function run(bool $delete): string
    {
        $candidates = $this->candidates();
        if ($candidates === null) {
            return 'No device type or profile yet – aborting.';
        }
        $kept = count($this->variables()) - count($candidates);
        $deleted = 0;
        foreach ($candidates as $c) {
            if ($delete) {
                @IPS_DeleteVariable($c['id']);
                $deleted++;
            }
        }
        if ($candidates !== []) {
            $this->ctx->debug('CleanupVariables', 'Unknown: ' . implode(', ', array_map(static fn(array $c): string => $c['ident'] . ' [' . $c['name'] . ']', $candidates)));
        }
        return sprintf('CleanupVariables: kept=%d, hidden=%d, deleted=%d', $kept, $delete ? 0 : count($candidates), $deleted);
    }

    /** @return array<int, array{id: int, ident: string, name: string}>|null variables outside the plan; null without device type */
    private function candidates(): ?array
    {
        // Without a usable profile the plan would be nearly empty and every variable a candidate
        $type = trim($this->ctx->attributeString('DeviceType'));
        $profile = $this->profiles->readStoredProfile();
        if ($type === '' || $profile === []) {
            return null;
        }
        $plan = ($this->engine)()->buildPlan($type, $profile, $this->profiles->readLastStatus());
        $valid = array_fill_keys(array_merge(array_map('strval', array_keys($plan)), ThinQDeviceSetup::GENERIC), true);
        return array_values(array_filter($this->variables(), static fn(array $v): bool => $v['ident'] !== '' && !isset($valid[$v['ident']])));
    }

    /** @return array<int, array{id: int, ident: string, name: string}> child variables */
    private function variables(): array
    {
        $out = [];
        foreach ((array)@IPS_GetChildrenIDs($this->ctx->instanceId) as $cid) {
            $object = @IPS_GetObject((int)$cid);
            if (!is_array(@IPS_GetVariable((int)$cid)) || !is_array($object)) {
                continue;
            }
            $out[] = ['id' => (int)$cid, 'ident' => (string)($object['ObjectIdent'] ?? ''), 'name' => (string)($object['ObjectName'] ?? '')];
        }
        return $out;
    }
}
