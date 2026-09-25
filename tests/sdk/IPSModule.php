<?php

declare(strict_types=1);

/*
 * IPSModule as the LG ThinQ modules see it (they extend IPSModule, not IPSModuleStrict,
 * hence no parameter types on the overridable methods). State lives in Kernel, so a new
 * PHP object after a reload or kernel restart finds properties and attributes again.
 * Rules taken from SymconStubs (Symcon GmbH) and the 9.1 measurements: MaintainVariable
 * sets name and position only on creation, re-creates on a type change and updates the
 * (module) presentation on every call; missing timers and attributes warn.
 */
class IPSModule
{
    protected $InstanceID;

    public function __construct($InstanceID)
    {
        $this->InstanceID = (int)$InstanceID;
    }

    public function Create() {}

    public function Destroy() {}

    public function ApplyChanges() {}

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {}

    public function ReceiveData($JSONString)
    {
        return '';
    }

    public function ForwardData($JSONString)
    {
        return '';
    }

    public function RequestAction($Ident, $Value)
    {
        Kernel::warn('No valid action available');
    }

    public function GetConfigurationForm()
    {
        $file = $this->moduleDir() . '/form.json';
        return is_file($file) ? (string)file_get_contents($file) : '{}';
    }

    public function GetConfigurationForParent()
    {
        return '{}';
    }

    public function Translate($Text)
    {
        return Kernel::translate($this->moduleDir(), (string)$Text);
    }

    public function GetReferenceList()
    {
        return array_values(Kernel::$instances[$this->InstanceID]['references']);
    }

    // ------------------------------------------------------------------ properties

    protected function RegisterPropertyBoolean($Name, $DefaultValue) { $this->registerProperty((string)$Name, (bool)$DefaultValue, 'boolean'); }
    protected function RegisterPropertyInteger($Name, $DefaultValue) { $this->registerProperty((string)$Name, (int)$DefaultValue, 'integer'); }
    protected function RegisterPropertyFloat($Name, $DefaultValue) { $this->registerProperty((string)$Name, (float)$DefaultValue, 'double'); }
    protected function RegisterPropertyString($Name, $DefaultValue) { $this->registerProperty((string)$Name, (string)$DefaultValue, 'string'); }

    protected function ReadPropertyBoolean($Name) { return $this->readProperty((string)$Name, 'boolean'); }
    protected function ReadPropertyInteger($Name) { return $this->readProperty((string)$Name, 'integer'); }
    protected function ReadPropertyFloat($Name) { return $this->readProperty((string)$Name, 'double'); }
    protected function ReadPropertyString($Name) { return $this->readProperty((string)$Name, 'string'); }

    private function registerProperty(string $name, mixed $default, string $type): void
    {
        $inst = &Kernel::$instances[$this->InstanceID];
        $inst['propertyTypes'][$name] = $type;
        if (!array_key_exists($name, $inst['properties'])) {
            $inst['properties'][$name] = $default;
        }
        if (!array_key_exists($name, $inst['pending'])) {
            $inst['pending'][$name] = $default;
        }
    }

    private function readProperty(string $name, string $type): mixed
    {
        $props = Kernel::$instances[$this->InstanceID]['properties'];
        if (!array_key_exists($name, $props)) {
            Kernel::warn(sprintf('Eigenschaft %s nicht gefunden', $name));
            return false;
        }
        $v = $props[$name];
        settype($v, $type);
        return $v;
    }

    // ------------------------------------------------------------------ attributes

    protected function RegisterAttributeBoolean($Name, $DefaultValue) { $this->registerAttribute((string)$Name, (bool)$DefaultValue); }
    protected function RegisterAttributeInteger($Name, $DefaultValue) { $this->registerAttribute((string)$Name, (int)$DefaultValue); }
    protected function RegisterAttributeFloat($Name, $DefaultValue) { $this->registerAttribute((string)$Name, (float)$DefaultValue); }
    protected function RegisterAttributeString($Name, $DefaultValue) { $this->registerAttribute((string)$Name, (string)$DefaultValue); }

