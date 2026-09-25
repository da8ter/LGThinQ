<?php

declare(strict_types=1);

/**
 * ThinQDeviceUtil
 *
 * Extracted from LG ThinQ Device/module.php.
 * Pure utility methods: value setting, array flattening, anonymization, reference management,
 * deep merging, and throwable logging.
 */
class ThinQDeviceUtil
{
    private int $instanceId;

    public function __construct(
        private IPSModule $module,
        int $instanceId
    ) {
        $this->instanceId = $instanceId;
    }

    public function setValueByVarType(string $ident, mixed $value): void
    {
        $vid = (int)@IPS_GetObjectIDByIdent($ident, $this->instanceId);
        if ($vid <= 0) {
            return;
        }
        $var = @IPS_GetVariable($vid);
        if (!is_array($var)) {
            return;
        }
        switch ((int)$var['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                @SetValueBoolean($vid, (bool)$value);
                break;
            case VARIABLETYPE_INTEGER:
                @SetValueInteger($vid, (int)$value);
                break;
            case VARIABLETYPE_FLOAT:
                @SetValueFloat($vid, (float)$value);
                break;
            case VARIABLETYPE_STRING:
                @SetValueString($vid, (string)$value);
                break;
        }
    }

    public function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
            if (is_array($value)) {
                $result += $this->flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }
        return $result;
    }

    public function firstNumericByPaths(array $flat, array $paths): ?float
    {
        foreach ($paths as $path) {
            $path = (string)$path;
            if ($path === '') {
                continue;
            }
            $candidates = [$path, 'property.' . $path, 'value.' . $path, 'profile.' . $path];
            for ($i = 0; $i <= 4; $i++) {
                $candidates[] = 'property.' . $i . '.' . $path;
                $candidates[] = 'value.property.' . $i . '.' . $path;
                $candidates[] = 'profile.property.' . $i . '.' . $path;
                $candidates[] = 'profile.value.property.' . $i . '.' . $path;
            }
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $flat) && is_numeric($flat[$candidate])) {
                    return (float)$flat[$candidate];
                }
            }
        }
        return null;
    }

    public function updateReferences(): void
    {
        $references = [];
        $inst       = @IPS_GetInstance($this->instanceId);
        $parentId   = is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
        if ($parentId > 0) {
            $references[] = $parentId;
        }
        if (method_exists($this->module, 'MaintainReferences')) {
            $this->module->publicMaintainReferences($references);
        }
    }

    public function maskText(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (strlen($s) <= 4) return substr($s, 0, 1) . '***' . substr($s, -1);
        return substr($s, 0, 2) . '***' . substr($s, -2);
    }

    /**
     * Recursively anonymize sensitive strings in an array structure.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function anonymizeArray(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            // Preserve specific fields verbatim (requested: deviceType, modelName)
            $keyNorm  = strtolower((string)$k);
            $preserve = (
                $keyNorm === 'devicetype' ||
                $keyNorm === 'modelname' ||
                $keyNorm === 'device_type' ||
                $keyNorm === 'model_name'
            );
            if ($preserve && is_string($v)) {
                $out[$k] = $v;
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $this->anonymizeArray($v);
            } elseif (is_string($v)) {
                $out[$k] = $this->anonymizeText($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function anonymizeText(string $s): string
    {
        $r = $s;
        // Emails -> ***@***
        $r = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+/i', '***@***', $r);
        // IPv4 -> ***.***.***.***
        $r = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '***.***.***.***', $r);
        // MAC -> **:**:**:**:**:**
        $r = preg_replace('/\b([0-9A-Fa-f]{2}[:\-]){5}([0-9A-Fa-f]{2})\b/', '**:**:**:**:**:**', $r);
        // UUID -> ****-****-****-****-XXXXXXXX
        $r = preg_replace_callback(
            '/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/',
            function ($m) {
                $t = (string)$m[0];
                return '****-****-****-****-' . substr($t, -12);
            },
            $r
        );
        // Long tokens/IDs (12+ alnum/_-) -> keep first 2 and last 2
        $r = preg_replace_callback('/[A-Za-z0-9_\-]{12,}/', function ($m) {
            $t = (string)$m[0];
            return substr($t, 0, 2) . '***' . substr($t, -2);
        }, $r);
        return $r;
    }

    public function logThrowable(string $context, \Throwable $e): void
    {
        $this->module->publicSendDebug($context, $e->getMessage(), 0);
    }

    public function deepMerge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
