<?php

declare(strict_types=1);

/*
 * IPSModuleStrict: the kernel behaviour of IPSModuleCore with the typed signatures of Symcon's
 * ModuleStrictStubs (SymconStubs by Symcon GmbH, state 2025-11 with ProfileOrPresentation as
 * string|array; tests/fixtures/modulestrict_signatures.json, compared by sdk_test.php).
 * Overrides of a module must match them, and a call with a wrong type fails with a TypeError as
 * it does in Symcon. Where the kernel warns and has no value (a missing property or attribute, no
 * parent) the typed method returns the empty value of its type.
 */
class IPSModuleStrict implements IPSModuleBase
{
    use IPSModuleCore {
        Create as private coreCreate; Destroy as private coreDestroy; ApplyChanges as private coreApplyChanges;
        MessageSink as private coreMessageSink; ReceiveData as private coreReceiveData;
        ForwardData as private coreForwardData; RequestAction as private coreRequestAction;
        GetConfigurationForm as private coreGetConfigurationForm;
        GetConfigurationForParent as private coreGetConfigurationForParent; Translate as private coreTranslate;
        GetReferenceList as private coreGetReferenceList;
        RegisterPropertyBoolean as private coreRegisterPropertyBoolean;
        RegisterPropertyInteger as private coreRegisterPropertyInteger;
        RegisterPropertyFloat as private coreRegisterPropertyFloat;
        RegisterPropertyString as private coreRegisterPropertyString;
        ReadPropertyBoolean as private coreReadPropertyBoolean;
        ReadPropertyInteger as private coreReadPropertyInteger; ReadPropertyFloat as private coreReadPropertyFloat;
        ReadPropertyString as private coreReadPropertyString;
        RegisterAttributeBoolean as private coreRegisterAttributeBoolean;
        RegisterAttributeInteger as private coreRegisterAttributeInteger;
        RegisterAttributeFloat as private coreRegisterAttributeFloat;
        RegisterAttributeString as private coreRegisterAttributeString;
        ReadAttributeBoolean as private coreReadAttributeBoolean;
        ReadAttributeInteger as private coreReadAttributeInteger;
        ReadAttributeFloat as private coreReadAttributeFloat; ReadAttributeString as private coreReadAttributeString;
        WriteAttributeBoolean as private coreWriteAttributeBoolean;
        WriteAttributeInteger as private coreWriteAttributeInteger;
        WriteAttributeFloat as private coreWriteAttributeFloat;
        WriteAttributeString as private coreWriteAttributeString; RegisterTimer as private coreRegisterTimer;
        SetTimerInterval as private coreSetTimerInterval; GetTimerInterval as private coreGetTimerInterval;
        RegisterVariableBoolean as private coreRegisterVariableBoolean;
        RegisterVariableInteger as private coreRegisterVariableInteger;
        RegisterVariableFloat as private coreRegisterVariableFloat;
        RegisterVariableString as private coreRegisterVariableString;
        MaintainVariable as private coreMaintainVariable; UnregisterVariable as private coreUnregisterVariable;
        EnableAction as private coreEnableAction; DisableAction as private coreDisableAction;
        MaintainAction as private coreMaintainAction; GetIDForIdent as private coreGetIDForIdent;
        SendDataToParent as private coreSendDataToParent; SendDataToChildren as private coreSendDataToChildren;
        ConnectParent as private coreConnectParent; RequireParent as private coreRequireParent;
        HasActiveParent as private coreHasActiveParent; RegisterMessage as private coreRegisterMessage;
        UnregisterMessage as private coreUnregisterMessage; GetMessageList as private coreGetMessageList;
        SetStatus as private coreSetStatus; GetStatus as private coreGetStatus; SetSummary as private coreSetSummary;
        SetBuffer as private coreSetBuffer; GetBuffer as private coreGetBuffer; SendDebug as private coreSendDebug;
        LogMessage as private coreLogMessage; RegisterReference as private coreRegisterReference;
        UnregisterReference as private coreUnregisterReference;
        SetReceiveDataFilter as private coreSetReceiveDataFilter;
        SetForwardDataFilter as private coreSetForwardDataFilter;
    }

    public function __construct(int $InstanceID)
    {
        $this->InstanceID = $InstanceID;
    }

    public function Create(): void
    {
        $this->coreCreate();
    }

    public function Destroy(): void
    {
        $this->coreDestroy();
    }

