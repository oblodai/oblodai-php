<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use BackedEnum;
use JsonSerializable;
use Stringable;

/**
 * A closed-vocabulary wire value, read openly.
 *
 * The gateway keeps adding statuses, networks and fee modes. A receiver that throws on the first
 * value it has never seen answers 500 to an authentic webhook and gets it redelivered for a day —
 * so the SDK never refuses a value it does not recognise. `value` is always the raw string the core
 * sent; `known` is the typed case when this contract snapshot has one, and null when it does not.
 *
 * ```php
 * $payment->status->value;                       // "paid" — always the wire string
 * $payment->status->is(PaymentStatus::Paid);     // true
 * $payment->status->known === PaymentStatus::Paid;
 * if (!$payment->status->isKnown()) { … }        // newer than this SDK — log and move on
 * ```
 *
 * @template-covariant T of BackedEnum
 */
final class OpenEnum implements JsonSerializable, Stringable
{
    /**
     * @param T|null $known
     */
    private function __construct(
        /** The raw wire value, exactly as the core sent it. */
        public readonly string $value,
        /** The typed case, or null when the value is outside this contract snapshot. */
        public readonly ?BackedEnum $known = null,
    ) {
    }

    /**
     * Decode a wire string against a vocabulary. Never throws.
     *
     * @template E of BackedEnum
     *
     * @param class-string<E> $enum
     *
     * @return self<E>
     */
    public static function of(string $enum, string $value): self
    {
        /** @var self<E> */
        return new self($value, $enum::tryFrom($value));
    }

    /**
     * Wrap a case the caller already has (for building expectations in tests and fixtures).
     *
     * @template E of BackedEnum
     *
     * @param E $case
     *
     * @return self<E>
     */
    public static function from(BackedEnum $case): self
    {
        /** @var self<E> */
        return new self((string) $case->value, $case);
    }

    /** True when the core sent a value this snapshot knows. */
    public function isKnown(): bool
    {
        return $this->known !== null;
    }

    /** Compare against a case or a raw string. */
    public function is(BackedEnum|string $case): bool
    {
        return $this->value === ($case instanceof BackedEnum ? (string) $case->value : $case);
    }

    /** True when the value is any of the given cases or raw strings. */
    public function isOneOf(BackedEnum|string ...$cases): bool
    {
        foreach ($cases as $case) {
            if ($this->is($case)) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /** Serializes back to the wire string, so a re-encoded model matches what arrived. */
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
