<?php

declare(strict_types=1);

/*
 * Symcon kernel in memory for the LG ThinQ test bench: objects, instances, variables,
 * profiles, timers, messages and the message log. Measured in Symcon 9.1 (Docker, PHP 8.5.8)
 * on 25.09.2026 with temporary scripts — nothing below throws, everything warns:
 *   SetValueInteger on a float variable   false + "Variablentyp stimmt nicht überein" (value kept)
 *   SetValue with another PHP type        converts; floats round (23.5 -> 24), 'false' -> false
 *   SetValue with an array                false + "Cannot auto-convert value for parameter VariableValue ..."
 *   IPS_GetObjectIDByIdent, missing       false + "Objekt mit Ident X wurde nicht gefunden"
 *   IPS_SetIdent                          letters, digits, underscore; duplicates per level refused
 *   IPS_GetObject/Instance/Variable       false + "Objekt/Instanz/Variable #X existiert nicht"
 *   IPS_SetProperty, unknown name         false + "Eigenschaft X nicht gefunden"
 *   custom presentation                   scalars only, OPTIONS as JSON string; arrays refused
 *   IPS_GetInstance                       the module GUID is under ModuleInfo, not at the top
 *   MQTT Client / Client Socket           property names as in World::registerSymconModules()
 * From the log: unregistered timer "Timer X does not exist"; memory: missing attribute
 * "Attribut X nicht gefunden". VariableUpdated moves on every write, VariableChanged only on change.
 */
final class Kernel
{
    use KernelRuntime;

    /** id => [type, parent, ident, name, position, info] */
    public static array $objects = [];
    /** id => [type, value, profile, customProfile, presentation, customPresentation, action, customAction, updated, changed] */
    public static array $variables = [];
    /** id => [module, connection, status, properties, pending, attributes, timers, messages, buffers, references, object, handler] */
    public static array $instances = [];
    /** guid => module definition (IPS_GetModule shape plus class/dir/defaults) */
    public static array $modules = [];
    /** name => [ProfileType, Prefix, Suffix, MinValue, MaxValue, StepSize, Digits, Icon, Associations] */
    public static array $profiles = [];
    /** Message log like logfile*.log: [time, id, type, sender, text] */
    public static array $log = [];
    /** SendDebug output per instance: id => [[sender, text], ...] */
    public static array $debug = [];
    /** Warnings not silenced with @ (kernel and PHP), as Symcon writes them to the log */
    public static array $warnings = [];
    public static int $runlevel = KR_READY;
    public static string $language = 'de';
    /** Idents whose MaintainVariable Symcon answers with false (to replay that failure) */
    public static array $failMaintain = [];
    /** Kernel messages delivered to MessageSink: [time, sender, message, data] */
    public static array $messageTrace = [];

    public const DEFAULT_TIME = 1790330400; // 2026-09-25 12:00:00 Europe/Berlin
    private static int $nextId = 10000;
    private static ?array $warnOrigin = null;

    public static function reset(): void
    {
        self::$objects = self::$variables = self::$instances = self::$modules = [];
        self::$log = self::$debug = self::$warnings = self::$failMaintain = self::$messageTrace = [];
        self::$runlevel = KR_READY;
        self::$language = 'de';
        self::$nextId = 10000;
        self::$profiles = [
            '~UnixTimestamp' => self::profileDef(VARIABLETYPE_INTEGER),
            '~Switch'        => self::profileDef(VARIABLETYPE_BOOLEAN),
            '~Temperature'   => self::profileDef(VARIABLETYPE_FLOAT),
        ];
        ThinQClock::$now = self::DEFAULT_TIME;
    }

    public static function now(): int
    {
        return ThinQClock::now();
    }

    public static function profileDef(int $type): array
    {
        return ['ProfileType' => $type, 'Prefix' => '', 'Suffix' => '', 'MinValue' => 0.0, 'MaxValue' => 0.0,
            'StepSize' => 0.0, 'Digits' => 0, 'Icon' => '', 'Associations' => []];
    }

    // ------------------------------------------------------------------ warnings and log

