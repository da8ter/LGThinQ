<?php

declare(strict_types=1);

/**
 * ThinQControlGuard
 *
 * What LG refuses is remembered per variable. A refused command is written to ERROR_LAST and the
 * log instead of surfacing as an uncaught exception in the visualization. When LG answers with a
 * feature code (2201 NOT_PROVIDED_FEATURE, 1220 NOT_SUPPORTED_FEATURE) the variable and its timer
 * partner (hours <-> minutes) lose their action until the device profile changes: the profile marked
 * the property writable, the LG backend does not provide it for this model (measured 01.10.2026 with
 * the sleep timer of an air conditioner; the same pattern is known from pythinqconnect issues 54/56).
 */
final class ThinQControlGuard
{
    public const ATTRIBUTE = 'BlockedIdents';
    private const FEATURE_CODES = ['2201', '1220'];

    public function __construct(private ThinQModuleContext $ctx)
    {
    }

    /** @return array<int, string> idents whose action LG refused */
    public function blocked(): array
    {
        $list = json_decode((string)@$this->ctx->attributeString(self::ATTRIBUTE), true);
        return is_array($list) ? array_values(array_filter(array_map('strval', $list), static fn(string $i): bool => $i !== '')) : [];
    }

    public function isBlocked(string $ident): bool
    {
        return in_array($ident, $this->blocked(), true);
    }

    public function clear(): void
    {
        if ($this->blocked() !== []) {
            $this->ctx->writeAttributeString(self::ATTRIBUTE, '[]');
            $this->ctx->debug('ControlGuard', 'profile changed: refused controls forgotten');
        }
    }

    /**
     * A command LG or the plan refused. Writes ERROR_LAST and a warning; on a feature code the
     * variable and its timer partner lose their action. Returns the text written to ERROR_LAST.
     */
    public function refused(string $ident, string $message): string
    {
        $code = preg_match('/API error (\d{4})/', $message, $m) === 1 ? $m[1] : '';
        $text = $this->ctx->t('Command refused') . ' (' . $ident . '): ' . self::shorten($this->ctx->t($message));
        $this->writeLastError($text);
        $this->ctx->log($text, KL_WARNING);
        if (in_array($code, self::FEATURE_CODES, true)) {
            $this->block($ident);
        }
        return $text;
    }

    /** @return array<int, string> the ident and, for a timer value, its hour/minute partner */
    public static function family(string $ident): array
    {
        $family = [$ident];
        if (preg_match('/_HOUR(_|$)/', $ident) === 1) {
            $family[] = (string)preg_replace('/_HOUR(_|$)/', '_MINUTE$1', $ident);
        } elseif (preg_match('/_MINUTE(_|$)/', $ident) === 1) {
            $family[] = (string)preg_replace('/_MINUTE(_|$)/', '_HOUR$1', $ident);
        }
        return $family;
    }

    private function block(string $ident): void
    {
        $list = $this->blocked();
        foreach (self::family($ident) as $member) {
            if (!in_array($member, $list, true)) {
                $list[] = $member;
            }
            try {
                $this->ctx->disableAction($member);
            } catch (\Throwable $e) {
                // no such variable
            }
        }
        $this->ctx->writeAttributeString(self::ATTRIBUTE, (string)json_encode($list));
        $this->ctx->log(sprintf('%s: %s', implode(', ', self::family($ident)), $this->ctx->t('LG does not provide this feature for this device; the variable is read-only until the profile changes')), KL_WARNING);
    }

    private function writeLastError(string $text): void
    {
        $vid = (int)@IPS_GetObjectIDByIdent('ERROR_LAST', $this->ctx->instanceId);
        if ($vid > 0) {
            ThinQValue::write($vid, $text);
        }
    }

    private static function shorten(string $message): string
    {
        $message = (string)preg_replace('/\s*\(https?:\/\/[^)]*\)/', '', $message); // the LG URL carries the device ID
        return mb_strlen($message) > 160 ? mb_substr($message, 0, 157) . '…' : $message;
    }
}
