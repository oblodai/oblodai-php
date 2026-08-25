<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/split/rule`.
 */
final class SplitRuleRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Share of every payment, as a string: "10" = 10 %, "2.5" = 2.5 %. Greater than 0 and at most 100, step 0.01 %; the sum of all rules cannot exceed 100 %.
         * Example: "10".
         */
        public readonly string $percent,
        /** External crypto address of the partner; the share leaves as a real on-chain transaction — irreversible. Exactly one recipient option: either address+network or merchant_id. */
        public readonly ?string $address = null,
        /**
         * Id of the partner merchant inside Oblodai; the share moves through internal accounting and is clawed back on a refund.
         * Example: "b4c1f0e2-5a77-4d31-9f08-2c6e7a1b3d94".
         */
        public readonly ?string $merchant_id = null,
        /**
         * Address network. Required together with address.
         * Example: "tron".
         */
        public readonly string|Network|null $network = null,
        /** Comment for yourself (shown in the rule list). */
        public readonly ?string $note = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'percent' => $this->percent,
            'address' => $this->address,
            'merchant_id' => $this->merchant_id,
            'network' => $this->network,
            'note' => $this->note,
        ]);
    }
}
