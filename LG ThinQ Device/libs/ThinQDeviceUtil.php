<?php

declare(strict_types=1);

/**
 * ThinQDeviceUtil
 *
 * Extracted from LG ThinQ Device/module.php.
 * Pure utility methods: the Bridge's answer, value setting, array flattening, anonymization and
 * throwable logging.
 */
class ThinQDeviceUtil
{
    public function __construct(private ThinQModuleContext $ctx)
    {
    }

    public function setValueByVarType(string $ident, mixed $value): void
    {
        ThinQValue::write((int)@IPS_GetObjectIDByIdent($ident, $this->ctx->instanceId), $value);
    }

    /**
     * The Bridge's answer to a ForwardData call: an error as exception, else the payload (devices,
     * status, profile, energy) as JSON, anything else as it came; nothing without a parent.
     */
    public static function bridgeResult(mixed $result): string
    {
        if (!is_string($result)) {
            return '';
        }
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return $result;
        }
        if (($decoded['success'] ?? true) === false) {
            $error = '';
            if (isset($decoded['error'])) {
                $error = (string)$decoded['error'];
            } elseif (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $error = implode('; ', array_map('strval', $decoded['errors']));
            } elseif (isset($decoded['message'])) {
                $error = (string)$decoded['message'];
            }
            if ($error === '') {
                $error = 'unknown error (payload: ' . substr((string)preg_replace('/\s+/', ' ', $result), 0, 200) . ')';
            }
            throw new Exception($error);
        }
        foreach (['devices', 'status', 'profile', 'energyProfile', 'energyData'] as $key) {
            if (isset($decoded[$key])) {
                return (string)json_encode($decoded[$key]);
            }
        }
        return $result;
    }

    public static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
            if (is_array($value)) {
                $result += self::flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }
        return $result;
    }

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
        $this->ctx->debug($context, $e->getMessage());
    }
}
