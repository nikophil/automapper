<?php

declare(strict_types=1);

namespace AutoMapper;

use AutoMapper\Extractor\FromSourceMappingExtractor;
use AutoMapper\Extractor\FromTargetMappingExtractor;
use AutoMapper\Extractor\SourceTargetMappingExtractor;

/**
 * Metadata factory, used to autoregistering new mapping without creating them.
 *
 * @author Joel Wurtz <jwurtz@jolicode.com>
 */
final readonly class MapperGeneratorMetadataFactory implements MapperGeneratorMetadataFactoryInterface
{
    public function __construct(
        private SourceTargetMappingExtractor $sourceTargetPropertiesMappingExtractor,
        private FromSourceMappingExtractor $fromSourcePropertiesMappingExtractor,
        private FromTargetMappingExtractor $fromTargetPropertiesMappingExtractor,
        private string $classPrefix = 'Mapper_',
        private bool $attributeChecking = true,
        private string $dateTimeFormat = \DateTime::RFC3339,
    ) {
    }

    /**
     * Create metadata for a source and target.
     */
    public function create(MapperGeneratorMetadataRegistryInterface $autoMapperRegister, string $source, string $target): MapperGeneratorMetadataInterface
    {
        $extractor = $this->sourceTargetPropertiesMappingExtractor;

        if ('array' === $source || 'stdClass' === $source) {
            $extractor = $this->fromTargetPropertiesMappingExtractor;
        }

        if ('array' === $target || 'stdClass' === $target) {
            $extractor = $this->fromSourcePropertiesMappingExtractor;
        }

        $mapperMetadata = new MapperMetadata($autoMapperRegister, $extractor, $source, $target, $this->isReadOnly($target), $this->classPrefix);
        $mapperMetadata->setAttributeChecking($this->attributeChecking);
        $mapperMetadata->setDateTimeFormat($this->dateTimeFormat);

        return $mapperMetadata;
    }

    private function isReadOnly(string $mappedType): bool
    {
        try {
            $reflClass = new \ReflectionClass($mappedType);
        } catch (\ReflectionException $e) {
            $reflClass = null;
        }
        if (\PHP_VERSION_ID >= 80200 && null !== $reflClass && $reflClass->isReadOnly()) {
            return true;
        }

        return false;
    }
}