    public function ApplyChanges(): void
    {
        $this->coreApplyChanges();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $this->coreMessageSink($TimeStamp, $SenderID, $Message, $Data);
    }

    public function ReceiveData(string $JSONString): string
    {
        return (string)$this->coreReceiveData($JSONString);
    }

    public function ForwardData(string $JSONString): string
    {
        return (string)$this->coreForwardData($JSONString);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $this->coreRequestAction($Ident, $Value);
    }

    public function GetConfigurationForm(): string
    {
        return (string)$this->coreGetConfigurationForm();
    }

    public function GetConfigurationForParent(): string
    {
        return (string)$this->coreGetConfigurationForParent();
    }

    public function Translate(string $Text): string
    {
        return (string)$this->coreTranslate($Text);
    }

    public function GetReferenceList(): array
    {
        return (array)$this->coreGetReferenceList();
    }

    protected function RegisterPropertyBoolean(string $Name, bool $DefaultValue): bool
    {
        $this->coreRegisterPropertyBoolean($Name, $DefaultValue);
        return true;
    }

    protected function RegisterPropertyInteger(string $Name, int $DefaultValue): bool
    {
        $this->coreRegisterPropertyInteger($Name, $DefaultValue);
        return true;
    }

    protected function RegisterPropertyFloat(string $Name, float $DefaultValue): bool
    {
        $this->coreRegisterPropertyFloat($Name, $DefaultValue);
        return true;
    }

    protected function RegisterPropertyString(string $Name, string $DefaultValue): bool
    {
        $this->coreRegisterPropertyString($Name, $DefaultValue);
        return true;
    }

    protected function ReadPropertyBoolean(string $Name): bool
    {
        return (bool)$this->coreReadPropertyBoolean($Name);
    }

    protected function ReadPropertyInteger(string $Name): int
    {
        return (int)$this->coreReadPropertyInteger($Name);
    }

    protected function ReadPropertyFloat(string $Name): float
    {
        return (float)$this->coreReadPropertyFloat($Name);
    }

    protected function ReadPropertyString(string $Name): string
    {
        return (string)$this->coreReadPropertyString($Name);
    }

    protected function RegisterAttributeBoolean(string $Name, bool $DefaultValue): bool
    {
        $this->coreRegisterAttributeBoolean($Name, $DefaultValue);
        return true;
    }

    protected function RegisterAttributeInteger(string $Name, int $DefaultValue): bool
    {
        $this->coreRegisterAttributeInteger($Name, $DefaultValue);
        return true;
    }

    protected function RegisterAttributeFloat(string $Name, float $DefaultValue): bool
    {
        $this->coreRegisterAttributeFloat($Name, $DefaultValue);
        return true;
    }

    protected function RegisterAttributeString(string $Name, string $DefaultValue): bool
    {
        $this->coreRegisterAttributeString($Name, $DefaultValue);
        return true;
    }

    protected function ReadAttributeBoolean(string $Name): bool
    {
        return (bool)$this->coreReadAttributeBoolean($Name);
    }

    protected function ReadAttributeInteger(string $Name): int
    {
        return (int)$this->coreReadAttributeInteger($Name);
    }

    protected function ReadAttributeFloat(string $Name): float
    {
        return (float)$this->coreReadAttributeFloat($Name);
    }

    protected function ReadAttributeString(string $Name): string
    {
        return (string)$this->coreReadAttributeString($Name);
    }

    protected function WriteAttributeBoolean(string $Name, bool $Value): bool
    {
        $this->coreWriteAttributeBoolean($Name, $Value);
        return true;
    }

    protected function WriteAttributeInteger(string $Name, int $Value): bool
    {
        $this->coreWriteAttributeInteger($Name, $Value);
        return true;
    }

    protected function WriteAttributeFloat(string $Name, float $Value): bool
    {
        $this->coreWriteAttributeFloat($Name, $Value);
        return true;
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        $this->coreWriteAttributeString($Name, $Value);
        return true;
    }

    protected function RegisterTimer(string $Ident, int $Milliseconds, string $ScriptText): bool
    {
        return (bool)$this->coreRegisterTimer($Ident, $Milliseconds, $ScriptText);
    }

    protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
    {
        return (bool)$this->coreSetTimerInterval($Ident, $Milliseconds);
    }

    protected function GetTimerInterval(string $Ident): int
    {
        return (int)$this->coreGetTimerInterval($Ident);
    }

