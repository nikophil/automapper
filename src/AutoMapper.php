<?php

declare(strict_types=1);

namespace AutoMapper;

use AutoMapper\Exception\NoMappingFoundException;
use AutoMapper\Extractor\FromSourceMappingExtractor;
use AutoMapper\Extractor\FromTargetMappingExtractor;
use AutoMapper\Extractor\MapToContextPropertyInfoExtractorDecorator;
use AutoMapper\Extractor\SourceTargetMappingExtractor;
use AutoMapper\Generator\Generator;
use AutoMapper\Loader\ClassLoaderInterface;
use AutoMapper\Loader\EvalLoader;
use AutoMapper\Transformer\ArrayTransformerFactory;
use AutoMapper\Transformer\BuiltinTransformerFactory;
use AutoMapper\Transformer\ChainTransformerFactory;
use AutoMapper\Transformer\DateTimeTransformerFactory;
use AutoMapper\Transformer\EnumTransformerFactory;
use AutoMapper\Transformer\MultipleTransformerFactory;
use AutoMapper\Transformer\NullableTransformerFactory;
use AutoMapper\Transformer\ObjectTransformerFactory;
use AutoMapper\Transformer\SymfonyUidTransformerFactory;
use AutoMapper\Transformer\TransformerFactoryInterface;
use AutoMapper\Transformer\UniqueTypeTransformerFactory;
use Doctrine\Common\Annotations\AnnotationReader;
use PhpParser\ParserFactory;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorFromClassMetadata;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\NameConverter\AdvancedNameConverterInterface;
use Symfony\Component\Uid\AbstractUid;

/**
 * Maps a source data structure (object or array) to a target one.
 *
 * @author Joel Wurtz <jwurtz@jolicode.com>
 */
class AutoMapper implements AutoMapperInterface, AutoMapperRegistryInterface, MapperGeneratorMetadataRegistryInterface
{
    /** @var MapperGeneratorMetadataInterface[] */
    private array $metadata = [];

    /** @var GeneratedMapper[] */
    private array $mapperRegistry = [];

    public function __construct(
        private readonly ClassLoaderInterface $classLoader,
        private readonly ChainTransformerFactory $chainTransformerFactory,
        private readonly ?MapperGeneratorMetadataFactoryInterface $mapperConfigurationFactory = null
    ) {
    }

    public function register(MapperGeneratorMetadataInterface $configuration): void
    {
        $this->metadata[$configuration->getSource()][$configuration->getTarget()] = $configuration;
    }

    public function getMapper(string $source, string $target): MapperInterface
    {
        $metadata = $this->getMetadata($source, $target);

        if (null === $metadata) {
            throw new NoMappingFoundException('No mapping found for source ' . $source . ' and target ' . $target);
        }

        $className = $metadata->getMapperClassName();

        if (\array_key_exists($className, $this->mapperRegistry)) {
            return $this->mapperRegistry[$className];
        }

        if (!class_exists($className)) {
            $this->classLoader->loadClass($metadata);
        }

        $this->mapperRegistry[$className] = new $className();
        $this->mapperRegistry[$className]->injectMappers($this);

        foreach ($metadata->getCallbacks() as $property => $callback) {
            $this->mapperRegistry[$className]->addCallback($property, $callback);
        }

        return $this->mapperRegistry[$className];
    }

    public function hasMapper(string $source, string $target): bool
    {
        return null !== $this->getMetadata($source, $target);
    }

    public function map(null|array|object $source, string|array|object $target, array $context = []): null|array|object
    {
        $sourceType = $targetType = null;

        if (null === $source) {
            return null;
        }

        if (\is_object($source)) {
            $sourceType = \get_class($source);
        } elseif (\is_array($source)) {
            $sourceType = 'array';
        }

        if (null === $sourceType) {
            throw new NoMappingFoundException('Cannot map this value, source is neither an object or an array.');
        }

        if (\is_object($target)) {
            $targetType = \get_class($target);
            $context[MapperContext::TARGET_TO_POPULATE] = $target;
        } elseif (\is_array($target)) {
            $targetType = 'array';
            $context[MapperContext::TARGET_TO_POPULATE] = $target;
        } elseif (\is_string($target)) {
            $targetType = $target;
        }

        if (null === $targetType) {
            throw new NoMappingFoundException('Cannot map this value, target is neither an object or an array.');
        }

        if ('array' === $sourceType && 'array' === $targetType) {
            throw new NoMappingFoundException('Cannot map this value, both source and target are array.');
        }

        return $this->getMapper($sourceType, $targetType)->map($source, $context);
    }

