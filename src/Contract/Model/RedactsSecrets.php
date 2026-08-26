<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use ReflectionObject;

/**
 * Keeps a model's one-time secrets out of every accidental print.
 *
 * A webhook secret, a freshly minted API secret, a payout link's claim token, claim URL or passcode
 * are shown
 * by the gateway exactly once. They also sit on models that end up in a `var_dump` while debugging,
 * in a `json_encode` inside a structured log line, or in a serialized session — and from there in a
 * log aggregator that anybody on the team can read. Reading the property is deliberate and stays
 * possible; everything that dumps an object wholesale gets `[redacted]` instead.
 *
 * The mask covers the field AND the same key inside the model's `raw` wire body. `toArray()` is the
 * deliberate escape hatch and is left alone.
 *
 * PHP caveat: `print_r()` and `var_export()` read properties directly and cannot be intercepted for
 * an object with public properties. Do not `print_r` a model that carries a secret.
 */
trait RedactsSecrets
{
    /**
     * Wire field names whose value is a one-time secret. `claim_url` is one by content: the claim
     * page URL embeds the cheque's `claim_token`, so printing it hands the money away.
     *
     * Static methods rather than constants: PHP 8.1 (the floor in composer.json) does not allow
     * constants in traits.
     *
     * @return list<string>
     */
    private static function secretKeys(): array
    {
        return ['secret', 'passcode', 'claim_token', 'claim_url'];
    }

    /** The placeholder printed instead of a secret. */
    public static function redacted(): string
    {
        return '[redacted]';
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return self::maskSecrets(get_object_vars($this));
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return self::maskSecrets(get_object_vars($this));
    }

    /**
     * Restores a serialized model. The secret is gone by construction — `__serialize()` replaced it
     * with {@see RedactsSecrets::redacted()} — so a round-tripped model is safe to keep but useless
     * for verifying signatures. Fetch the object again, or store the secret where it belongs.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $reflection = new ReflectionObject($this);
        foreach ($data as $name => $value) {
            if ($reflection->hasProperty((string) $name)) {
                $reflection->getProperty((string) $name)->setValue($this, $value);
            }
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return self::maskSecrets(get_object_vars($this));
    }

    /**
     * @param  array<mixed> $values
     * @return array<string, mixed>
     */
    private static function maskSecrets(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::secretKeys(), true)) {
                $out[$key] = $value === null ? null : self::redacted();

                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::maskSecrets($value);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
