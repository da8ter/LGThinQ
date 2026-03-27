<?php

declare(strict_types=1);

/**
 * CapabilityCatalogLoader
 *
 * Extracted from CapabilityEngine: catalog loading and rule matching for capability files.
 */
class CapabilityCatalogLoader
{
    /** @var array<string, mixed>|null */
    private static ?array $catalog = null;

    public function __construct(
        private string $baseDir,
        private int $instanceId
    ) {}

    /**
     * Resolve capability files for a given device type and profile.
     * @param array<string, mixed> $profile
     * @return array<int, string>
     */
    public function resolveCapabilityFiles(string $deviceType, array $profile): array
    {
        $catalog = $this->loadCatalog();
        $typeLower = strtolower((string)$deviceType);
        $profileText = strtolower(json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        // Build deviceType candidates (normalized variants)
        $candidates = [];
        $candidates[] = $typeLower;
        $stripped1 = (string)preg_replace('/^(device_|lge_|lg_)/', '', $typeLower);
        if ($stripped1 !== '' && $stripped1 !== $typeLower) { $candidates[] = $stripped1; }
        $spaced   = str_replace('_', ' ', $typeLower);
        if ($spaced !== '' && $spaced !== $typeLower) { $candidates[] = $spaced; }
        $noscore  = str_replace('_', '', $typeLower);
        if ($noscore !== '' && $noscore !== $typeLower) { $candidates[] = $noscore; }
        $candidates = array_values(array_unique(array_filter($candidates, function($v){ return is_string($v) && $v !== ''; })));

        $files = [];

        // 1) Strict deviceType-only matching: if any rules match any candidate, use ONLY those files
        $strictFiles = [];
        foreach ($catalog['rules'] as $rule) {
            foreach ($candidates as $cand) {
                if ($this->catalogRuleMatchesDeviceOnly($rule, $cand)) {
                    foreach ($rule['files'] as $file) {
                        $strictFiles[] = $this->baseDir . '/capabilities/' . $file;
                    }
                    break;
                }
            }
        }
        if (!empty($strictFiles)) {
            return array_values(array_unique($strictFiles));
        }

        // 2) Fallback: broader match using deviceType candidates + profile text
        foreach ($catalog['rules'] as $rule) {
            foreach ($candidates as $cand) {
                if ($this->catalogRuleMatches($rule, $cand, $profileText)) {
                    foreach ($rule['files'] as $file) {
                        $files[] = $this->baseDir . '/capabilities/' . $file;
                    }
                    break;
                }
            }
        }
        if (empty($files)) {
            foreach ($catalog['fallback'] as $fallback) {
                $files[] = $this->baseDir . '/capabilities/' . $fallback;
            }
        }

        $files = array_values(array_unique($files));
        return $files;
    }

    /**
     * Load the capability catalog from disk (cached per PHP process).
     * @return array<string, mixed>
     */
    public function loadCatalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $file = $this->baseDir . '/capabilities/catalog.json';
        $default = ['rules' => [], 'fallback' => []];
        if (!@is_file($file)) {
            self::$catalog = $default;
            return self::$catalog;
        }
        $json = @file_get_contents($file);
        if (!is_string($json) || $json === '') {
            self::$catalog = $default;
            return self::$catalog;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['rules']) || !is_array($data['rules'])) {
            self::$catalog = $default;
            return self::$catalog;
        }
        foreach ($data['rules'] as &$rule) {
            if (!isset($rule['files']) || !is_array($rule['files'])) {
                $rule['files'] = [];
            }
        }
        if (!isset($data['fallback']) || !is_array($data['fallback'])) {
            $data['fallback'] = [];
        }
        self::$catalog = $data;
        return self::$catalog;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function catalogRuleMatches(array $rule, string $deviceType, string $profileText): bool
    {
        $match = $rule['match'] ?? [];
        $exclude = $rule['exclude'] ?? [];
        if (!$this->matchesCondition($match, $deviceType, $profileText)) {
            return false;
        }
        if (!empty($exclude) && $this->matchesCondition($exclude, $deviceType, $profileText)) {
            return false;
        }
        return true;
    }

    /**
     * Like catalogRuleMatches but only considers the deviceType (ignores profileText).
     */
    private function catalogRuleMatchesDeviceOnly(array $rule, string $deviceType): bool
    {
        $match = $rule['match'] ?? [];
        $exclude = $rule['exclude'] ?? [];
        if (!$this->matchesCondition($match, $deviceType, '')) {
            return false;
        }
        if (!empty($exclude) && $this->matchesCondition($exclude, $deviceType, '')) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function matchesCondition(array $condition, string $deviceType, string $profileText): bool
    {
        if (empty($condition)) {
            return true;
        }
        $haystacks = [$deviceType];
        if ($profileText !== '') {
            $haystacks[] = $profileText;
        }

        if (isset($condition['any']) && is_array($condition['any'])) {
            $found = false;
            foreach ($condition['any'] as $needle) {
                $needle = strtolower((string)$needle);
                if ($needle === '') {
                    continue;
                }
                foreach ($haystacks as $haystack) {
                    if (strpos($haystack, $needle) !== false) {
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) {
                return false;
            }
        }

        if (isset($condition['all']) && is_array($condition['all'])) {
            foreach ($condition['all'] as $needle) {
                $needle = strtolower((string)$needle);
                if ($needle === '') {
                    continue;
                }
                $matches = false;
                foreach ($haystacks as $haystack) {
                    if (strpos($haystack, $needle) !== false) {
                        $matches = true;
                        break;
                    }
                }
                if (!$matches) {
                    return false;
                }
            }
        }

        if (isset($condition['regex']) && is_array($condition['regex'])) {
            $regexMatch = false;
            foreach ($condition['regex'] as $pattern) {
                $pattern = (string)$pattern;
                if ($pattern === '') {
                    continue;
                }
                $pattern = '/' . str_replace('/', '\/', $pattern) . '/i';
                foreach ($haystacks as $haystack) {
                    if (@preg_match($pattern, $haystack)) {
                        $regexMatch = true;
                        break 2;
                    }
                }
            }
            if (!$regexMatch) {
                return false;
            }
        }

        return true;
    }
}
