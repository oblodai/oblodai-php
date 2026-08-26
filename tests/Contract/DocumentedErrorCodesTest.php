<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Contract\Enums;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every error code a method's docblock tells a caller to branch on must be one the gateway can
 * actually emit.
 *
 * This is not pedantry about documentation. A code that does not exist sends the reader to write a
 * `match` arm that never fires — the reference SDK told PHP callers to branch on `wallet.blocked`
 * for a blocked-address refund, and no such code is in the catalogue, so the handling of a genuine
 * `refund.nothing_to_refund` fell through to the default branch instead.
 *
 * Only codes the API itself returns are checked. The SDK's own families never appear in the
 * catalogue because the gateway never sends them, and `wallet.paid` and friends are webhook event
 * types rather than errors.
 */
final class DocumentedErrorCodesTest extends TestCase
{
    /** Prefixes the SDK raises itself; the gateway's catalogue has nothing to say about them. */
    private const SDK_PREFIXES = ['sdk.', 'transport.', 'webhook.'];

    /** Wire vocabulary that reads like `family.reason` but is an event type, not an error. */
    private const EVENT_TYPES = ['wallet.paid'];

    /** @return iterable<string, array{string}> */
    public static function resourceFiles(): iterable
    {
        foreach ((array) glob(dirname(__DIR__, 2) . '/src/Resource/*.php') as $path) {
            yield basename((string) $path) => [(string) $path];
        }
    }

    #[DataProvider('resourceFiles')]
    public function testEveryDocumentedCodeExistsInTheCatalogue(string $path): void
    {
        $unknown = [];
        foreach (self::documentedCodes((string) file_get_contents($path)) as $code) {
            if (!in_array($code, Enums::ERROR_CODES, true)) {
                $unknown[] = $code;
            }
        }

        self::assertSame(
            [],
            $unknown,
            sprintf(
                '%s documents %s, which the gateway cannot emit — take the codes from '
                    . 'Enums::ERROR_CODES (contract/contract.json), not from memory',
                basename($path),
                implode(', ', $unknown)
            )
        );
    }

    /** The check must be able to fail, or it proves nothing. */
    public function testTheCheckRejectsAnInventedCode(): void
    {
        $doc = <<<'PHP'
            /**
             * Codes worth branching on: `refund.dust`, `wallet.blocked`.
             */
            PHP;

        self::assertSame(['refund.dust', 'wallet.blocked'], self::documentedCodes($doc));
        self::assertContains('refund.dust', Enums::ERROR_CODES);
        self::assertNotContains(
            'wallet.blocked',
            Enums::ERROR_CODES,
            'wallet.blocked is a MODEL field, not an error code'
        );
    }

    /** At least the money-moving resources must actually name codes, or the sweep is vacuous. */
    public function testTheMoneyMovingResourcesDocumentCodes(): void
    {
        foreach (['Payments', 'Payouts', 'Refunds', 'PayoutLinks', 'PaymentLinks', 'Transfers', 'Wallets'] as $name) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Resource/' . $name . '.php');
            self::assertGreaterThanOrEqual(
                3,
                count(self::documentedCodes($source)),
                $name . ' names too few error codes for a caller to branch on'
            );
        }
    }

    /**
     * Codes named inside a "Codes worth branching on" block, in backticks.
     *
     * @return list<string>
     */
    private static function documentedCodes(string $source): array
    {
        preg_match_all(
            '/(?:Call-level codes|Codes) worth branching on:(.*?)(?:\n\s*\*\s*\n|\n\s*\*\/)/s',
            $source,
            $blocks
        );

        $out = [];
        foreach ($blocks[1] as $block) {
            preg_match_all('/`([a-z_]+\.[a-z_]+)`/', $block, $found);
            foreach ($found[1] as $code) {
                if (in_array($code, self::EVENT_TYPES, true)) {
                    continue;
                }
                foreach (self::SDK_PREFIXES as $prefix) {
                    if (str_starts_with($code, $prefix)) {
                        continue 2;
                    }
                }
                $out[] = $code;
            }
        }

        return $out;
    }
}
