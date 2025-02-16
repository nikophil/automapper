<?php

declare(strict_types=1);

namespace AutoMapper\Transformer;

use AutoMapper\Extractor\PropertyMapping;
use AutoMapper\Generator\UniqueVariableScope;
use AutoMapper\MapperContext;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use Symfony\Component\PropertyInfo\Type;

/**
 * Transform to an object which can be mapped by AutoMapper (sub mapping).
 *
 * @author Joel Wurtz <jwurtz@jolicode.com>
 */
final class ObjectTransformer implements TransformerInterface, DependentTransformerInterface, AssignedByReferenceTransformerInterface
{
    public function __construct(
        private Type $sourceType,
        private Type $targetType,
    ) {
    }

    public function transform(Expr $input, Expr $target, PropertyMapping $propertyMapping, UniqueVariableScope $uniqueVariableScope): array
    {
        $mapperName = $this->getDependencyName();

        return [new Expr\MethodCall(new Expr\ArrayDimFetch(
            new Expr\PropertyFetch(new Expr\Variable('this'), 'mappers'),
            new Scalar\String_($mapperName)
        ), 'map', [
            new Arg($input),
            new Arg(new Expr\StaticCall(new Name\FullyQualified(MapperContext::class), 'withNewContext', [
                new Arg(new Expr\Variable('context')),
                new Arg(new Scalar\String_($propertyMapping->property)),
            ])),
        ]), []];
    }

    public function assignByRef(): bool
    {
        return true;
    }

    public function getDependencies(): array
    {
        return [new MapperDependency($this->getDependencyName(), $this->getSource(), $this->getTarget())];
    }

    private function getDependencyName(): string
    {
        return 'Mapper_' . $this->getSource() . '_' . $this->getTarget();
    }

    private function getSource(): string
    {
        $sourceTypeName = 'array';

        if (Type::BUILTIN_TYPE_OBJECT === $this->sourceType->getBuiltinType()) {
            $sourceTypeName = $this->sourceType->getClassName();
        }

        return $sourceTypeName;
    }

    private function getTarget(): string
    {
        $targetTypeName = 'array';

        if (Type::BUILTIN_TYPE_OBJECT === $this->targetType->getBuiltinType()) {
            $targetTypeName = $this->targetType->getClassName();
        }

        return $targetTypeName;
    }
}
