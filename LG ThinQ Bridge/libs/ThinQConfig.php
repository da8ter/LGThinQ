<?php

declare(strict_types=1);

final class ThinQBridgeConfig
{
    /** LG publishes to app/clients/<client id>/push; {ClientID} is filled in at runtime. */
    public const DEFAULT_TOPIC = 'app/clients/{ClientID}/push';

    public string $accessToken;
    public string $countryCode;
    public string $clientId;
    public bool $debug;
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
        string $mqttTopicFilter,
        bool $ignoreRetained,
        int $eventTtlHours,
        int $eventRenewLeadMin
    ) {
        $this->accessToken = $accessToken;
        $this->countryCode = $countryCode;
        $this->clientId = $clientId;
        $this->debug = $debug;
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
            $mqttTopicFilter,
            $ignoreRetained,
            $eventTtlHours,
            $eventRenewLeadMin
        );
    }

    /** The same configuration under another client ID (certificate requests, MQTT setup). */
    public function withClientId(string $clientId): self
    {
        $copy = clone $this;
        $copy->clientId = $clientId;
        return $copy;
    }

    /**
     * @return array<int, string>
     */
    public function validate(): array
    {
        $errors = [];
        if ($this->accessToken === '') {
            $errors[] = 'AccessToken is missing.';
        }
        if ($this->countryCode === '') {
            $errors[] = 'CountryCode is missing.';
        } elseif (self::resolveRegion($this->countryCode) === '') {
            $errors[] = 'CountryCode ' . $this->countryCode . ' is not supported by LG ThinQ Connect.';
        }
        if ($this->eventTtlHours < 1 || $this->eventTtlHours > 24) {
            $errors[] = 'EventTTL must be between 1 and 24 hours.';
        }
        if ($this->eventRenewLeadMin < 1 || $this->eventRenewLeadMin >= 60) {
            $errors[] = 'Event renew lead time must be between 1 and 59 minutes.';
        }
        return $errors;
    }

    /** The MQTT topic filter with {ClientID} filled in; empty means no filtering. */
    public function topicFilter(): string
    {
        return self::expandTopic($this->mqttTopicFilter, $this->clientId);
    }

    public static function expandTopic(string $filter, string $clientId): string
    {
        return str_replace('{ClientID}', $clientId, trim($filter));
    }

    public function baseUrl(): string
    {
        $region = self::resolveRegion($this->countryCode);
        return 'https://api-' . strtolower($region) . '.lgthinq.com/';
    }

    /** LG region per country, as in LG's SDK (pythinqconnect, country.py). */
    private const REGIONS = [
        'EIC' => 'AE AF AL AM AO AT AZ BA BE BF BG BH BJ BY CD CF CG CH CI CM CV CY CZ DE DJ DK DZ EE EG ES'
            . ' ET FI FR GA GB GE GH GM GN GQ GR HR HU IE IL IQ IR IS IT JO KE KG KW KZ LB LR LT LU LV LY'
            . ' MA MD ME MK ML MR MT MU MW NE NG NL NO OM PK PL PS PT QA RO RS RU RW SA SD SE SI SK SL SN'
            . ' SO ST SY TD TG TN TR TZ UA UG UZ XK YE ZA ZM',
        'AIC' => 'AG AR AW BB BO BR BS BZ CA CL CO CR CU DM DO EC GD GT GY HN HT JM KN LC MX NI PA PE PR PY'
            . ' SR SV TT US UY VC VE',
        'KIC' => 'AU BD CN HK ID IN JP KH KR LA LK MM MY NP NZ PH SG TH TW VN',
    ];

    /** EIC, AIC or KIC; '' for a country LG ThinQ Connect does not serve. */
    public static function resolveRegion(string $countryCode): string
    {
        $country = strtoupper(trim($countryCode));
        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            foreach (self::REGIONS as $region => $countries) {
                if (str_contains(' ' . $countries . ' ', ' ' . $country . ' ')) {
                    return $region;
                }
            }
        }
        return '';
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
