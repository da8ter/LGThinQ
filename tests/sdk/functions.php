<?php

declare(strict_types=1);

/*
 * Global Symcon functions used by the LG ThinQ modules, on top of Kernel. IDs are int as in
 * the Symcon documentation; values are mixed and converted like 9.1 does (measured).
 */

// ------------------------------------------------------------------ kernel, log

function IPS_GetKernelRunlevel(): int { return Kernel::$runlevel; }
function IPS_GetKernelVersion(): string { return '9.1'; }
function IPS_GetSystemLanguage(): string { return Kernel::$language === 'de' ? 'de_DE' : 'en_US'; } // 9.1: "de_DE"
function IPS_GetKernelDir(): string { return sys_get_temp_dir() . '/'; }
function IPS_Sleep(int $Milliseconds): bool { return true; }

function IPS_LogMessage(string $Sender, string $Message): bool
{
    Kernel::log(0, 'CUSTOM', $Sender, $Message);
    return true;
}

// ------------------------------------------------------------------ objects

function objectOrWarn(int $ID): ?array
{
    if (!isset(Kernel::$objects[$ID])) {
        Kernel::warn(sprintf('Objekt #%d existiert nicht', $ID));
        return null;
    }
    return Kernel::$objects[$ID];
}

function IPS_ObjectExists(int $ID): bool { return isset(Kernel::$objects[$ID]); }
function IPS_VariableExists(int $VariableID): bool { return isset(Kernel::$variables[$VariableID]); }
function IPS_InstanceExists(int $InstanceID): bool { return isset(Kernel::$instances[$InstanceID]); }
function IPS_CategoryExists(int $CategoryID): bool { return (Kernel::$objects[$CategoryID]['type'] ?? -1) === OBJECTTYPE_CATEGORY; }

function IPS_GetObject(int $ID): array|false
{
    $o = objectOrWarn($ID);
    if ($o === null) {
        return false;
    }
    $children = Kernel::children($ID);
    return ['ChildrenIDs' => $children, 'HasChildren' => $children !== [], 'ObjectID' => $ID, 'ObjectIcon' => '',
        'ObjectIdent' => $o['ident'], 'ObjectInfo' => $o['info'], 'ObjectIsDisabled' => false, 'ObjectIsHidden' => false,
        'ObjectIsHiddenMaximize' => false, 'ObjectIsHiddenTitle' => false, 'ObjectIsLocked' => false,
        'ObjectIsReadOnly' => false, 'ObjectName' => $o['name'], 'ObjectPosition' => $o['position'],
        'ObjectSummary' => '', 'ObjectType' => $o['type'], 'ParentID' => $o['parent']];
}

function IPS_GetName(int $ID): string|false
{
    $o = objectOrWarn($ID);
    return $o === null ? false : $o['name'];
}

function IPS_SetName(int $ID, string $Name): bool
{
    if (objectOrWarn($ID) === null) {
        return false;
    }
    Kernel::$objects[$ID]['name'] = $Name;
    return true;
}

function IPS_SetIdent(int $ID, string $Ident): bool
{
    return objectOrWarn($ID) !== null && Kernel::setIdent($ID, $Ident);
}

function IPS_SetPosition(int $ID, int $Position): bool
{
    if (objectOrWarn($ID) === null) {
        return false;
    }
    Kernel::$objects[$ID]['position'] = $Position;
    return true;
}

function IPS_GetParent(int $ID): int|false
{
    $o = objectOrWarn($ID);
    return $o === null ? false : $o['parent'];
}

function IPS_SetParent(int $ID, int $ParentID): bool
{
    if (objectOrWarn($ID) === null) {
        return false;
    }
    $ident = Kernel::$objects[$ID]['ident'];
    if ($ident !== '' && Kernel::findIdent($ParentID, $ident) !== 0) {
        Kernel::warn('Ident muss für jede Ebene eindeutig sein');
        return false;
    }
    Kernel::$objects[$ID]['parent'] = $ParentID;
    return true;
}

function IPS_GetChildrenIDs(int $ID): array|false
{
    if ($ID !== 0 && objectOrWarn($ID) === null) {
        return false;
    }
    return Kernel::children($ID);
}

function IPS_GetObjectIDByIdent(string $Ident, int $ParentID): int|false
{
    $id = Kernel::findIdent($ParentID, $Ident);
    if ($id === 0) {
        Kernel::warn(sprintf('Objekt mit Ident %s wurde nicht gefunden', $Ident));
        return false;
    }
    return $id;
}