    protected function ReadAttributeBoolean($Name) { return $this->readAttribute((string)$Name, 'boolean'); }
    protected function ReadAttributeInteger($Name) { return $this->readAttribute((string)$Name, 'integer'); }
    protected function ReadAttributeFloat($Name) { return $this->readAttribute((string)$Name, 'double'); }
    protected function ReadAttributeString($Name) { return $this->readAttribute((string)$Name, 'string'); }

    protected function WriteAttributeBoolean($Name, $Value) { $this->writeAttribute((string)$Name, (bool)$Value); }
    protected function WriteAttributeInteger($Name, $Value) { $this->writeAttribute((string)$Name, (int)$Value); }
    protected function WriteAttributeFloat($Name, $Value) { $this->writeAttribute((string)$Name, (float)$Value); }
    protected function WriteAttributeString($Name, $Value) { $this->writeAttribute((string)$Name, (string)$Value); }

    private function registerAttribute(string $name, mixed $default): void
    {
        if (!array_key_exists($name, Kernel::$instances[$this->InstanceID]['attributes'])) {
            Kernel::$instances[$this->InstanceID]['attributes'][$name] = $default;
        }
    }

    private function readAttribute(string $name, string $type): mixed
    {
        $attrs = Kernel::$instances[$this->InstanceID]['attributes'];
        if (!array_key_exists($name, $attrs)) {
            Kernel::warn(sprintf('Attribut %s nicht gefunden', $name));
            return false;
        }
        $v = $attrs[$name];
        settype($v, $type);
        return $v;
    }

    private function writeAttribute(string $name, mixed $value): void
    {
        if (!array_key_exists($name, Kernel::$instances[$this->InstanceID]['attributes'])) {
            Kernel::warn(sprintf('Attribut %s nicht gefunden', $name));
            return;
        }
        Kernel::$instances[$this->InstanceID]['attributes'][$name] = $value;
    }

    // ------------------------------------------------------------------ timers (9.1: SetTimerInterval restarts the countdown)

    protected function RegisterTimer($Ident, $Milliseconds, $ScriptText)
    {
        Kernel::$instances[$this->InstanceID]['timers'][(string)$Ident] = ['interval' => (int)$Milliseconds,
            'script' => (string)$ScriptText, 'armedAt' => Kernel::now()];
        return true;
    }

    protected function SetTimerInterval($Ident, $Milliseconds)
    {
        $timers = &Kernel::$instances[$this->InstanceID]['timers'];
        if (!isset($timers[(string)$Ident])) {
            Kernel::warn(sprintf('Timer %s does not exist', $Ident));
            return false;
        }
        $timers[(string)$Ident]['interval'] = (int)$Milliseconds;
        $timers[(string)$Ident]['armedAt'] = Kernel::now();
        return true;
    }

    protected function GetTimerInterval($Ident)
    {
        $t = Kernel::$instances[$this->InstanceID]['timers'][(string)$Ident] ?? null;
        if ($t === null) {
            Kernel::warn(sprintf('Timer %s does not exist', $Ident));
            return false;
        }
        return $t['interval'];
    }

    // ------------------------------------------------------------------ variables

    protected function RegisterVariableBoolean($Ident, $Name, $ProfileOrPresentation = '', $Position = 0) { return $this->registerVariable((string)$Ident, (string)$Name, VARIABLETYPE_BOOLEAN, $ProfileOrPresentation, (int)$Position); }
    protected function RegisterVariableInteger($Ident, $Name, $ProfileOrPresentation = '', $Position = 0) { return $this->registerVariable((string)$Ident, (string)$Name, VARIABLETYPE_INTEGER, $ProfileOrPresentation, (int)$Position); }
    protected function RegisterVariableFloat($Ident, $Name, $ProfileOrPresentation = '', $Position = 0) { return $this->registerVariable((string)$Ident, (string)$Name, VARIABLETYPE_FLOAT, $ProfileOrPresentation, (int)$Position); }
    protected function RegisterVariableString($Ident, $Name, $ProfileOrPresentation = '', $Position = 0) { return $this->registerVariable((string)$Ident, (string)$Name, VARIABLETYPE_STRING, $ProfileOrPresentation, (int)$Position); }

