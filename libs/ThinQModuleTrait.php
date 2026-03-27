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

    private function findModuleGUIDByName(string $name): ?string
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
}
