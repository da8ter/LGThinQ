<?php

declare(strict_types=1);

trait ThinQModuleTrait
{
    private function t(string $text): string
    {
        return method_exists($this, 'Translate') ? $this->Translate($text) : $text;
    }

    private function isKernelReady(): bool
    {
        return function_exists('IPS_GetKernelRunlevel') ? (IPS_GetKernelRunlevel() === KR_READY) : true;
    }

    public function findModuleGUIDByName(string $name): ?string
    {
        foreach (@IPS_GetModuleList() as $guid) {
            $m = @IPS_GetModule($guid);
            if (!is_array($m)) { continue; }
            $names = array_merge([$m['ModuleName'] ?? ''], $m['Aliases'] ?? []);
            foreach ($names as $n) {
                if (mb_strtolower((string)$n) === mb_strtolower($name)) { return (string)$guid; }
            }
        }
        return null;
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

    public function publicMaintainReferences(array $references): void
    {
        $this->MaintainReferences($references);
    }
}