    protected function MaintainVariable($Ident, $Name, $Type, $ProfileOrPresentation, $Position, $Keep)
    {
        if (isset(Kernel::$failMaintain[(string)$Ident])) {
            return false;
        }
        if ($Keep) {
            return $this->registerVariable((string)$Ident, (string)$Name, (int)$Type, $ProfileOrPresentation, (int)$Position) > 0;
        }
        $this->UnregisterVariable($Ident);
        return true;
    }

    protected function UnregisterVariable($Ident)
    {
        $vid = Kernel::findIdent($this->InstanceID, (string)$Ident);
        if ($vid > 0 && isset(Kernel::$variables[$vid])) {
            Kernel::deleteObject($vid);
        }
    }

    private function registerVariable(string $ident, string $name, int $type, mixed $profileOrPresentation, int $position): int
    {
        if (is_string($profileOrPresentation) && $profileOrPresentation !== '') {
            $profile = $profileOrPresentation;
            if (isset(Kernel::$profiles['~' . $profile])) {
                $profile = '~' . $profile;
            }
            if (!isset(Kernel::$profiles[$profile])) {
                Kernel::warn(sprintf('Profil mit dem Namen #%s existiert nicht', $profile));
                return 0;
            }
            $profileOrPresentation = $profile;
        }
        if (is_array($profileOrPresentation) && !Kernel::acceptPresentation($profileOrPresentation)) {
            return 0;
        }
        $vid = Kernel::findIdent($this->InstanceID, $ident);
        if ($vid > 0 && !isset(Kernel::$variables[$vid])) {
            Kernel::warn('Ident with name ' . $ident . ' is used for wrong object type');
            return 0;
        }
        if ($vid > 0 && Kernel::$variables[$vid]['type'] !== $type) {
            Kernel::deleteObject($vid); // type change: Symcon deletes and re-creates (new ID, history gone)
            $vid = 0;
        }
        if ($vid === 0) {
            $vid = Kernel::createVariable($type);
            Kernel::$objects[$vid]['parent'] = $this->InstanceID;
            if (!Kernel::setIdent($vid, $ident)) {
                Kernel::deleteObject($vid);
                return 0;
            }
            Kernel::$objects[$vid]['name'] = $name;
            Kernel::$objects[$vid]['position'] = $position;
        }
        if (is_array($profileOrPresentation)) {
            Kernel::$variables[$vid]['presentation'] = $profileOrPresentation;
        } else {
            Kernel::$variables[$vid]['profile'] = (string)$profileOrPresentation;
        }
        return $vid;
    }

    protected function EnableAction($Ident)
    {
        return $this->setAction((string)$Ident, $this->InstanceID);
    }

    protected function DisableAction($Ident)
    {
        return $this->setAction((string)$Ident, 0);
    }

    protected function MaintainAction($Ident, $Keep)
    {
        return $this->setAction((string)$Ident, $Keep ? $this->InstanceID : 0);
    }

    private function setAction(string $ident, int $action): bool
    {
        $vid = Kernel::findIdent($this->InstanceID, $ident);
        if ($vid === 0 || !isset(Kernel::$variables[$vid])) {
            Kernel::warn(sprintf('Objekt mit Ident %s wurde nicht gefunden', $ident));
            return false;
        }
        Kernel::$variables[$vid]['action'] = $action;
        return true;
    }

    protected function GetIDForIdent($Ident)
    {
        return IPS_GetObjectIDByIdent((string)$Ident, $this->InstanceID);
    }

    // ------------------------------------------------------------------ data flow

    protected function SendDataToParent($Data)
    {
        $parent = (int)Kernel::$instances[$this->InstanceID]['connection'];
        if ($parent === 0 || !isset(Kernel::$instances[$parent])) {
            Kernel::warn('Instanz hat keine übergeordnete Instanz (Prüfstand)');
            return false;
        }
        $dataId = (string)(json_decode((string)$Data, true)['DataID'] ?? '');
        if (!Kernel::implementsData($parent, $dataId)) {
            Kernel::warn(sprintf('Übergeordnete Instanz unterstützt %s nicht (Prüfstand)', $dataId));
            return false;
        }
        $target = Kernel::$instances[$parent]['object'] ?? Kernel::$instances[$parent]['handler'];
        try {
            return is_object($target) ? $target->ForwardData((string)$Data) : '';
        } catch (\Throwable $e) {
            Kernel::logFatal($parent, $e);
            Kernel::warn($e->getMessage());
            return false;
        }
    }

