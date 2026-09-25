<?php

declare(strict_types=1);

/** A JSON list or map kept in one string attribute of the module (device list, subscriptions). */
final class ThinQJsonAttribute
{
    public function __construct(private ThinQModuleContext $ctx, private string $name)
    {
    }

    /** @return array<mixed> */
    public function all(): array
    {
        $data = json_decode($this->ctx->attributeString($this->name), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<mixed> $data */
    public function replace(array $data): void
    {
        $this->ctx->writeAttributeString($this->name, (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $data = $this->all();
        $data[$key] = $value;
        $this->replace($data);
    }

    /** @param array<string, mixed> $fields merged into the entry at $key */
    public function merge(string $key, array $fields): void
    {
        $data = $this->all();
        $data[$key] = $fields + (is_array($data[$key] ?? null) ? $data[$key] : []);
        $this->replace($data);
    }

    public function remove(string $key): void
    {
        $data = $this->all();
        if (array_key_exists($key, $data)) {
            unset($data[$key]);
            $this->replace($data);
        }
    }
}
