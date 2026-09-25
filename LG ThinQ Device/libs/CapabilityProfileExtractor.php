<?php

declare(strict_types=1);

/**
 * CapabilityProfileExtractor
 *
 * Extracted from CapabilityEngine: profile-parsing helpers for error/push options,
 * enum value extraction, and label mapping.
 */
class CapabilityProfileExtractor
{
    /** @var callable|null */
    private $translateCallback;

    public function __construct(
        private array $flatProfile,
        ?callable $translateCallback
    ) {
        $this->translateCallback = $translateCallback;
    }

    private function t(string $s): string
    {
        return $this->translateCallback ? ($this->translateCallback)($s) : $s;
    }

    /**
     * Extract presentation options for ERROR_LAST from the profile.
     * @param array<string, mixed> $profile
     * @return array<int, array{value: string, caption: string}>
     */
    public function extractErrorOptions(array $profile): array
    {
        $values = [];
        $labels = [];
        $node = $profile['error'] ?? null;
        if (is_array($node)) {
            $values = $this->extractEnumValuesFromNode($node);
            $labels = $this->extractLabelsMap($node);
        }
        if (empty($values)) {
            $values = $this->extractEnumValuesFromFlatPrefix('error');
            if (empty($values)) {
                $values = $this->extractEnumValuesFromFlatPrefix('property.error');
            }
        }
        if (empty($labels)) {
            $labels = $this->extractLabelsMapFromFlatPrefix('error');
            if (empty($labels)) {
                $labels = $this->extractLabelsMapFromFlatPrefix('property.error');
            }
        }
        $out = [];
        foreach ($values as $code) {
            $raw = $labels[$code] ?? $this->humanizeEnum($code);
            $caption = is_array($raw) ? $this->getBestLabel($raw) : (string)$raw;
            $caption = $this->t($caption);
            $out[] = ['value' => (string)$code, 'caption' => (string)$caption];
        }
        return $out;
    }

    /**
     * Extract presentation options for PUSH_LAST from the profile's notification.push section.
     * @param array<string, mixed> $profile
     * @return array<int, array{value: string, caption: string}>
     */
    public function extractPushOptions(array $profile): array
    {
        $values = [];
        $labels = [];
        $notif = $profile['notification'] ?? null;
        if (is_array($notif)) {
            $push = $notif['push'] ?? null;
            if (is_array($push)) {
                $values = $this->extractEnumValuesFromNode($push);
                $labels = $this->extractLabelsMap($push);
            }
        }
        if (empty($values)) {
            $values = $this->extractEnumValuesFromFlatPrefix('notification.push');
            if (empty($values)) {
                $values = $this->extractEnumValuesFromFlatPrefix('property.notification.push');
            }
        }
        if (empty($labels)) {
            $labels = $this->extractLabelsMapFromFlatPrefix('notification.push');
            if (empty($labels)) {
                $labels = $this->extractLabelsMapFromFlatPrefix('property.notification.push');
            }
        }
        $out = [];
        foreach ($values as $code) {
            $raw = $labels[$code] ?? $this->humanizeEnum($code);
            $caption = is_array($raw) ? $this->getBestLabel($raw) : (string)$raw;
            $caption = $this->t($caption);
            $out[] = ['value' => (string)$code, 'caption' => (string)$caption];
        }
        return $out;
    }