    protected function SendDataToChildren($Data)
    {
        Kernel::sendToChildren($this->InstanceID, (string)$Data);
    }

    protected function ConnectParent($ModuleID)
    {
        if ((int)Kernel::$instances[$this->InstanceID]['connection'] === 0) {
            $ids = IPS_GetInstanceListByModuleID((string)$ModuleID);
            if (count($ids) > 0) {
                IPS_ConnectInstance($this->InstanceID, $ids[0]);
                return;
            }
            $this->RequireParent($ModuleID);
        }
    }

    protected function RequireParent($ModuleID)
    {
        if ((int)Kernel::$instances[$this->InstanceID]['connection'] === 0) {
            $id = IPS_CreateInstance((string)$ModuleID);
            IPS_ConnectInstance($this->InstanceID, $id);
        }
    }

    protected function HasActiveParent()
    {
        $parent = (int)Kernel::$instances[$this->InstanceID]['connection'];
        return $parent > 0 && isset(Kernel::$instances[$parent]) && Kernel::$instances[$parent]['status'] === IS_ACTIVE;
    }

    // ------------------------------------------------------------------ messages, status, buffers, log, references

    protected function RegisterMessage($SenderID, $Message)
    {
        $list = &Kernel::$instances[$this->InstanceID]['messages'][(int)$SenderID];
        $list ??= [];
        if (!in_array((int)$Message, $list, true)) {
            $list[] = (int)$Message;
        }
        return true;
    }

    protected function UnregisterMessage($SenderID, $Message)
    {
        $list = Kernel::$instances[$this->InstanceID]['messages'][(int)$SenderID] ?? [];
        Kernel::$instances[$this->InstanceID]['messages'][(int)$SenderID] = array_values(array_diff($list, [(int)$Message]));
        return true;
    }

    protected function GetMessageList()
    {
        return Kernel::$instances[$this->InstanceID]['messages'];
    }

    protected function SetStatus($Status)
    {
        $old = (int)Kernel::$instances[$this->InstanceID]['status'];
        Kernel::$instances[$this->InstanceID]['status'] = (int)$Status;
        if ($old !== (int)$Status) {
            Kernel::sendMessage($this->InstanceID, IM_CHANGESTATUS, [(int)$Status, $old]); // like Symcon: only on a change
        }
        return true;
    }

    protected function GetStatus()
    {
        return (int)Kernel::$instances[$this->InstanceID]['status'];
    }

    protected function SetSummary($Summary) {}

    protected function SetBuffer($Name, $Data)
    {
        Kernel::$instances[$this->InstanceID]['buffers'][(string)$Name] = (string)$Data;
    }

    protected function GetBuffer($Name)
    {
        return Kernel::$instances[$this->InstanceID]['buffers'][(string)$Name] ?? '';
    }

    protected function SendDebug($Message, $Data, $Format)
    {
        Kernel::$debug[$this->InstanceID][] = [(string)$Message, (string)$Data];
        return true;
    }

    protected function LogMessage($Message, $Type)
    {
        Kernel::log($this->InstanceID, Kernel::logType((int)$Type), Kernel::nameOf($this->InstanceID), (string)$Message);
        return true;
    }

    protected function RegisterReference($ID)
    {
        Kernel::$instances[$this->InstanceID]['references'][(int)$ID] = (int)$ID;
        return true;
    }

    protected function UnregisterReference($ID)
    {
        unset(Kernel::$instances[$this->InstanceID]['references'][(int)$ID]);
        return true;
    }

    protected function SetReceiveDataFilter($RequiredRegexMatch) {}

    protected function SetForwardDataFilter($RequiredRegexMatch) {}

    private function moduleDir(): ?string
    {
        return Kernel::moduleOf($this->InstanceID)['dir'] ?? null;
    }
}