    public function getMetadata(string $source, string $target): ?MapperGeneratorMetadataInterface
    {
        if (!isset($this->metadata[$source][$target])) {
            if (null === $this->mapperConfigurationFactory) {
                return null;
            }

            $this->register($this->mapperConfigurationFactory->create($this, $source, $target));
        }

        return $this->metadata[$source][$target];
    }

    public function bindTransformerFactory(TransformerFactoryInterface $transformerFactory): void
    {
        if (!$this->chainTransformerFactory->hasTransformerFactory($transformerFactory)) {
            $this->chainTransformerFactory->addTransformerFactory($transformerFactory);
        }
    }

    public static function create(
        bool $private = true,
        ClassLoaderInterface $loader = null,
        AdvancedNameConverterInterface $nameConverter = null,
        string $classPrefix = 'Mapper_',
        bool $attributeChecking = true,
        bool $autoRegister = true,
        string $dateTimeFormat = \DateTimeInterface::RFC3339,
        bool $allowReadOnlyTargetToPopulate = false
    ): self {
        $classMetadataFactory = new ClassMetadataFactory(new AnnotationLoader(new AnnotationReader()));

        if (null === $loader) {
            $loader = new EvalLoader(new Generator(
                (new ParserFactory())->create(ParserFactory::PREFER_PHP7),
                new ClassDiscriminatorFromClassMetadata($classMetadataFactory),
                $allowReadOnlyTargetToPopulate
            ));
        }

        $flags = ReflectionExtractor::ALLOW_PUBLIC;

        if ($private) {
            $flags |= ReflectionExtractor::ALLOW_PROTECTED | ReflectionExtractor::ALLOW_PRIVATE;
        }

        $reflectionExtractor = new ReflectionExtractor(
            null,
            null,
            null,
            true,
            $flags
        );

        $phpDocExtractor = new PhpDocExtractor();
        $propertyInfoExtractor = new PropertyInfoExtractor(
            [$reflectionExtractor],
            [$phpDocExtractor, $reflectionExtractor],
            [$reflectionExtractor],
            [new MapToContextPropertyInfoExtractorDecorator($reflectionExtractor)]
        );

        $transformerFactory = new ChainTransformerFactory();
        $sourceTargetMappingExtractor = new SourceTargetMappingExtractor(
            $propertyInfoExtractor,
            new MapToContextPropertyInfoExtractorDecorator($reflectionExtractor),
            $reflectionExtractor,
            $transformerFactory,
            $classMetadataFactory
        );

        $fromTargetMappingExtractor = new FromTargetMappingExtractor(
            $propertyInfoExtractor,
            $reflectionExtractor,
            $reflectionExtractor,
            $transformerFactory,
            $classMetadataFactory,
            $nameConverter
        );

        $fromSourceMappingExtractor = new FromSourceMappingExtractor(
            $propertyInfoExtractor,
            new MapToContextPropertyInfoExtractorDecorator($reflectionExtractor),
            $reflectionExtractor,
            $transformerFactory,
            $classMetadataFactory,
            $nameConverter
        );

        $autoMapper = $autoRegister ? new self($loader, $transformerFactory, new MapperGeneratorMetadataFactory(
            $sourceTargetMappingExtractor,
            $fromSourceMappingExtractor,
            $fromTargetMappingExtractor,
            $classPrefix,
            $attributeChecking,
            $dateTimeFormat
        )) : new self($loader, $transformerFactory);

        $transformerFactory->addTransformerFactory(new MultipleTransformerFactory($transformerFactory));
        $transformerFactory->addTransformerFactory(new NullableTransformerFactory($transformerFactory));
        $transformerFactory->addTransformerFactory(new UniqueTypeTransformerFactory($transformerFactory));
        $transformerFactory->addTransformerFactory(new DateTimeTransformerFactory());
        $transformerFactory->addTransformerFactory(new BuiltinTransformerFactory());
        $transformerFactory->addTransformerFactory(new ArrayTransformerFactory($transformerFactory));
        $transformerFactory->addTransformerFactory(new ObjectTransformerFactory($autoMapper));
        $transformerFactory->addTransformerFactory(new EnumTransformerFactory());

        if (class_exists(AbstractUid::class)) {
            $transformerFactory->addTransformerFactory(new SymfonyUidTransformerFactory());
        }

        return $autoMapper;
    }
}