    /**
     * Extract enum list from a profile node.
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    public function extractEnumValuesFromNode(array $node): array
    {
        $vals = [];
        if (isset($node['value'])) {
            $v = $node['value'];
            if (is_array($v)) {
                if (isset($v['r']) && is_array($v['r'])) { $vals = array_merge($vals, array_map('strval', array_values($v['r']))); }
                if (isset($v['w']) && is_array($v['w'])) { $vals = array_merge($vals, array_map('strval', array_values($v['w']))); }
            }
        }
        foreach (['values','codes','enums','list'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) {
                foreach ($node[$k] as $item) {
                    if (is_string($item)) { $vals[] = $item; }
                    elseif (is_array($item) && isset($item['code']) && is_string($item['code'])) { $vals[] = $item['code']; }
                }
            }
        }
        if (empty($vals)) {
            foreach ($node as $item) {
                if (is_string($item) || is_numeric($item)) { $vals[] = (string)$item; continue; }
                if (is_array($item) && isset($item['code']) && is_string($item['code'])) { $vals[] = (string)$item['code']; }
            }
        }
        $seen = [];
        $uniq = [];
        foreach ($vals as $v) {
            if (isset($seen[$v])) continue;
            $seen[$v] = true;
            $uniq[] = (string)$v;
        }
        return $uniq;
    }

    /**
     * Extract mapping code => label from profile node.
     * @param array<string, mixed> $node
     * @return array<string, string>
     */
    public function extractLabelsMap(array $node): array
    {
        foreach (['text','label','labels','names','descriptions'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) {
                $map = [];
                foreach ($node[$k] as $code => $label) {
                    if (!is_string($code)) { continue; }
                    if (is_string($label) || is_numeric($label)) {
                        $map[(string)$code] = (string)$label;
                    } elseif (is_array($label)) {
                        $map[(string)$code] = $this->getBestLabel($label);
                    }
                }
                if (!empty($map)) return $map;
            }
        }
        foreach (['values','codes','items','list'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) {
                $map = [];
                foreach ($node[$k] as $entry) {
                    if (is_array($entry)) {
                        $code = $entry['code'] ?? null;
                        $label = $entry['text'] ?? ($entry['label'] ?? ($entry['name'] ?? ($entry['description'] ?? null)));
                        if (!is_string($code)) { continue; }
                        if (is_string($label) || is_numeric($label)) {
                            $map[(string)$code] = (string)$label;
                        } elseif (is_array($label)) {
                            $map[(string)$code] = $this->getBestLabel($label);
                        }
                    }
                }
                if (!empty($map)) return $map;
            }
        }
        return [];
    }

