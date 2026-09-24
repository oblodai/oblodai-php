<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\RequestBuilder;
use Oblodai\Exception\ConfigException;
use Oblodai\Generated\Model\PaymentRequest;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A float in an amount is an error before the network (spec §3 item 3): amounts are decimal
 * strings, and the generated models type them so.
 */
final class MoneyFloatTest extends TestCase
{
    public function testAFloatAmountFailsBeforeTheNetworkWithItsOwnCode(): void
    {
        $fake = new FakeHttpClient([]);
        $ob = new Oblodai(publicId: 'pk', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        try {
            // @phpstan-ignore argument.type (the float amount is the mistake under test)
            $ob->payments->create(['amount' => 25.5, 'currency' => 'USDT', 'order_id' => 'o1']);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('sdk.float_amount', $e->errorCode);
            self::assertSame('amount', $e->field);
            self::assertStringStartsWith('[sdk.float_amount] ', $e->getMessage());
            self::assertStringContainsString("'25.5'", $e->getMessage());
        }
        self::assertSame(0, $fake->count());
    }

    public function testADecimalStringGoesToTheWireVerbatim(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::sample('createPayment')]);
        $ob = new Oblodai(publicId: 'pk', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $ob->payments->create(new PaymentRequest(amount: '25.10', currency: 'USDT'));

        self::assertSame('{"amount":"25.10","currency":"USDT"}', $fake->calls[0]->body);
    }

    /**
     * The only floats a body may carry are the contract's non-money numbers: the list the runtime
     * lets through is exactly the float properties of the generated request models.
     */
    public function testTheNonMoneyNumbersAreExactlyTheFloatFieldsOfTheRequestModels(): void
    {
        $floats = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Generated/Resource/*.php') ?: [] as $file) {
            preg_match_all('/\b(\w+)\|array \$params/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $model) {
                $class = 'Oblodai\\Generated\\Model\\' . $model;
                self::assertTrue(class_exists($class), $class);
                $constructor = (new ReflectionClass($class))->getConstructor();
                foreach ($constructor?->getParameters() ?? [] as $param) {
                    $type = $param->getType();
                    if ($type instanceof ReflectionNamedType && $type->getName() === 'float') {
                        $floats[$param->getName()] = true;
                    }
                }
            }
        }
        $floats = array_keys($floats);
        sort($floats);

        self::assertNotSame([], $floats, 'the scan found no request model at all');
        self::assertSame($floats, RequestBuilder::NON_MONEY_NUMBERS);
    }
}
