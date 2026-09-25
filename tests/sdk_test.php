<?php

declare(strict_types=1);

/*
 * The kernel in memory itself: IPSModuleStrict carries the signatures of Symcon's
 * ModuleStrictStubs (tests/fixtures/modulestrict_signatures.json, taken from SymconStubs as of
 * 2025-11), and a module on it behaves like one on IPSModule, with the TypeError Symcon raises for
 * an argument of the wrong type.
 */

require __DIR__ . '/bootstrap.php';

const STRICT_PROBE_GUID = '{5E1F4A2B-0C3D-4E5F-8A9B-1C2D3E4F5A6B}';

class StrictProbe extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Name', 'probe');
        $this->RegisterAttributeInteger('Count', 0);
        $this->RegisterTimer('Tick', 0, 'SP_Count($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->MaintainVariable('VALUE', 'Value', VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C'], 10, true);
        $this->EnableAction('VALUE');
        $this->SetTimerInterval('Tick', 1000);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        SetValueFloat($this->GetIDForIdent($Ident), (float)$Value);
        $this->Count();
    }

    public function Count(): int
    {
        $this->WriteAttributeInteger('Count', $this->ReadAttributeInteger('Count') + 1);
        return $this->ReadAttributeInteger('Count');
    }

    public function Wrong(): void
    {
        $this->SetBuffer('Buffer', 5); // an int where the typed signature wants a string
    }

    public function Missing(): string
    {
        return $this->ReadPropertyString('Nope');
    }
}

section('Signaturen wie in den ModuleStrictStubs');
$stub = json_decode((string)file_get_contents(__DIR__ . '/fixtures/modulestrict_signatures.json'), true);
$sig = static function (ReflectionMethod $m): string {
    $type = static function (?ReflectionType $t): string {
        $parts = explode('|', (string)$t);
        sort($parts);
        return implode('|', $parts);
    };
    $params = array_map(static fn(ReflectionParameter $p): string => ($p->hasType() ? $type($p->getType()) . ' ' : '') . '$' . $p->getName()
        . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : ''), $m->getParameters());
    return ($m->isPublic() ? 'public' : 'protected') . ' ' . $m->getName() . '(' . implode(', ', $params) . ')'
        . ($m->hasReturnType() ? ': ' . $type($m->getReturnType()) : '');
};
$wrong = [];
$count = 0;
foreach ((new ReflectionClass('IPSModuleStrict'))->getMethods() as $m) {
    if ($m->isPrivate() || $m->getName() === '__construct') {
        continue;
    }
    $count++;
    if (($stub[$m->getName()] ?? null) !== $sig($m)) {
        $wrong[] = $sig($m) . ' ≠ ' . ($stub[$m->getName()] ?? '(nicht in den Stubs)');
    }
}
check($wrong === [], $count . ' Methoden von IPSModuleStrict mit der Signatur der Stubs' . ($wrong === [] ? '' : ":\n  " . implode("\n  ", $wrong)));
$sdkMethods = static fn(string $class): array => array_map(static fn(ReflectionMethod $m): string => $m->getName(),
    array_filter((new ReflectionClass($class))->getMethods(), static fn(ReflectionMethod $m): bool => !$m->isPrivate() && $m->getName() !== '__construct'));
$missing = array_values(array_diff($sdkMethods('IPSModule'), $sdkMethods('IPSModuleStrict')));
check($missing === [], 'jede Methode von IPSModule gibt es typisiert in IPSModuleStrict' . ($missing === [] ? '' : ': ' . implode(', ', $missing)));

section('Ein Modul auf IPSModuleStrict');
Kernel::reset();
Kernel::registerModule(['ModuleID' => STRICT_PROBE_GUID, 'ModuleName' => 'Strict Probe', 'ModuleType' => 3, 'Prefix' => 'SP', 'class' => 'StrictProbe']);
Kernel::exportFunctions('StrictProbe', 'SP');
$id = IPS_CreateInstance(STRICT_PROBE_GUID);
$vid = IPS_GetObjectIDByIdent('VALUE', $id);
check(Kernel::$instances[$id]['status'] === IS_ACTIVE && Kernel::$instances[$id]['properties']['Name'] === 'probe', 'Anlegen: Create() und ApplyChanges() laufen, Eigenschaft mit Vorgabe');
check(Kernel::$variables[$vid]['type'] === VARIABLETYPE_FLOAT && (Kernel::$variables[$vid]['presentation']['SUFFIX'] ?? '') === ' °C', 'MaintainVariable mit Darstellung');
check(RequestAction($vid, 21.5) === true && GetValue($vid) === 21.5 && SP_Count($id) === 2, 'RequestAction und Attribut über die typisierten Methoden');
check(function_exists('SP_Count') && !function_exists('SP_GetReferenceList') && !function_exists('SP_Translate'), 'exportiert werden nur die Methoden des Moduls');
$error = '';
try {
    SP_Wrong($id);
} catch (\TypeError $e) {
    $error = $e->getMessage();
}
check(str_contains($error, 'SetBuffer()') && str_contains($error, 'must be of type string, int given'), 'falscher Argumenttyp ist ein TypeError wie in Symcon (' . $error . ')');
Kernel::$warnings = [];
check(SP_Missing($id) === '' && Kernel::$warnings !== [] && str_contains(Kernel::$warnings[0], 'Eigenschaft Nope nicht gefunden'),
    'fehlende Eigenschaft: Warnung und leerer Text statt false');

done();