    protected function RegisterVariableBoolean(string $Ident, string $Name, array|string $ProfileOrPresentation = '', int $Position = 0): bool
    {
        return (bool)$this->coreRegisterVariableBoolean($Ident, $Name, $ProfileOrPresentation, $Position);
    }

    protected function RegisterVariableInteger(string $Ident, string $Name, array|string $ProfileOrPresentation = '', int $Position = 0): bool
    {
        return (bool)$this->coreRegisterVariableInteger($Ident, $Name, $ProfileOrPresentation, $Position);
    }

    protected function RegisterVariableFloat(string $Ident, string $Name, array|string $ProfileOrPresentation = '', int $Position = 0): bool
    {
        return (bool)$this->coreRegisterVariableFloat($Ident, $Name, $ProfileOrPresentation, $Position);
    }

    protected function RegisterVariableString(string $Ident, string $Name, array|string $ProfileOrPresentation = '', int $Position = 0): bool
    {
        return (bool)$this->coreRegisterVariableString($Ident, $Name, $ProfileOrPresentation, $Position);
    }

    protected function MaintainVariable(string $Ident, string $Name, int $Type, array|string $ProfileOrPresentation, int $Position, bool $Keep): bool
    {
        return (bool)$this->coreMaintainVariable($Ident, $Name, $Type, $ProfileOrPresentation, $Position, $Keep);
    }

    protected function UnregisterVariable(string $Ident): bool
    {
        $this->coreUnregisterVariable($Ident);
        return true;
    }

    protected function EnableAction(string $Ident): bool
    {
        return (bool)$this->coreEnableAction($Ident);
    }

    protected function DisableAction(string $Ident): bool
    {
        return (bool)$this->coreDisableAction($Ident);
    }

    protected function MaintainAction(string $Ident, bool $Keep): bool
    {
        return (bool)$this->coreMaintainAction($Ident, $Keep);
    }

    protected function GetIDForIdent(string $Ident): int
    {
        return (int)$this->coreGetIDForIdent($Ident);
    }

    protected function SendDataToParent(string $Data): string
    {
        return (string)$this->coreSendDataToParent($Data);
    }

    protected function SendDataToChildren(string $Data): array
    {
        $this->coreSendDataToChildren($Data);
        return [];
    }

    protected function ConnectParent(string $ModuleID): bool
    {
        $this->coreConnectParent($ModuleID);
        return true;
    }

    protected function RequireParent(string $ModuleID): bool
    {
        $this->coreRequireParent($ModuleID);
        return true;
    }

    protected function HasActiveParent(): bool
    {
        return (bool)$this->coreHasActiveParent();
    }

    protected function RegisterMessage(int $SenderID, int $Message): bool
    {
        return (bool)$this->coreRegisterMessage($SenderID, $Message);
    }

    protected function UnregisterMessage(int $SenderID, int $Message): bool
    {
        return (bool)$this->coreUnregisterMessage($SenderID, $Message);
    }

    protected function GetMessageList(): array
    {
        return (array)$this->coreGetMessageList();
    }

    protected function SetStatus(int $Status): bool
    {
        return (bool)$this->coreSetStatus($Status);
    }

    protected function GetStatus(): int
    {
        return (int)$this->coreGetStatus();
    }

    protected function SetSummary(string $Summary): bool
    {
        $this->coreSetSummary($Summary);
        return true;
    }

    protected function SetBuffer(string $Name, string $Data): bool
    {
        $this->coreSetBuffer($Name, $Data);
        return true;
    }

    protected function GetBuffer(string $Name): string
    {
        return (string)$this->coreGetBuffer($Name);
    }

    protected function SendDebug(string $Message, string $Data, int $Format): bool
    {
        return (bool)$this->coreSendDebug($Message, $Data, $Format);
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        return (bool)$this->coreLogMessage($Message, $Type);
    }

    protected function RegisterReference(int $ID): bool
    {
        return (bool)$this->coreRegisterReference($ID);
    }

    protected function UnregisterReference(int $ID): bool
    {
        return (bool)$this->coreUnregisterReference($ID);
    }

    protected function SetReceiveDataFilter(string $RequiredRegexMatch): bool
    {
        $this->coreSetReceiveDataFilter($RequiredRegexMatch);
        return true;
    }

    protected function SetForwardDataFilter(string $RequiredRegexMatch): bool
    {
        $this->coreSetForwardDataFilter($RequiredRegexMatch);
        return true;
    }
}
