<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use BackedEnum;
use Oblodai\Core\Model;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;

/**
 * Minimal valid wire bodies for generated models, built from the models themselves: every required
 * field present with a value of its type (the first case of an enum, a sample of a nested model),
 * optional fields left out.
 */
final class Samples
{
    /**
     * @param class-string|string  $class     a generated model class
     * @param array<string, mixed> $overrides wire keys to set on top
     *
     * @return array<string, mixed>
     */
    public static function of(string $class, array $overrides = [], int $depth = 0): array
    {
        if (!class_exists($class)) {
            throw new RuntimeException(sprintf('no model %s', $class));
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->hasMethod('parse') && !$reflection->isSubclassOf(Model::class)) {
            // oneOf: a sample of its first variant.
            /** @var list<class-string> $variants */
            $variants = $reflection->getConstant('VARIANTS');

            return self::of($variants[0], $overrides, $depth);
        }
        $constructor = $reflection->getConstructor() ?? throw new RuntimeException($class . ' has no constructor');
        /** @var list<string> $fields */
        $fields = $reflection->getConstant('FIELDS');
        $wire = [];
        foreach ($fields as $key) {
            $wire[self::ident($key)] = $key;
        }
        $out = [];
        foreach ($constructor->getParameters() as $param) {
            if ($param->isOptional()) {
                continue;
            }
            $key = $wire[$param->getName()] ?? $param->getName();
            $out[$key] = self::value($param->getType(), $depth);
        }

        return array_replace($out, $overrides);
    }

    private static function value(?\ReflectionType $type, int $depth): mixed
    {
        $names = [];
        if ($type instanceof ReflectionNamedType) {
            $names = [$type->getName()];
        } elseif ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType) {
                    $names[] = $t->getName();
                }
            }
        }
        foreach ($names as $name) {
            if (enum_exists($name) && is_subclass_of($name, BackedEnum::class)) {
                return $name::cases()[0]->value;
            }
            if (class_exists($name) && $depth < 8) {
                return self::of($name, [], $depth + 1);
            }
        }

        return match ($names[0] ?? 'mixed') {
            'string' => 'x',
            'int' => 0,
            'float' => 0,
            'bool' => false,
            'array' => [],
            default => null,
        };
    }

    /** The property name the generator gives a wire key. */
    private static function ident(string $key): string
    {
        $out = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $key);

        return preg_match('/^\d/', $out) === 1 ? '_' . $out : ($out === '' ? '_' : $out);
    }
}
