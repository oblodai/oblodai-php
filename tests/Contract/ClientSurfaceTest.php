<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Core\Resource;
use Oblodai\Generated\Routes;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\Operations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The client reaches every operation of the contract, under the names `names.lock` fixes: a
 * resource the client forgot to expose, or a method renamed without the lock, fails here.
 */
final class ClientSurfaceTest extends TestCase
{
    public function testTheClientExposesEveryGeneratedResource(): void
    {
        $exposed = [];
        foreach ((new ReflectionClass(Oblodai::class))->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Resource::class)) {
                $exposed[$property->getName()] = $type->getName();
            }
        }
        $generated = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Generated/Resource/*.php') ?: [] as $file) {
            $class = basename($file, '.php');
            $generated[lcfirst($class)] = 'Oblodai\\Generated\\Resource\\' . $class;
        }
        ksort($exposed);
        ksort($generated);

        self::assertSame($generated, $exposed);
    }

    public function testEveryOperationHasExactlyOneMethod(): void
    {
        $ops = array_keys(Operations::all());
        $all = Routes::OPERATIONS;
        sort($all);

        self::assertSame($all, $ops);
        self::assertCount(count(Routes::all()), $ops);
    }

    public function testMethodNamesAreTheLockedNamesInCamelCase(): void
    {
        $locked = array_values(array_filter(array_map('trim', file(dirname(__DIR__, 2) . '/names.lock') ?: [])));
        $methods = [];
        foreach (Operations::all() as $op) {
            $methods[] = self::snake($op['resource']) . '.' . self::snake($op['method']);
        }
        sort($methods);
        sort($locked);

        self::assertSame($locked, $methods);
    }

    public function testEveryMethodTakesRequestOptionsLast(): void
    {
        $client = new Oblodai(publicId: 'pk', secret: 's', env: []);
        foreach (Operations::all() as $operationId => $op) {
            $resource = $client->{$op['resource']};
            self::assertInstanceOf(Resource::class, $resource);
            $method = new \ReflectionMethod($resource, $op['method']);
            $params = $method->getParameters();
            $last = end($params);
            self::assertNotFalse($last, $operationId);
            self::assertSame('options', $last->getName(), $operationId);
            self::assertTrue($last->allowsNull(), $operationId);
        }
    }

    private static function snake(string $camel): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
    }
}
