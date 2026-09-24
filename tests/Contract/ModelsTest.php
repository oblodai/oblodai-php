<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Core\Model;
use Oblodai\Exception\ContractException;
use Oblodai\Generated\Enum\PaymentStatus;
use Oblodai\Generated\Model\PaymentView;
use Oblodai\Tests\Support\Samples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every generated model reads a minimal valid body and writes it back; newer fields and enum
 * values than this SDK knows are kept, never dropped and never fatal (spec §3 item 4).
 */
final class ModelsTest extends TestCase
{
    /** @return iterable<string, array{class-string<Model>}> */
    public static function models(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/src/Generated/Model/*.php') ?: [] as $file) {
            $class = 'Oblodai\\Generated\\Model\\' . basename($file, '.php');
            if (is_subclass_of($class, Model::class)) {
                yield basename($file, '.php') => [$class];
            }
        }
    }

    /** @param class-string<Model> $class */
    #[DataProvider('models')]
    public function testReadsItsSampleAndWritesItBack(string $class): void
    {
        $sample = Samples::of($class);
        $fromArray = [$class, 'fromArray'];
        self::assertIsCallable($fromArray);
        $model = $fromArray($sample + ['brand_new_field' => ['nested' => true]]);

        self::assertInstanceOf($class, $model);
        self::assertSame(['brand_new_field' => ['nested' => true]], get_object_vars($model)['extra']);
        $wire = $model->toArray();
        self::assertEquals($sample + ['brand_new_field' => ['nested' => true]], $wire);
        self::assertSame(array_keys($sample + ['brand_new_field' => 1]), array_keys($wire));
    }

    public function testCountsEveryModel(): void
    {
        self::assertGreaterThan(150, iterator_count(self::models()));
    }

    public function testAnUnknownEnumValueStaysTheString(): void
    {
        $known = PaymentView::fromArray(Samples::of(PaymentView::class, ['status' => 'paid']));
        $unknown = PaymentView::fromArray(Samples::of(PaymentView::class, ['status' => 'teleported']));

        self::assertSame(PaymentStatus::Paid, $known->status);
        self::assertSame('teleported', $unknown->status);
        self::assertSame('teleported', $unknown->toArray()['status']);
    }

    public function testAMissingRequiredFieldOrAWrongTypeIsAContractError(): void
    {
        $sample = Samples::of(PaymentView::class);
        unset($sample['uuid']);

        try {
            PaymentView::fromArray($sample);
            self::fail('expected a ContractException');
        } catch (ContractException $e) {
            self::assertStringContainsString('uuid', $e->getMessage());
        }

        $this->expectException(ContractException::class);
        PaymentView::fromArray(Samples::of(PaymentView::class, ['amount' => 25.5]));
    }

    public function testAmountsStayDecimalStrings(): void
    {
        $view = PaymentView::fromArray(Samples::of(PaymentView::class, ['amount' => '25.10']));

        self::assertSame('25.10', $view->amount);
    }
}
