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
        private IPSModule $module,
        private int $instanceId,
        callable $sendActionCallback,
        callable $getVarIdCallback,
        callable $applyPresentationCallback
    ) {
        $this->sendActionCallback = $sendActionCallback;
        $this->getVarIdCallback = $getVarIdCallback;
        $this->applyPresentationCallback = $applyPresentationCallback;
    }

    public function fetchEnergyProfile(string $deviceId): array
    {
        try {
            $raw = ($this->sendActionCallback)('GetEnergyProfile', ['DeviceID' => $deviceId]);
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                return [];
            }
            return $data;
        } catch (\Throwable $e) {
            $this->module->SendDebug('FetchEnergyProfile', 'Failed: ' . $e->getMessage(), 0);
            return [];
        }
    }

    public function getEnergyProperties(): array
    {
        $raw = $this->module->ReadAttributeString('EnergyProfile');
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
        $dataKey = $result['dataKey'] ?? null;
        if (is_array($dataKey)) {
            return $dataKey;
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
        $this->module->SendDebug('Energy', sprintf('Energy properties: %s', $hasEnergy ? implode(', ', $energyProps) : 'none'), 0);

        $this->module->MaintainVariable('ENERGY_YESTERDAY', $this->module->Translate('Energy Yesterday'), VARIABLETYPE_FLOAT, '', 900, $hasEnergy);
        $this->module->MaintainVariable('ENERGY_THIS_MONTH', $this->module->Translate('Energy This Month'), VARIABLETYPE_FLOAT, '', 901, $hasEnergy);
        $this->module->MaintainVariable('ENERGY_LAST_MONTH', $this->module->Translate('Energy Last Month'), VARIABLETYPE_FLOAT, '', 902, $hasEnergy);

        if ($hasEnergy) {
            foreach (['ENERGY_YESTERDAY', 'ENERGY_THIS_MONTH', 'ENERGY_LAST_MONTH'] as $ident) {
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
    }

    public function scheduleEnergyTimer(): void
    {
        $energyProps = $this->getEnergyProperties();
        if (empty($energyProps)) {
            if (method_exists($this->module, 'SetTimerInterval')) {
                @$this->module->SetTimerInterval('UpdateEnergy', 0);
            }
            return;
        }
        if (method_exists($this->module, 'RegisterTimer')) {
            @$this->module->RegisterTimer('UpdateEnergy', 6 * 3600 * 1000, 'LGTQD_UpdateEnergy($_IPS["TARGET"]);');
        }
    }

    public function execute(): void
    {
        $deviceId = trim((string)$this->module->ReadPropertyString('DeviceID'));
        if ($deviceId === '') {
            return;
        }

        $energyProps = $this->getEnergyProperties();
        if (empty($energyProps)) {
            return;
        }

        $this->module->SendDebug('Energy', 'Fetching energy usage data...', 0);

        $today = new \DateTime('now');
        $yesterday = (clone $today)->modify('-1 day');
        $firstOfMonth = (clone $today)->modify('first day of this month');
        $lastMonth = (clone $firstOfMonth)->modify('-1 day');

        $property = $energyProps[0];

        $yesterdayWh = $this->fetchEnergyUsage($deviceId, $property, 'daily',
            $yesterday->format('Ymd'), $yesterday->format('Ymd'));
        if ($yesterdayWh !== null) {
            $vid = ($this->getVarIdCallback)('ENERGY_YESTERDAY');
            if ($vid > 0) { @SetValueFloat($vid, $yesterdayWh); }
        }

        $thisMonthWh = $this->fetchEnergyUsage($deviceId, $property, 'monthly',
            $today->format('Ym'), $today->format('Ym'));
        if ($thisMonthWh !== null) {
            $vid = ($this->getVarIdCallback)('ENERGY_THIS_MONTH');
            if ($vid > 0) { @SetValueFloat($vid, $thisMonthWh); }
        }

        $lastMonthWh = $this->fetchEnergyUsage($deviceId, $property, 'monthly',
            $lastMonth->format('Ym'), $lastMonth->format('Ym'));
        if ($lastMonthWh !== null) {
            $vid = ($this->getVarIdCallback)('ENERGY_LAST_MONTH');
            if ($vid > 0) { @SetValueFloat($vid, $lastMonthWh); }
        }

        $this->module->SendDebug('Energy', sprintf('Updated: yesterday=%.0f Wh, thisMonth=%.0f Wh, lastMonth=%.0f Wh',
            $yesterdayWh ?? -1, $thisMonthWh ?? -1, $lastMonthWh ?? -1), 0);
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
            $total = 0.0;
            foreach ($dataList as $entry) {
                if (is_array($entry) && isset($entry[$property])) {
                    $total += (float)$entry[$property];
                }
            }
            return $total;
        } catch (\Throwable $e) {
            $this->module->SendDebug('FetchEnergyUsage', sprintf('Failed (%s %s-%s): %s', $period, $startDate, $endDate, $e->getMessage()), 0);
            return null;
        }
    }
}
