<?php

declare(strict_types=1);

// Single time source of the library. In Symcon $now stays null and now() is time();
// the test bench (tests/) pins it to move subscriptions, timers and cooldowns forward.
if (!class_exists('ThinQClock')) {
    final class ThinQClock
    {
        public static ?int $now = null;

        public static function now(): int
        {
            return self::$now ?? time();
        }
    }
}
