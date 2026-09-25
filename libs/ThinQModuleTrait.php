<?php

declare(strict_types=1);

require_once __DIR__ . '/ThinQClock.php';
require_once __DIR__ . '/ThinQModuleContext.php';
require_once __DIR__ . '/ThinQJsonAttribute.php';

trait ThinQModuleTrait
{
    private ?ThinQModuleContext $thinqContext = null;

    /** The module as its helper classes may use it; see ThinQModuleContext. Nothing of it is exported. */
    private function moduleContext(): ThinQModuleContext
    {
        return $this->thinqContext ??= new ThinQModuleContext(
            instanceId: $this->InstanceID,
            debug: fn(string $tag, string $message) => $this->SendDebug($tag, $message, 0),
            translate: fn(string $text): string => (string)$this->Translate($text),
            property: fn(string $name, string $type): mixed => match ($type) {
                'bool' => $this->ReadPropertyBoolean($name),
                'int' => $this->ReadPropertyInteger($name),
                default => $this->ReadPropertyString($name),
            },
            attribute: fn(string $name, string $type): mixed => $type === 'int' ? $this->ReadAttributeInteger($name) : $this->ReadAttributeString($name),
            writeAttribute: function (string $name, int|string $value): void {
                if (is_int($value)) {
                    $this->WriteAttributeInteger($name, $value);
                } else {
                    $this->WriteAttributeString($name, $value);
                }
            },
            buffer: fn(string $name): string => (string)$this->GetBuffer($name),
            setBuffer: fn(string $name, string $value) => $this->SetBuffer($name, $value),
            log: fn(string $message, int $type) => $this->LogMessage($message, $type),
            maintainVariable: fn(string $ident, string $name, int $type, string|array $presentation, int $position, bool $keep): bool
                => (bool)$this->MaintainVariable($ident, $name, $type, $presentation, $position, $keep),
            enableAction: fn(string $ident) => $this->EnableAction($ident),
            setTimerInterval: fn(string $ident, int $milliseconds) => $this->SetTimerInterval($ident, $milliseconds),
            hasActiveParent: fn(): bool => (bool)$this->HasActiveParent()
        );
    }

    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }

    private function isKernelReady(): bool
    {
        return function_exists('IPS_GetKernelRunlevel') ? (IPS_GetKernelRunlevel() === KR_READY) : true;
    }

    // ---------------------------------------------------------------------------
    // Public proxies for protected IPSModule methods — used by injected lib classes
    // ---------------------------------------------------------------------------

    public function publicGetInstanceId(): int
    {
        return $this->InstanceID;
    }

    public function publicSendDebug(string $sender, string $message, int $format): void
    {
        $this->SendDebug($sender, $message, $format);
    }

    public function publicTranslate(string $text): string
    {
        return $this->Translate($text);
    }

    public function publicReadPropertyString(string $name): string
    {
        return $this->ReadPropertyString($name);
    }

    public function publicReadPropertyInteger(string $name): int
    {
        return $this->ReadPropertyInteger($name);
    }

    public function publicReadPropertyBoolean(string $name): bool
    {
        return $this->ReadPropertyBoolean($name);
    }

    public function publicReadAttributeString(string $name): string
    {
        return $this->ReadAttributeString($name);
    }

    public function publicWriteAttributeString(string $name, string $value): void
    {
        $this->WriteAttributeString($name, $value);
    }

    public function publicMaintainVariable(string $ident, string $name, int $type, mixed $profile, int $position, bool $keep): void
    {
        $this->MaintainVariable($ident, $name, $type, $profile, $position, $keep);
    }

    public function publicSetTimerInterval(string $name, int $milliseconds): void
    {
        $this->SetTimerInterval($name, $milliseconds);
    }

    public function publicRegisterTimer(string $name, int $milliseconds, string $script): void
    {
        $this->RegisterTimer($name, $milliseconds, $script);
    }
}
