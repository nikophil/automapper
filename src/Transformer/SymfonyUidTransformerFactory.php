<?php

declare(strict_types=1);

namespace AutoMapper\Transformer;

use AutoMapper\MapperMetadataInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Ulid;

/**
 * @author Baptiste Leduc <baptiste.leduc@gmail.com>
 */
final class SymfonyUidTransformerFactory extends AbstractUniqueTypeTransformerFactory implements PrioritizedTransformerFactoryInterface
{
    private array $reflectionCache = [];

    protected function createTransformer(Type $sourceType, Type $targetType, MapperMetadataInterface $mapperMetadata): ?TransformerInterface
    {
        $isSourceUid = $this->isUid($sourceType);
        $isTargetUid = $this->isUid($targetType);

        if ($isSourceUid && $isTargetUid) {
            return new SymfonyUidCopyTransformer();
        }

        if ($isSourceUid) {
            return new SymfonyUidToStringTransformer($this->reflectionCache[$sourceType->getClassName()][1]);
        }

        if ($isTargetUid) {
            return new StringToSymfonyUidTransformer($targetType->getClassName());
        }

        return null;
    }

    private function isUid(Type $type): bool
    {
        if (Type::BUILTIN_TYPE_OBJECT !== $type->getBuiltinType()) {
            return false;
        }

        if (null === $type->getClassName()) {
            return false;
        }

        if (!\array_key_exists($type->getClassName(), $this->reflectionCache)) {
            $reflClass = new \ReflectionClass($type->getClassName());
            $this->reflectionCache[$type->getClassName()] = [$reflClass->isSubclassOf(AbstractUid::class), $type->getClassName() === Ulid::class];
        }

        return $this->reflectionCache[$type->getClassName()][0];
    }

    public function getPriority(): int
    {
        return 24;
    }
}
