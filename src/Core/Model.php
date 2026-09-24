<?php

declare(strict_types=1);

namespace Oblodai\Core;

use JsonSerializable;

/**
 * Base of every generated model (`Oblodai\Generated\Model\*`): readonly properties named as on the
 * wire, `fromArray()` / `toArray()`, and the fields newer than this SDK kept in `extra`.
 *
 * One-time secrets (a webhook secret, a payout link's claim token, claim URL or passcode) stay
 * readable as properties, but every wholesale rendering the class can intercept — `var_dump`,
 * `json_encode`, `serialize` — shows `[redacted]` instead. `toArray()` is the deliberate escape hatch.
 * PHP caveat: `print_r()` and `var_export()` read public properties directly and cannot be
 * intercepted; do not `print_r` a model that carries a secret.
 */
abstract class Model implements JsonSerializable
{
    /** Wire keys whose value is shown once and must never reach a log. */
    public const SECRET_KEYS = ['secret', 'passcode', 'claim_token', 'claim_url'];

    public const REDACTED = '[redacted]';

    /**
     * The model as its wire array (secrets included), newer fields too.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * The wire array with secrets masked.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        /** @var array<string, mixed> $masked */
        $masked = self::mask($this->toArray());

        return $masked;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        /** @var array<string, mixed> $masked */
        $masked = self::mask(get_object_vars($this));

        return $masked;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        /** @var array<string, mixed> $masked */
        $masked = self::mask(get_object_vars($this));

        return $masked;
    }

    /**
     * A round-tripped model keeps everything but its secrets, which `__serialize()` replaced.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $reflection = new \ReflectionObject($this);
        foreach ($data as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $reflection->getProperty($name)->setValue($this, $value);
            }
        }
    }

    /** A short, secret-free description: the class and its identifying field, when it has one. */
    public function __toString(): string
    {
        $short = substr(strrchr(static::class, '\\') ?: static::class, 1) ?: static::class;
        foreach (['uuid', 'id', 'order_id'] as $key) {
            $value = get_object_vars($this)[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                return sprintf('%s(%s=%s)', $short, $key, (string) $value);
            }
        }

        return $short . '()';
    }

    private static function mask(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->jsonSerialize();
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_string($key) && in_array($key, self::SECRET_KEYS, true) && $item !== null
                ? self::REDACTED
                : self::mask($item);
        }

        return $out;
    }
}
