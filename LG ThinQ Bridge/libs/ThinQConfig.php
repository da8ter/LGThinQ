<?php

declare(strict_types=1);

final class ThinQBridgeConfig
{
    public string $accessToken;
    public string $countryCode;
    public string $clientId;
    public bool $debug;
    public bool $useMqtt;
    public int $mqttClientId;
    public string $mqttTopicFilter;
    public bool $ignoreRetained;
    public int $eventTtlHours;
    public int $eventRenewLeadMin;

    /**
     * @param array<int, string> $errors
     */
    private function __construct(
        string $accessToken,
        string $countryCode,
        string $clientId,
        bool $debug,
        bool $useMqtt,
        int $mqttClientId,
        string $mqttTopicFilter,
        bool $ignoreRetained,
        int $eventTtlHours,
        int $eventRenewLeadMin
    ) {
        $this->accessToken = $accessToken;
        $this->countryCode = $countryCode;
        $this->clientId = $clientId;
        $this->debug = $debug;
        $this->useMqtt = $useMqtt;
        $this->mqttClientId = $mqttClientId;
        $this->mqttTopicFilter = $mqttTopicFilter;
        $this->ignoreRetained = $ignoreRetained;
        $this->eventTtlHours = $eventTtlHours;
        $this->eventRenewLeadMin = $eventRenewLeadMin;
    }

    public static function create(
        string $accessToken,
        string $countryCode,
        string $clientId,
        bool $debug,
        bool $useMqtt,
        int $mqttClientId,
        string $mqttTopicFilter,
        bool $ignoreRetained,
        int $eventTtlHours,
        int $eventRenewLeadMin
    ): self {
        return new self(
            $accessToken,
            $countryCode,
            $clientId,
            $debug,
            $useMqtt,
            $mqttClientId,
            $mqttTopicFilter,
            $ignoreRetained,
            $eventTtlHours,
            $eventRenewLeadMin
        );
    }

    /**
     * @return array<int, string>
     */
    public function validate(): array
    {
        $errors = [];
        if ($this->accessToken === '') {
            $errors[] = 'AccessToken fehlt.';
        }
        if ($this->countryCode === '') {
            $errors[] = 'CountryCode fehlt.';
        }
        if ($this->eventTtlHours < 1 || $this->eventTtlHours > 24) {
            $errors[] = 'EventTTL muss zwischen 1 und 24 Stunden liegen.';
        }
        if ($this->eventRenewLeadMin < 1 || $this->eventRenewLeadMin >= 60) {
            $errors[] = 'Event Renew Vorlauf muss zwischen 1 und 59 Minuten liegen.';
        }
        return $errors;
    }

    public function isValid(): bool
    {
        return empty($this->validate());
    }

    public function baseUrl(): string
    {
        $region = self::resolveRegion($this->countryCode);
        return 'https://api-' . strtolower($region) . '.lgthinq.com/';
    }

    public static function resolveRegion(string $countryCode): string
    {
        $country = strtoupper($countryCode);
        $eic = ['DE','AT','CH','FR','IT','ES','GB','IE','NL','BE','DK','SE','NO','FI','PL','PT','GR','CZ','HU','RO'];
        $aic = ['US','CA','AR','BR','CL','CO','MX','PE','UY','VE','PR'];
        $kic = ['JP','KR','AU','NZ','CN','HK','TW','SG','TH','VN','MY','ID','PH'];
        if (in_array($country, $eic, true)) {
            return 'EIC';
        }
        if (in_array($country, $aic, true)) {
            return 'AIC';
        }
        if (in_array($country, $kic, true)) {
            return 'KIC';
        }
        return 'KIC';
    }

    public function normalizedEventTtlHours(): int
    {
        return max(1, min(24, $this->eventTtlHours));
    }

    public function normalizedEventRenewLeadMinutes(): int
    {
        return max(1, min(59, $this->eventRenewLeadMin));
    }
}
