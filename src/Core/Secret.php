<?php

declare(strict_types=1);

namespace Oblodai\Core;

use JsonSerializable;
use Stringable;
use WeakMap;

/**
 * A secret the SDK holds but never prints.
 *
 * PHP has no way to hide a property from `print_r()` or `var_export()`, so the value is not stored
 * on the object at all: it lives in a process-wide {@see WeakMap} keyed by this instance and dies
 * with it. Every way of showing an object — `var_dump`, `print_r`, `var_export`, `json_encode`,
 * `serialize`, string interpolation, a caller-injected logger that dumps its context — sees an
 * object with no state, or the literal `[redacted]`. Only `reveal()` returns the bytes, and only
 * the signer calls it.
 */
final class Secret implements JsonSerializable, Stringable
{
    public const REDACTED = '[redacted]';

    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    public function __construct(string $value)
    {
        self::values()[$this] = $value;
    }

    /** The secret bytes. Call this only where they are actually needed — at the HMAC. */
    public function reveal(): string
    {
        return self::values()[$this] ?? '';
    }

    public function isEmpty(): bool
    {
        return $this->reveal() === '';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => self::redacted()];
    }

    /** @return array<string, string> */
    public function __serialize(): array
    {
        return ['value' => self::redacted()];
    }

    /**
     * A serialized secret carries `[redacted]`, so a restored one signs nothing. That is the point:
     * secrets belong in a key store, not in a session or a cache entry.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        self::values()[$this] = is_string($data['value'] ?? null) ? $data['value'] : self::redacted();
    }

    public function __toString(): string
    {
        return self::redacted();
    }

    public function jsonSerialize(): string
    {
        return self::redacted();
    }

    /** @return WeakMap<self, string> */
    private static function values(): WeakMap
    {
        /** @var WeakMap<self, string> */
        return self::$values ??= new WeakMap();
    }
}
