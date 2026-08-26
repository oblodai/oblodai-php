<?php

declare(strict_types=1);

namespace Oblodai\Helper;

use Oblodai\Exception\ConfigException;

/**
 * Amounts are decimal strings; never cast them to float (USDT has 6 decimals, BTC 8, ETH 18 — a
 * double loses the last of those). These helpers compare and add at arbitrary precision by working
 * on the digits themselves, so they need no bcmath.
 *
 * Scale is preserved, not normalised: `add('10.000000', '0.5')` is `'10.500000'` and
 * `subtract('1.5', '0.5')` is `'1.0'`, matching what the gateway echoes back and what the other
 * SDKs return. Compare with {@see Money::compare()}, never with `===` or `strcmp()` — `'9'` sorts
 * after `'10'` as text and before it as money.
 *
 * Anything that is not a decimal amount is a `ConfigException` (`sdk.bad_amount`), so a caller
 * catching `OblodaiException` catches it; nothing here throws a native `TypeError`.
 */
final class Money
{
    /**
     * `-?` digits, at most one dot, non-empty on both sides of it. Deliberately `[0-9]` and not
     * `\d`: with a Unicode-aware `\d` an Arabic-Indic digit would pass the check and then be
     * mangled by the arithmetic below.
     */
    private const DECIMAL = '/^-?[0-9]+(\.[0-9]+)?$/';

    /**
     * Longest amount the helpers accept, characters. No asset needs anything close (BTC's total
     * supply in satoshi is 16 digits; ETH's smallest unit gives 18 decimals), and the cap keeps a
     * hostile string from turning digit-by-digit arithmetic into a denial of service.
     */
    public const MAX_LENGTH = 64;

    /** -1, 0 or 1. */
    public static function compare(string $a, string $b): int
    {
        $scale = max(self::scaleOf($a), self::scaleOf($b));
        $x = self::scaled($a, $scale);
        $y = self::scaled($b, $scale);

        return self::compareIntegerStrings($x, $y);
    }

