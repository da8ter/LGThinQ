<?php

declare(strict_types=1);

/**
 * What the helper classes may do with their module: debug, translate, read properties and
 * attributes, write attributes and buffers, log, maintain variables, enable actions, set timers.
 * The module builds it from closures (ThinQModuleTrait::moduleContext()), so the helpers reach
 * the protected SDK methods without any public module method; Symcon would export each of those
 * as an LGTQ_/LGTQD_ script function.
 */
final class ThinQModuleContext
{
    public function __construct(
        public readonly int $instanceId,
        private Closure $debug,
        private Closure $translate,
        private Closure $property,
        private Closure $attribute,
        private Closure $writeAttribute,
        private Closure $buffer,
        private Closure $setBuffer,
        private Closure $log,
        private Closure $maintainVariable,
        private Closure $enableAction,
        private Closure $disableAction,
        private Closure $setTimerInterval,
        private Closure $hasActiveParent
    ) {
    }

    public function debug(string $tag, string $message): void
    {
        ($this->debug)($tag, $message);
    }

    public function debugEnabled(): bool
    {
        return (bool)($this->property)('Debug', 'bool');
    }

    public function t(string $text): string
    {
        return (string)($this->translate)($text);
    }

    public function propertyString(string $name): string
    {
        return (string)($this->property)($name, 'string');
    }

    public function propertyInteger(string $name): int
    {
        return (int)($this->property)($name, 'int');
    }

    public function propertyBoolean(string $name): bool
    {
        return (bool)($this->property)($name, 'bool');
    }

    public function attributeString(string $name): string
    {
        return (string)($this->attribute)($name, 'string');
    }

    public function attributeInteger(string $name): int
    {
        return (int)($this->attribute)($name, 'int');
    }

    public function writeAttributeString(string $name, string $value): void
    {
        ($this->writeAttribute)($name, $value);
    }

    public function writeAttributeInteger(string $name, int $value): void
    {
        ($this->writeAttribute)($name, $value);
    }

    public function buffer(string $name): string
    {
        return (string)($this->buffer)($name);
    }

    public function setBuffer(string $name, string $value): void
    {
        ($this->setBuffer)($name, $value);
    }

    public function log(string $message, int $type): void
    {
        ($this->log)($message, $type);
    }

    /** @param string|array<string, mixed> $presentation */
    public function maintainVariable(string $ident, string $name, int $type, string|array $presentation, int $position, bool $keep): bool
    {
        return (bool)($this->maintainVariable)($ident, $name, $type, $presentation, $position, $keep);
    }

    public function enableAction(string $ident): void
    {
        ($this->enableAction)($ident);
    }

    public function disableAction(string $ident): void
    {
        ($this->disableAction)($ident);
    }

    public function setTimerInterval(string $ident, int $milliseconds): void
    {
        ($this->setTimerInterval)($ident, $milliseconds);
    }

    public function hasActiveParent(): bool
    {
        return (bool)($this->hasActiveParent)();
    }
}