function IPS_CreateCategory(): int { return Kernel::createObject(OBJECTTYPE_CATEGORY); }
function IPS_DeleteCategory(int $CategoryID): bool { Kernel::deleteObject($CategoryID); return true; }

// ------------------------------------------------------------------ variables

function IPS_CreateVariable(int $VariableType): int { return Kernel::createVariable($VariableType); }

function IPS_DeleteVariable(int $VariableID): bool
{
    if (!isset(Kernel::$variables[$VariableID])) {
        Kernel::warn(sprintf('Variable #%d existiert nicht', $VariableID));
        return false;
    }
    Kernel::deleteObject($VariableID);
    return true;
}

function IPS_GetVariable(int $VariableID): array|false
{
    $v = Kernel::$variables[$VariableID] ?? null;
    if ($v === null) {
        Kernel::warn(sprintf('Variable #%d existiert nicht', $VariableID));
        return false;
    }
    return ['VariableAction' => $v['action'], 'VariableChanged' => $v['changed'], 'VariableCustomAction' => $v['customAction'],
        'VariableCustomPresentation' => $v['customPresentation'], 'VariableCustomProfile' => $v['customProfile'],
        'VariableID' => $VariableID, 'VariableIsLocked' => false, 'VariablePresentation' => $v['presentation'],
        'VariableProfile' => $v['profile'], 'VariableType' => $v['type'], 'VariableUpdated' => $v['updated'],
        'VariableValue' => $v['value']];
}

function IPS_SetVariableCustomPresentation(int $VariableID, array $Presentation): bool
{
    if (!isset(Kernel::$variables[$VariableID])) {
        Kernel::warn(sprintf('Variable #%d existiert nicht', $VariableID));
        return false;
    }
    if (!Kernel::acceptPresentation($Presentation, (int)Kernel::$variables[$VariableID]['type'])) {
        return false;
    }
    Kernel::$variables[$VariableID]['customPresentation'] = $Presentation;
    return true;
}

function IPS_GetVariableCustomPresentation(int $VariableID): array|false
{
    return isset(Kernel::$variables[$VariableID]) ? Kernel::$variables[$VariableID]['customPresentation'] : false;
}

function IPS_SetVariableCustomProfile(int $VariableID, string $ProfileName): bool
{
    if (!isset(Kernel::$variables[$VariableID])) {
        Kernel::warn(sprintf('Variable #%d existiert nicht', $VariableID));
        return false;
    }
    if ($ProfileName !== '' && !isset(Kernel::$profiles[$ProfileName])) {
        Kernel::warn(sprintf('Profil mit dem Namen #%s existiert nicht', $ProfileName));
        return false;
    }
    Kernel::$variables[$VariableID]['customProfile'] = $ProfileName;
    return true;
}

function IPS_SetVariableCustomAction(int $VariableID, int $ScriptID): bool
{
    if (!isset(Kernel::$variables[$VariableID])) {
        Kernel::warn(sprintf('Variable #%d existiert nicht', $VariableID));
        return false;
    }
    Kernel::$variables[$VariableID]['customAction'] = $ScriptID;
    return true;
}

function SetValue(int $VariableID, mixed $Value): bool { return Kernel::setValue($VariableID, $Value, null); }
function SetValueBoolean(int $VariableID, mixed $Value): bool { return Kernel::setValue($VariableID, $Value, VARIABLETYPE_BOOLEAN); }
function SetValueInteger(int $VariableID, mixed $Value): bool { return Kernel::setValue($VariableID, $Value, VARIABLETYPE_INTEGER); }
function SetValueFloat(int $VariableID, mixed $Value): bool { return Kernel::setValue($VariableID, $Value, VARIABLETYPE_FLOAT); }
function SetValueString(int $VariableID, mixed $Value): bool { return Kernel::setValue($VariableID, $Value, VARIABLETYPE_STRING); }
function GetValue(int $VariableID): mixed { return Kernel::getValue($VariableID, null); }
function GetValueBoolean(int $VariableID): mixed { return Kernel::getValue($VariableID, VARIABLETYPE_BOOLEAN); }
function GetValueInteger(int $VariableID): mixed { return Kernel::getValue($VariableID, VARIABLETYPE_INTEGER); }
function GetValueFloat(int $VariableID): mixed { return Kernel::getValue($VariableID, VARIABLETYPE_FLOAT); }
function GetValueString(int $VariableID): mixed { return Kernel::getValue($VariableID, VARIABLETYPE_STRING); }