    /**
     * Choose best localized label from a map like { de: '...', en: '...' }
     * @param array<string, mixed> $labels
     */
    public function getBestLabel(array $labels): string
    {
        foreach ([ThinQNaming::systemLanguage(), 'en'] as $language) {
            if (isset($labels[$language]) && is_string($labels[$language])) { return $labels[$language]; }
        }
        foreach ($labels as $v) {
            if (is_string($v)) { return (string)$v; }
        }
        return json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Humanize an enum code (e.g., WATER_DRAIN_ERROR -> "Water Drain Error")
     */
    public function humanizeEnum(string $code): string
    {
        $readable = str_replace('_', ' ', $code);
        return ucwords(strtolower($readable));
    }

    /**
     * Extract enum values by scanning the flattened profile for keys with a given prefix.
     * @return array<int, string>
     */
    public function extractEnumValuesFromFlatPrefix(string $prefix): array
    {
        $vals = [];
        $pfx = $prefix . '.';
        foreach ($this->flatProfile as $k => $v) {
            $ks = (string)$k;
            if (strpos($ks, $pfx) !== 0) continue;
            if (preg_match('#^' . preg_quote($pfx, '#') . '(?:value\.(?:r|w)|values|codes|enums|list)\.(\d+)$#', $ks, $m)) {
                if (is_string($v) || is_numeric($v)) {
                    $vals[(int)$m[1]] = (string)$v;
                }
            } elseif (preg_match('#^' . preg_quote($pfx, '#') . '(\d+)$#', $ks, $m)) {
                if (is_string($v) || is_numeric($v)) {
                    $vals[(int)$m[1]] = (string)$v;
                }
            }
        }
        if (!empty($vals)) {
            ksort($vals, SORT_NUMERIC);
            return array_values($vals);
        }
        return [];
    }

    /**
     * Extract labels map by scanning flattened profile for maps like prefix.text.CODE = "Label"
     * @return array<string, string>
     */
    public function extractLabelsMapFromFlatPrefix(string $prefix): array
    {
        $map = [];
        $pfx = $prefix . '.';
        foreach ($this->flatProfile as $k => $v) {
            $ks = (string)$k;
            if (preg_match('#^' . preg_quote($pfx, '#') . '(?:text|label|labels|names|descriptions)\.([^\.]+)$#', $ks, $m)) {
                if (is_string($v) || is_numeric($v)) {
                    $map[(string)$m[1]] = (string)$v;
                }
            }
        }
        if (!empty($map)) return $map;
        $codes = [];
        $texts = [];
        foreach ($this->flatProfile as $k => $v) {
            $ks = (string)$k;
            if (preg_match('#^' . preg_quote($pfx, '#') . 'values\.(\d+)\.(code)$#', $ks, $m)) {
                if (is_string($v)) { $codes[(int)$m[1]] = (string)$v; }
            }
            if (preg_match('#^' . preg_quote($pfx, '#') . 'values\.(\d+)\.(text|label|name|description)$#', $ks, $m)) {
                if (is_string($v) || is_numeric($v)) { $texts[(int)$m[1]] = (string)$v; }
            }
        }
        if (!empty($codes) && !empty($texts)) {
            foreach ($codes as $i => $code) {
                $label = $texts[$i] ?? null;
                if (is_string($label) && $label !== '') { $map[$code] = $label; }
            }
        }
        return $map;
    }

    /**
     * Extract last error code/text from status payload.
     * @param array<string, mixed> $status
     * @param array<string, mixed> $flat
     */
    public function extractErrorFromStatus(array $status, array $flat): ?string
    {
        if (isset($status['error'])) {
            $e = $status['error'];
            if (is_string($e) && $e !== '') return $e;
            if (is_array($e)) {
                foreach (['code','errorCode','id','name','text','message','description','error'] as $k) {
                    $v = $e[$k] ?? null;
                    if (is_string($v) && $v !== '') return $v;
                }
            }
        }
        if (isset($status['errorCode']) && is_string($status['errorCode']) && $status['errorCode'] !== '') {
            return $status['errorCode'];
        }
        $candidates = [
            'error.code','errorCode','lastError','error.last','error.id','error.name','error.text','error.message','status.error','property.error'
        ];
        foreach ($candidates as $k) {
            $v = $flat[$k] ?? null;
            if (is_string($v) && $v !== '') return $v;
        }
        foreach ($flat as $k => $v) {
            if (stripos((string)$k, 'error') !== false && is_string($v) && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    /**
     * Extract last push code/text from status payload.
     * @param array<string, mixed> $status
     * @param array<string, mixed> $flat
     */
    public function extractPushFromStatus(array $status, array $flat): ?string
    {
        $notif = $status['notification'] ?? null;
        if (is_array($notif)) {
            $push = $notif['push'] ?? null;
            if (is_string($push) && $push !== '') return $push;
            if (is_array($push)) {
                foreach (['code','event','name','text','message','description','id'] as $k) {
                    if (isset($push[$k]) && is_string($push[$k]) && $push[$k] !== '') return $push[$k];
                }
                $arr = $push;
                $list = [];
                foreach ($arr as $key => $item) { if (is_array($item) || is_string($item)) $list[] = $item; }
                for ($i = count($list) - 1; $i >= 0; $i--) {
                    $item = $list[$i];
                    if (is_string($item) && $item !== '') return $item;
                    if (is_array($item)) {
                        foreach (['code','event','name','text','message','description','id'] as $k) {
                            if (isset($item[$k]) && is_string($item[$k]) && $item[$k] !== '') return $item[$k];
                        }
                    }
                }
            }
        }
        if (isset($status['push']) && is_string($status['push']) && $status['push'] !== '') return $status['push'];
        $last = null;
        foreach ($flat as $k => $v) {
            $ks = (string)$k;
            if (stripos($ks, 'push') !== false) {
                if (is_string($v) && $v !== '') { $last = $v; continue; }
                if (is_array($v)) {
                    foreach (['code','event','name','text','message','description','id'] as $f) {
                        $vv = $v[$f] ?? null;
                        if (is_string($vv) && $vv !== '') { $last = $vv; break; }
                    }
                }
            }
        }
        return $last;
    }
}