    /** Symcon warning: E_USER_WARNING at the caller outside tests/sdk; @ silences it as in Symcon. */
    public static function warn(string $message): void
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && strpos($file, '/tests/sdk/') === false) {
                self::$warnOrigin = [$file, (int)($frame['line'] ?? 0)];
                break;
            }
        }
        trigger_error($message, E_USER_WARNING);
        self::$warnOrigin = null;
    }

    /** Called by the error handler for every warning/notice that was not silenced. */
    public static function recordWarning(int $severity, string $message, string $file, int $line): void
    {
        if (self::$warnOrigin !== null) {
            [$file, $line] = self::$warnOrigin;
        }
        $kind = match ($severity) {
            E_USER_WARNING, E_WARNING => 'Warning',
            E_USER_NOTICE, E_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            default => 'Error',
        };
        $text = sprintf('%s: %s in %s on line %d', $kind, $message, $file, $line);
        self::$warnings[] = $text;
        self::log(0, 'WARNING', 'PHP', $text);
    }

    public static function log(int $id, string $type, string $sender, string $text): void
    {
        self::$log[] = ['time' => self::now(), 'id' => $id, 'type' => $type, 'sender' => $sender, 'text' => $text];
    }

    public static function logType(int $kl): string
    {
        return match ($kl) {
            KL_SUCCESS => 'SUCCESS', KL_NOTIFY => 'NOTIFY', KL_WARNING => 'WARNING', KL_ERROR => 'ERROR',
            KL_DEBUG => 'DEBUG', KL_CUSTOM => 'CUSTOM', default => 'MESSAGE',
        };
    }

    /** Uncaught Throwable in an entry point Symcon called: logged like the kernel does. */
    public static function logFatal(int $id, \Throwable $e): void
    {
        self::log($id, 'ERROR', self::nameOf($id), sprintf("Fatal error: Uncaught %s: %s in %s:%d",
            get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    }

    /** All text Symcon keeps: message log plus every debug line (for secret scans). */
    public static function allText(): string
    {
        $parts = array_map(static fn(array $l): string => $l['sender'] . ' | ' . $l['text'], self::$log);
        foreach (self::$debug as $id => $lines) {
            foreach ($lines as [$sender, $text]) {
                $parts[] = '#' . $id . ' ' . $sender . ' | ' . $text;
            }
        }
        return implode("\n", $parts);
    }

    // ------------------------------------------------------------------ objects

    public static function createObject(int $type, string $name = ''): int
    {
        $id = self::$nextId++;
        self::$objects[$id] = ['type' => $type, 'parent' => 0, 'ident' => '',
            'name' => $name !== '' ? $name : sprintf('Unbenanntes Objekt (ID: %d)', $id), 'position' => 0, 'info' => ''];
        return $id;
    }

    public static function objectExists(int $id): bool
    {
        return isset(self::$objects[$id]);
    }

    /** @return array<int, int> */
    public static function children(int $id): array
    {
        $ids = [];
        foreach (self::$objects as $cid => $o) {
            if ($o['parent'] === $id) {
                $ids[] = $cid;
            }
        }
        usort($ids, static fn(int $a, int $b): int => [self::$objects[$a]['position'], $a] <=> [self::$objects[$b]['position'], $b]);
        return $ids;
    }

    public static function findIdent(int $parent, string $ident): int
    {
        if ($ident === '') {
            return 0;
        }
        foreach (self::$objects as $id => $o) {
            if ($o['parent'] === $parent && $o['ident'] === $ident) {
                return $id;
            }
        }
        return 0;
    }

    public static function setIdent(int $id, string $ident): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $ident)) {
            self::warn('Ident darf nur Buchstaben und Zahlen enthalten');
            return false;
        }
        $other = self::findIdent(self::$objects[$id]['parent'], $ident);
        if ($other !== 0 && $other !== $id) {
            self::warn('Ident muss für jede Ebene eindeutig sein');
            return false;
        }
        self::$objects[$id]['ident'] = $ident;
        return true;
    }

    public static function deleteObject(int $id): void
    {
        foreach (self::children($id) as $child) {
            self::deleteObject($child);
        }
        unset(self::$objects[$id], self::$variables[$id], self::$instances[$id], self::$debug[$id]);
    }

    public static function nameOf(int $id): string
    {
        return (string)(self::$objects[$id]['name'] ?? '');
    }

    // ------------------------------------------------------------------ variables

    public static function createVariable(int $type): int
    {
        $id = self::createObject(OBJECTTYPE_VARIABLE);
        $default = match ($type) {
            VARIABLETYPE_BOOLEAN => false, VARIABLETYPE_INTEGER => 0, VARIABLETYPE_FLOAT => 0.0, default => '',
        };
        self::$variables[$id] = ['type' => $type, 'value' => $default, 'profile' => '', 'customProfile' => '',
            'presentation' => [], 'customPresentation' => [], 'action' => 0, 'customAction' => 0,
            'updated' => 0, 'changed' => 0];
        return $id;
    }

    /** Conversion of SetValue* as measured: floats round, 'false' is false, arrays fail. */
    public static function convert(int $type, mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            self::warn('Cannot auto-convert value for parameter VariableValue (Type does not match)');
            return null;
        }
        return match ($type) {
            VARIABLETYPE_BOOLEAN => is_string($value) ? !in_array(strtolower(trim($value)), ['', '0', 'false'], true) : (bool)$value,
            VARIABLETYPE_INTEGER => is_float($value) || (is_string($value) && is_numeric($value)) ? (int)round((float)$value) : (int)$value,
            VARIABLETYPE_FLOAT => (float)$value,
            default => is_bool($value) ? ($value ? '1' : '') : (string)$value,
        };
    }

    /** $expected: variable type a typed setter or getter requires, null for plain SetValue and GetValue. */
    public static function setValue(int $id, mixed $value, ?int $expected): bool
    {
        if (!isset(self::$variables[$id])) {
            self::warn(sprintf('Variable #%d existiert nicht', $id));
            return false;
        }
        $var = &self::$variables[$id];
        if ($expected !== null && $var['type'] !== $expected) {
            self::warn('Variablentyp stimmt nicht überein');
            return false;
        }
        $new = self::convert($var['type'], $value);
        if ($new === null) {
            return false;
        }
        $now = self::now();
        if ($new !== $var['value']) {
            $var['changed'] = $now;
        }
        $var['value'] = $new;
        $var['updated'] = $now;
        return true;
    }

    public static function getValue(int $id, ?int $expected): mixed
    {
        if (!isset(self::$variables[$id])) {
            self::warn(sprintf('Variable #%d existiert nicht', $id));
            return false;
        }
        if ($expected !== null && self::$variables[$id]['type'] !== $expected) {
            self::warn('Variablentyp stimmt nicht überein');
            return false;
        }
        return self::$variables[$id]['value'];
    }

    /** Presentation arrays accept scalars only; OPTIONS/INTERVALS travel as JSON strings (measured). */
    public static function acceptPresentation(array $presentation): bool
    {
        foreach ($presentation as $v) {
            if (is_array($v) || is_object($v)) {
                self::warn('Cannot auto-convert value for parameter Presentation (Type is not supported)');
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------ modules and instances

    public static function registerModule(array $def): void
    {
        self::$modules[$def['ModuleID']] = $def + ['Vendor' => '', 'Aliases' => [], 'Prefix' => '', 'Implemented' => [],
            'ParentRequirements' => [], 'ChildRequirements' => [], 'LibraryID' => '', 'class' => null, 'dir' => null, 'defaults' => []];
    }

    /** Loads every module folder of a library: module.json, module.php, PREFIX_ functions. */
    public static function loadLibrary(string $libraryDir): void
    {
        $library = json_decode((string)file_get_contents($libraryDir . '/library.json'), true);
        foreach (glob($libraryDir . '/*', GLOB_ONLYDIR) as $dir) {
            if (!is_file($dir . '/module.json') || !is_file($dir . '/module.php')) {
                continue;
            }
            $m = json_decode((string)file_get_contents($dir . '/module.json'), true);
            require_once $dir . '/module.php';
            $class = str_replace(' ', '', (string)$m['name']);
            self::registerModule(['ModuleID' => $m['id'], 'ModuleName' => $m['name'], 'ModuleType' => (int)$m['type'],
                'Vendor' => (string)($m['vendor'] ?? ''), 'Aliases' => $m['aliases'] ?? [], 'Prefix' => (string)$m['prefix'],
                'Implemented' => $m['implemented'] ?? [], 'ParentRequirements' => $m['parentRequirements'] ?? [],
                'ChildRequirements' => $m['childRequirements'] ?? [], 'LibraryID' => (string)($library['id'] ?? ''),
                'class' => $class, 'dir' => $dir]);
            self::exportFunctions($class, (string)$m['prefix']);
        }
    }

    /** Public methods become PREFIX_Method(int $InstanceID, ...) like in Symcon (SymconStubs rule). */
    public static function exportFunctions(string $class, string $prefix): void
    {
        $skip = ['__construct', 'Create', 'Destroy', 'ApplyChanges', 'ReceiveData', 'ForwardData', 'RequestAction',
            'MessageSink', 'GetConfigurationForm', 'GetConfigurationForParent', 'Translate', 'GetCompatibleParents'];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === 'IPSModule' || in_array($method->getName(), $skip, true) || $method->isStatic()) {
                continue;
            }
            $params = ['int $InstanceID'];
            $args = [];
            foreach ($method->getParameters() as $p) {
                $params[] = ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName() . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
                $args[] = '$' . $p->getName();
            }
            $fn = $prefix . '_' . $method->getName();
            if (!function_exists($fn)) {
                // Test-only, as in SymconStubs: the code is built from reflected method names of our own
                // module classes, no outside input. PHP has no other way to declare a function by name.
                eval(sprintf('function %s(%s) { return Kernel::callPublic($InstanceID, %s, [%s]); }',
                    $fn, implode(', ', $params), var_export($method->getName(), true), implode(', ', $args)));
            }
        }
    }

    /** @return array<int, string> exported PREFIX_ functions of the loaded LG modules */
    public static function exportedFunctions(): array
    {
        $out = [];
        foreach (get_defined_functions()['user'] as $fn) {
            if (preg_match('/^lgtq(d|c)?_/i', $fn)) {
                $out[] = $fn;
            }
        }
        sort($out);
        return $out;
    }

    public static function callPublic(int $id, string $method, array $args): mixed
    {
        $obj = self::$instances[$id]['object'] ?? null;
        if (!$obj instanceof IPSModule) {
            self::warn(sprintf('Instanz #%d existiert nicht', $id));
            return false;
        }
        return $obj->$method(...$args);
    }

    public static function createInstance(string $moduleId): int
    {
        $module = self::$modules[$moduleId] ?? null;
        if ($module === null) {
            self::warn(sprintf('Modul mit der GUID %s wurde nicht gefunden', $moduleId));
            return 0;
        }
        $id = self::createObject(OBJECTTYPE_INSTANCE, (string)$module['ModuleName']);
        self::$instances[$id] = ['module' => $moduleId, 'connection' => 0, 'status' => IS_CREATING,
            'properties' => $module['defaults'], 'pending' => $module['defaults'], 'propertyTypes' => [],
            'attributes' => [], 'timers' => [], 'messages' => [], 'buffers' => [], 'references' => [],
            'object' => null, 'handler' => null, 'changed' => self::now()];
        self::log($id, 'MESSAGE', (string)$module['ModuleName'], 'Erstelle...');
        if ($module['class'] !== null) {
            self::instantiate($id);
            self::runEntry($id, static fn(IPSModule $o) => $o->ApplyChanges());
        }
        if (self::$instances[$id]['status'] === IS_CREATING) {
            self::$instances[$id]['status'] = IS_ACTIVE;
        }
        return $id;
    }

    /** New PHP object for an instance, then Create() — like instance creation, reload and kernel start. */
    public static function instantiate(int $id): IPSModule
    {
        $class = (string)self::$modules[self::$instances[$id]['module']]['class'];
        $obj = new $class($id);
        self::$instances[$id]['object'] = $obj;
        self::$instances[$id]['timers'] = [];
        $obj->Create();
        return $obj;
    }

    /** Entry point called by the kernel: Throwable is logged, never propagated (as in Symcon). */
    public static function runEntry(int $id, callable $fn): mixed
    {
        $obj = self::$instances[$id]['object'] ?? null;
        if (!$obj instanceof IPSModule) {
            return null;
        }
        try {
            return $fn($obj);
        } catch (\Throwable $e) {
            self::logFatal($id, $e);
            return null;
        }
    }

    public static function applyChanges(int $id): bool
    {
        if (!isset(self::$instances[$id])) {
            self::warn(sprintf('Instanz #%d existiert nicht', $id));
            return false;
        }
        self::$instances[$id]['properties'] = self::$instances[$id]['pending'];
        self::$instances[$id]['changed'] = self::now();
        self::runEntry($id, static fn(IPSModule $o) => $o->ApplyChanges());
        return true;
    }

    public static function moduleOf(int $id): array
    {
        return self::$modules[self::$instances[$id]['module'] ?? ''] ?? [];
    }

    public static function implementsData(int $id, string $dataId): bool
    {
        foreach ((array)(self::moduleOf($id)['Implemented'] ?? []) as $guid) {
            if (strcasecmp((string)$guid, $dataId) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Delivers to every child connected to $parentId whose module implements the DataID. */
    public static function sendToChildren(int $parentId, string $json): void
    {
        $data = json_decode($json, true);
        $dataId = (string)($data['DataID'] ?? '');
        foreach (array_keys(self::$instances) as $cid) {
            if (self::$instances[$cid]['connection'] !== $parentId || !self::implementsData($cid, $dataId)) {
                continue;
            }
            if (self::$instances[$cid]['object'] instanceof IPSModule) {
                self::runEntry($cid, static fn(IPSModule $o) => $o->ReceiveData($json));
            } elseif (is_object(self::$instances[$cid]['handler']) && method_exists(self::$instances[$cid]['handler'], 'ReceiveData')) {
                self::$instances[$cid]['handler']->ReceiveData($json);
            }
        }
    }
}
