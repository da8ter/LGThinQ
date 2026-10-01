<?php

declare(strict_types=1);

/**
 * ThinQEnergyManager
 *
 * Extracted from LG ThinQ Device/module.php.
 * Manages energy profile fetching, variable setup, timer scheduling, and usage updates.
 */
class ThinQEnergyManager
{
    /** @var callable */
    private $sendActionCallback;
    /** @var callable */
    private $getVarIdCallback;
    /** @var callable */
    private $applyPresentationCallback;

    public function __construct(
        private ThinQModuleContext $ctx,
        callable $sendActionCallback,
        callable $getVarIdCallback,
        callable $applyPresentationCallback
    ) {
        $this->sendActionCallback = $sendActionCallback;
        $this->getVarIdCallback = $getVarIdCallback;
        $this->applyPresentationCallback = $applyPresentationCallback;
    }

    /**
     * The energy profile; [] when LG answers that the device has no energy data (1221), null when
     * the call failed otherwise; then the caller keeps what it knows, so an outage deletes nothing.
     */
    public function fetchEnergyProfile(string $deviceId): ?array
    {
        try {
            $raw = ($this->sendActionCallback)('GetEnergyProfile', ['DeviceID' => $deviceId]);
            $data = json_decode((string)$raw, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            $this->ctx->debug('FetchEnergyProfile', 'Failed: ' . $e->getMessage());
            return preg_match('/API error 1221\b/', $e->getMessage()) === 1 ? [] : null;
        }
    }

    public function getEnergyProperties(): array
    {
        $raw = $this->ctx->attributeString('EnergyProfile');
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $result = $data['result'] ?? $data;
        if (!is_array($result)) {
            return [];
        }
        // LG answers {"resultCode":"0000","result":{"property":["energyUsage"]}} (measured)
        foreach (['property', 'dataKey'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                return array_values(array_filter($result[$key], 'is_string'));
            }
        }
        if (isset($result[0]) && is_string($result[0])) {
            return $result;
        }
        return [];
    }

    public function setupEnergyVariables(): void
    {
        $energyProps = $this->getEnergyProperties();
        $hasEnergy = !empty($energyProps);
        $this->ctx->debug('Energy', sprintf('Energy properties: %s', $hasEnergy ? implode(', ', $energyProps) : 'none'));

        $this->ctx->maintainVariable('ENERGY_YESTERDAY', $this->ctx->t('Energy Yesterday'), VARIABLETYPE_FLOAT, '', 900, $hasEnergy);
        $this->ctx->maintainVariable('ENERGY_THIS_MONTH', $this->ctx->t('Energy This Month'), VARIABLETYPE_FLOAT, '', 901, $hasEnergy);
        $this->ctx->maintainVariable('ENERGY_LAST_MONTH', $this->ctx->t('Energy Last Month'), VARIABLETYPE_FLOAT, '', 902, $hasEnergy);

        if ($hasEnergy) {
            $this->reapplyPresentations();
        }
    }

    /** Names of the ENERGY_* variables in the current language, by ident. */
    public function names(): array
    {
        return [
            'ENERGY_YESTERDAY'  => $this->ctx->t('Energy Yesterday'),
            'ENERGY_THIS_MONTH' => $this->ctx->t('Energy This Month'),
            'ENERGY_LAST_MONTH' => $this->ctx->t('Energy Last Month'),
        ];
    }

    /** The Wh presentation on the existing ENERGY_* variables (setup and "Reapply Presentations"). */
    public function reapplyPresentations(): void
    {
        foreach (array_keys($this->names()) as $ident) {
            $vid = ($this->getVarIdCallback)($ident);
            if ($vid > 0) {
                ($this->applyPresentationCallback)($vid, $ident, [
                    'kind' => 'value',
                    'suffix' => ' Wh',
                    'digits' => 0
                ], [], 'FLOAT');
            }
        }
    }

    /** The timer is registered in the module's Create(); here it is only switched on (every 6 h) or off. */
    public function scheduleEnergyTimer(): void
    {
        $this->ctx->setTimerInterval('UpdateEnergy', empty($this->getEnergyProperties()) ? 0 : 6 * 3600 * 1000);
    }

    public function execute(): void
    {
        $deviceId = trim((string)$this->ctx->propertyString('DeviceID'));
        if ($deviceId === '') {
            return;
        }

        $energyProps = $this->getEnergyProperties();
        if (empty($energyProps)) {
            return;
        }

        $this->ctx->debug('Energy', 'Fetching energy usage data...');

        $today = (new \DateTime())->setTimestamp(ThinQClock::now());
        $yesterday = (clone $today)->modify('-1 day');
        $firstOfMonth = (clone $today)->modify('first day of this month');
        $lastMonth = (clone $firstOfMonth)->modify('-1 day');

        $property = $energyProps[0];

        $yesterdayWh = $this->fetchEnergyUsage($deviceId, $property, 'DAILY',
            $yesterday->format('Ymd'), $yesterday->format('Ymd'));
        if ($yesterdayWh !== null) {
            $vid = ($this->getVarIdCallback)('ENERGY_YESTERDAY');
            if ($vid > 0) { @SetValueFloat($vid, $yesterdayWh); }
        }

        $thisMonthWh = $this->fetchEnergyUsage($deviceId, $property, 'MONTHLY',
            $today->format('Ym'), $today->format('Ym'));
        if ($thisMonthWh !== null) {
            $vid = ($this->getVarIdCallback)('ENERGY_THIS_MONTH');
            if ($vid > 0) { @SetValueFloat($vid, $thisMonthWh); }
        }

        $lastMonthWh = $this->fetchEnergyUsage($deviceId, $property, 'MONTHLY',
            $lastMonth->format('Ym'), $lastMonth->format('Ym'));
        if ($lastMonthWh !== null) {
            $vid = ($this->getVarIdCallback)('ENERGY_LAST_MONTH');
            if ($vid > 0) { @SetValueFloat($vid, $lastMonthWh); }
        }

        $this->ctx->debug('Energy', sprintf('Updated: yesterday=%.0f Wh, thisMonth=%.0f Wh, lastMonth=%.0f Wh',
            $yesterdayWh ?? -1, $thisMonthWh ?? -1, $lastMonthWh ?? -1));
    }

    private function fetchEnergyUsage(string $deviceId, string $property, string $period, string $startDate, string $endDate): ?float
    {
        try {
            $raw = ($this->sendActionCallback)('GetEnergyUsage', [
                'DeviceID'  => $deviceId,
                'Property'  => $property,
                'Period'    => $period,
                'StartDate' => $startDate,
                'EndDate'   => $endDate
            ]);
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) { return null; }
            $result = $data['result'] ?? $data;
            if (!is_array($result)) { return null; }
            $dataList = $result['dataList'] ?? [];
            if (!is_array($dataList)) { return null; }
            // Spec: result.dataList[{usedDate, useAmount}]; no numeric entry means no value, not 0
            $total = null;
            foreach ($dataList as $entry) {
                $amount = is_array($entry) ? ($entry['useAmount'] ?? ($entry[$property] ?? null)) : null;
                if (is_numeric($amount)) {
                    $total = ($total ?? 0.0) + (float)$amount;
                }
            }
            return $total;
        } catch (\Throwable $e) {
            $this->ctx->debug('FetchEnergyUsage', sprintf('Failed (%s %s-%s): %s', $period, $startDate, $endDate, $e->getMessage()));
            return null;
        }
    }
}
