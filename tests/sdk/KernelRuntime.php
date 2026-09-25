<?php

declare(strict_types=1);

/*
 * Runtime side of the test kernel: timers and the clock, kernel messages, kernel start and
 * module reload, translation. Split from Kernel.php to keep both files short.
 */
trait KernelRuntime
{
    private static array $locales = [];

    // ------------------------------------------------------------------ timers, clock, kernel messages

    /** Moves the clock and fires every due timer in order (a timer restarts when its interval is set). */
    public static function advance(int $seconds): void
    {
        $target = self::now() + $seconds;
        while (true) {
            $next = null;
            foreach (self::$instances as $id => $inst) {
                foreach ($inst['timers'] as $name => $t) {
                    if ($t['interval'] > 0) {
                        $due = $t['armedAt'] + (int)ceil($t['interval'] / 1000);
                        if ($due <= $target && ($next === null || $due < $next[2])) {
                            $next = [$id, $name, $due];
                        }
                    }
                }
            }
            if ($next === null) {
                break;
            }
            [$id, $name, $due] = $next;
            ThinQClock::$now = max(self::now(), $due);
            self::$instances[$id]['timers'][$name]['armedAt'] = $due;
            self::runScript($id, (string)self::$instances[$id]['timers'][$name]['script'], 'TimerEvent');
        }
        ThinQClock::$now = $target;
    }

    public static function runScript(int $target, string $script, string $sender): void
    {
        $_IPS = ['TARGET' => $target, 'SENDER' => $sender, 'SELF' => $target];
        try {
            // Test-only: runs the script text a module registered with RegisterTimer (our own code,
            // e.g. "LGTQ_RenewEvents($_IPS['TARGET']);"), exactly as Symcon evaluates timer scripts.
            eval(str_replace(['<?php', '?>'], '', $script));
        } catch (\Throwable $e) {
            self::logFatal($target, $e);
        }
    }

    public static function sendMessage(int $senderId, int $message, array $data = []): void
    {
        foreach (self::$instances as $id => $inst) {
            if (in_array($message, $inst['messages'][$senderId] ?? [], true)) {
                self::$messageTrace[] = [self::now(), $senderId, $message, $id];
                self::runEntry($id, static fn(IPSModule $o) => $o->MessageSink(self::now(), $senderId, $message, $data));
            }
        }
    }

    /** Kernel restart: new objects, Create(), ApplyChanges() before KR_READY, then IPS_KERNELSTARTED. */
    public static function restart(): void
    {
        self::$runlevel = KR_INIT;
        foreach (self::$instances as $id => $inst) {
            self::$instances[$id]['buffers'] = [];
            self::$instances[$id]['messages'] = [];
            if (self::moduleOf($id)['class'] ?? null) {
                self::instantiate($id);
            }
        }
        foreach (array_keys(self::$instances) as $id) {
            self::runEntry($id, static fn(IPSModule $o) => $o->ApplyChanges());
        }
        self::$runlevel = KR_READY;
        self::sendMessage(0, IPS_KERNELSTARTED);
    }

    /** MC_ReloadModule: new objects with Create() and ApplyChanges(), kernel stays ready. */
    public static function reloadLibrary(): void
    {
        foreach (array_keys(self::$instances) as $id) {
            if (self::moduleOf($id)['class'] ?? null) {
                self::instantiate($id);
                self::runEntry($id, static fn(IPSModule $o) => $o->ApplyChanges());
            }
        }
    }

    // ------------------------------------------------------------------ translation

    public static function translate(?string $moduleDir, string $text): string
    {
        if ($moduleDir === null) {
            return $text;
        }
        if (!isset(self::$locales[$moduleDir])) {
            $locale = is_file($moduleDir . '/locale.json') ? json_decode((string)file_get_contents($moduleDir . '/locale.json'), true) : [];
            self::$locales[$moduleDir] = is_array($locale) ? $locale : [];
        }
        $t = self::$locales[$moduleDir]['translations'][self::$language][$text] ?? null;
        return is_string($t) ? $t : $text;
    }
}