/** Action of a variable (9.1, measured with EOS): errors come back as false plus a warning. */
function RequestAction(int $VariableID, mixed $Value): bool
{
    $v = Kernel::$variables[$VariableID] ?? null;
    $target = $v === null ? 0 : ($v['customAction'] > 0 ? $v['customAction'] : $v['action']);
    $obj = Kernel::$instances[$target]['object'] ?? null;
    if (!$obj instanceof IPSModuleBase) {
        Kernel::warn('No valid action available');
        return false;
    }
    try {
        $obj->RequestAction(Kernel::$objects[$VariableID]['ident'], $Value);
        return true;
    } catch (\Throwable $e) {
        Kernel::warn($e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------------ profiles

function IPS_VariableProfileExists(string $ProfileName): bool { return isset(Kernel::$profiles[$ProfileName]); }

function IPS_CreateVariableProfile(string $ProfileName, int $ProfileType): bool
{
    if (isset(Kernel::$profiles[$ProfileName])) {
        Kernel::warn(sprintf('Profil mit dem Namen %s existiert bereits', $ProfileName));
        return false;
    }
    Kernel::$profiles[$ProfileName] = Kernel::profileDef($ProfileType);
    return true;
}

function profileOrWarn(string $ProfileName): bool
{
    if (!isset(Kernel::$profiles[$ProfileName])) {
        Kernel::warn(sprintf('Profil mit dem Namen #%s existiert nicht', $ProfileName));
        return false;
    }
    return true;
}

function IPS_DeleteVariableProfile(string $ProfileName): bool
{
    if (!profileOrWarn($ProfileName)) {
        return false;
    }
    unset(Kernel::$profiles[$ProfileName]);
    return true;
}

function IPS_GetVariableProfile(string $ProfileName): array|false
{
    return profileOrWarn($ProfileName) ? Kernel::$profiles[$ProfileName] + ['ProfileName' => $ProfileName] : false;
}

function IPS_SetVariableProfileText(string $ProfileName, string $Prefix, string $Suffix): bool
{
    if (!profileOrWarn($ProfileName)) {
        return false;
    }
    Kernel::$profiles[$ProfileName]['Prefix'] = $Prefix;
    Kernel::$profiles[$ProfileName]['Suffix'] = $Suffix;
    return true;
}

function IPS_SetVariableProfileValues(string $ProfileName, float $MinValue, float $MaxValue, float $StepSize): bool
{
    if (!profileOrWarn($ProfileName)) {
        return false;
    }
    Kernel::$profiles[$ProfileName] = ['MinValue' => $MinValue, 'MaxValue' => $MaxValue, 'StepSize' => $StepSize] + Kernel::$profiles[$ProfileName];
    return true;
}

function IPS_SetVariableProfileDigits(string $ProfileName, int $Digits): bool
{
    if (!profileOrWarn($ProfileName)) {
        return false;
    }
    Kernel::$profiles[$ProfileName]['Digits'] = $Digits;
    return true;
}

function IPS_SetVariableProfileIcon(string $ProfileName, string $Icon): bool
{
    if (!profileOrWarn($ProfileName)) {
        return false;
    }
    Kernel::$profiles[$ProfileName]['Icon'] = $Icon;
    return true;
}

function IPS_SetVariableProfileAssociation(string $ProfileName, float $AssociationValue, string $Name, string $Icon, int $Color): bool
{
    if (!profileOrWarn($ProfileName)) {
        return false;
    }
    Kernel::$profiles[$ProfileName]['Associations'][] = ['Value' => $AssociationValue, 'Name' => $Name, 'Icon' => $Icon, 'Color' => $Color];
    return true;
}

// ------------------------------------------------------------------ instances, modules

function instanceOrWarn(int $InstanceID): bool
{
    if (!isset(Kernel::$instances[$InstanceID])) {
        Kernel::warn(sprintf('Instanz #%d existiert nicht', $InstanceID));
        return false;
    }
    return true;
}

function IPS_CreateInstance(string $ModuleID): int { return Kernel::createInstance($ModuleID); }

function IPS_DeleteInstance(int $InstanceID): bool
{
    if (!instanceOrWarn($InstanceID)) {
        return false;
    }
    Kernel::runEntry($InstanceID, static fn(IPSModuleBase $o) => $o->Destroy());
    Kernel::log($InstanceID, 'MESSAGE', Kernel::nameOf($InstanceID), 'Entferne...');
    Kernel::deleteObject($InstanceID);
    return true;
}

function IPS_GetInstance(int $InstanceID): array|false
{
    if (!instanceOrWarn($InstanceID)) {
        return false;
    }
    $i = Kernel::$instances[$InstanceID];
    $m = Kernel::moduleOf($InstanceID);
    return ['ConnectionID' => $i['connection'], 'InstanceChanged' => $i['changed'], 'InstanceID' => $InstanceID,
        'InstanceIsSearching' => false, 'InstanceStatus' => $i['status'], 'InstanceSupportsSearching' => false,
        'InstanceVisualizationType' => 0,
        'ModuleInfo' => ['ModuleID' => $i['module'], 'ModuleName' => $m['ModuleName'] ?? '', 'ModuleType' => $m['ModuleType'] ?? 0]];
}

function IPS_GetInstanceList(): array { return array_keys(Kernel::$instances); }

function IPS_GetInstanceListByModuleID(string $ModuleID): array
{
    $ids = [];
    foreach (Kernel::$instances as $id => $i) {
        if (strcasecmp($i['module'], $ModuleID) === 0) {
            $ids[] = $id;
        }
    }
    sort($ids);
    return $ids;
}

function IPS_ConnectInstance(int $InstanceID, int $ParentID): bool
{
    if (!instanceOrWarn($InstanceID) || !instanceOrWarn($ParentID)) {
        return false;
    }
    $needed = (array)(Kernel::moduleOf($InstanceID)['ParentRequirements'] ?? []);
    $offered = (array)(Kernel::moduleOf($ParentID)['Implemented'] ?? []);
    if (array_uintersect($needed, $offered, 'strcasecmp') === []) {
        Kernel::warn(sprintf('Instanz #%d ist nicht kompatibel mit Instanz #%d (Prüfstand)', $InstanceID, $ParentID));
        return false;
    }
    Kernel::$instances[$InstanceID]['connection'] = $ParentID;
    Kernel::sendMessage($InstanceID, FM_CONNECT, [$ParentID]);
    return true;
}

function IPS_DisconnectInstance(int $InstanceID): bool
{
    if (!instanceOrWarn($InstanceID)) {
        return false;
    }
    $parent = (int)Kernel::$instances[$InstanceID]['connection'];
    Kernel::$instances[$InstanceID]['connection'] = 0;
    Kernel::sendMessage($InstanceID, FM_DISCONNECT, [$parent]);
    return true;
}

function IPS_GetProperty(int $InstanceID, string $Name): mixed
{
    if (!instanceOrWarn($InstanceID)) {
        return false;
    }
    $props = Kernel::$instances[$InstanceID]['properties'];
    if (!array_key_exists($Name, $props)) {
        Kernel::warn(sprintf('Eigenschaft %s nicht gefunden', $Name));
        return false;
    }
    return $props[$Name];
}

/** Stages a value; IPS_ApplyChanges makes it active (GetProperty/GetConfiguration read the active state). */
function IPS_SetProperty(int $InstanceID, string $Name, mixed $Value): bool
{
    if (!instanceOrWarn($InstanceID)) {
        return false;
    }
    $inst = &Kernel::$instances[$InstanceID];
    if (!array_key_exists($Name, $inst['pending'])) {
        Kernel::warn(sprintf('Eigenschaft %s nicht gefunden', $Name));
        return false;
    }
    $current = $inst['pending'][$Name];
    if (!is_array($current) && !is_array($Value)) {
        settype($Value, gettype($current));
    }
    $inst['pending'][$Name] = $Value;
    return true;
}

function IPS_GetConfiguration(int $InstanceID): string|false
{
    return instanceOrWarn($InstanceID) ? (string)json_encode(Kernel::$instances[$InstanceID]['properties'], JSON_UNESCAPED_SLASHES) : false;
}

function IPS_ApplyChanges(int $InstanceID): bool { return Kernel::applyChanges($InstanceID); }

function IPS_GetModuleList(): array { return array_keys(Kernel::$modules); }

function IPS_ModuleExists(string $ModuleID): bool { return isset(Kernel::$modules[$ModuleID]); }

function IPS_GetModule(string $ModuleID): array|false
{
    $m = Kernel::$modules[$ModuleID] ?? null;
    if ($m === null) {
        Kernel::warn(sprintf('Modul #%s existiert nicht', $ModuleID));
        return false;
    }
    return array_diff_key($m, ['class' => 1, 'dir' => 1, 'defaults' => 1]);
}

function IPS_RequestAction(int $InstanceID, string $VariableIdent, mixed $Value): bool
{
    $obj = Kernel::$instances[$InstanceID]['object'] ?? null;
    if (!$obj instanceof IPSModuleBase) {
        Kernel::warn(sprintf('Instanz #%d existiert nicht', $InstanceID));
        return false;
    }
    try {
        $obj->RequestAction($VariableIdent, $Value);
        return true;
    } catch (\Throwable $e) {
        Kernel::warn($e->getMessage());
        return false;
    }
}