    public static function equals(string $a, string $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function add(string $a, string $b): string
    {
        $scale = max(self::scaleOf($a), self::scaleOf($b));

        return self::unscale(
            self::addIntegerStrings(self::scaled($a, $scale), self::scaled($b, $scale)),
            $scale
        );
    }

    public static function subtract(string $a, string $b): string
    {
        $scale = max(self::scaleOf($a), self::scaleOf($b));

        return self::unscale(
            self::addIntegerStrings(self::scaled($a, $scale), self::negate(self::scaled($b, $scale))),
            $scale
        );
    }

    public static function isZero(string $amount): bool
    {
        return self::compare($amount, '0') === 0;
    }

    public static function isPositive(string $amount): bool
    {
        return self::compare($amount, '0') > 0;
    }

    /** Number of digits after the decimal point. */
    public static function scaleOf(string $amount): int
    {
        [, , $frac] = self::parts($amount);

        return strlen($frac);
    }

    /**
     * Validate an amount without doing anything with it — useful before handing it to the API.
     *
     * @throws ConfigException when the string is not a decimal amount the gateway would accept
     */
    public static function assertAmount(string $amount): void
    {
        self::parts($amount);
    }

    /** @return array{0: bool, 1: string, 2: string} negative, integer digits, fractional digits */
    private static function parts(string $amount): array
    {
        if (strlen($amount) > self::MAX_LENGTH) {
            throw new ConfigException(
                ConfigException::BAD_AMOUNT,
                sprintf('amount is too long (%d chars, max %d)', strlen($amount), self::MAX_LENGTH),
                'amount'
            );
        }
        if (preg_match(self::DECIMAL, $amount) !== 1) {
            throw new ConfigException(
                ConfigException::BAD_AMOUNT,
                sprintf(
                    'not a decimal amount: %s — expected digits with at most one dot and a non-empty '
                        . 'integer and fractional part, optionally signed',
                    json_encode($amount, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ),
                'amount'
            );
        }
        $negative = str_starts_with($amount, '-');
        $body = $negative ? substr($amount, 1) : $amount;
        $dot = strpos($body, '.');

        return $dot === false
            ? [$negative, $body, '']
            : [$negative, substr($body, 0, $dot), substr($body, $dot + 1)];
    }

    /** The amount as a signed integer string at `$scale` decimal places. */
    private static function scaled(string $amount, int $scale): string
    {
        [$negative, $int, $frac] = self::parts($amount);
        $digits = ltrim($int . str_pad($frac, $scale, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;

        return ($negative && $digits !== '0' ? '-' : '') . $digits;
    }

    private static function unscale(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $digits = str_pad($negative ? substr($value, 1) : $value, $scale + 1, '0', STR_PAD_LEFT);
        $int = substr($digits, 0, strlen($digits) - $scale);
        $frac = $scale > 0 ? substr($digits, strlen($digits) - $scale) : '';
        $out = $int . ($scale > 0 ? '.' . $frac : '');

        return $negative && trim($out, '0.') !== '' ? '-' . $out : $out;
    }

    private static function negate(string $value): string
    {
        if ($value === '0') {
            return $value;
        }

        return str_starts_with($value, '-') ? substr($value, 1) : '-' . $value;
    }

    /** Signed addition on integer strings of arbitrary length. */
    private static function addIntegerStrings(string $a, string $b): string
    {
        $aNeg = str_starts_with($a, '-');
        $bNeg = str_starts_with($b, '-');
        $aAbs = $aNeg ? substr($a, 1) : $a;
        $bAbs = $bNeg ? substr($b, 1) : $b;

        if ($aNeg === $bNeg) {
            return ($aNeg ? '-' : '') . self::addAbs($aAbs, $bAbs);
        }
        $cmp = self::compareAbs($aAbs, $bAbs);
        if ($cmp === 0) {
            return '0';
        }
        if ($cmp > 0) {
            return ($aNeg ? '-' : '') . self::subAbs($aAbs, $bAbs);
        }

        return ($bNeg ? '-' : '') . self::subAbs($bAbs, $aAbs);
    }

    private static function compareIntegerStrings(string $a, string $b): int
    {
        $aNeg = str_starts_with($a, '-');
        $bNeg = str_starts_with($b, '-');
        if ($aNeg !== $bNeg) {
            return $aNeg ? -1 : 1;
        }
        $cmp = self::compareAbs($aNeg ? substr($a, 1) : $a, $bNeg ? substr($b, 1) : $b);

        return $aNeg ? -$cmp : $cmp;
    }

    private static function compareAbs(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }

    private static function addAbs(string $a, string $b): string
    {
        $length = max(strlen($a), strlen($b));
        $a = str_pad($a, $length, '0', STR_PAD_LEFT);
        $b = str_pad($b, $length, '0', STR_PAD_LEFT);
        $carry = 0;
        $out = '';
        for ($i = $length - 1; $i >= 0; --$i) {
            $sum = (int) $a[$i] + (int) $b[$i] + $carry;
            $carry = intdiv($sum, 10);
            $out = ($sum % 10) . $out;
        }

        return $carry > 0 ? $carry . $out : $out;
    }

    /** `$a` must be >= `$b` in absolute value. */
    private static function subAbs(string $a, string $b): string
    {
        $length = max(strlen($a), strlen($b));
        $a = str_pad($a, $length, '0', STR_PAD_LEFT);
        $b = str_pad($b, $length, '0', STR_PAD_LEFT);
        $borrow = 0;
        $out = '';
        for ($i = $length - 1; $i >= 0; --$i) {
            $digit = (int) $a[$i] - (int) $b[$i] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $out = ($digit + ($borrow * 10)) . $out;
        }
        $trimmed = ltrim($out, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
