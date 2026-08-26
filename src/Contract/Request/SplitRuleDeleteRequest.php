<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/split/rule/delete`.
 */
final class SplitRuleDeleteRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Rule identifier from POST /v1/split/rule or the list.
         * Example: "9f4c1a2b-77de-4a55-9c1f-0e2b3d4a5f60".
         */
        public readonly string $rule_id,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'rule_id' => $this->rule_id,
        ]);
    }
}
