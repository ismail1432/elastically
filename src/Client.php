<?php

namespace JoliCode\Elastically;

use Elastica\Client as ElasticaClient;
use Elastica\Exception\RuntimeException;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

class Client extends ElasticaClient
{
    /* Elastically config keys */
    const CONFIG_MAPPINGS_DIRECTORY = 'elastically_mappings_directory';
    const CONFIG_INDEX_CLASS_MAPPING = 'elastically_index_class_mapping';
    const CONFIG_INDEX_PREFIX = 'elastically_index_prefix';
    const CONFIG_SERIALIZER_CONTEXT_PER_CLASS = 'elastically_serializer_context_per_class';
    const CONFIG_SERIALIZER = 'elastically_serializer';
    const CONFIG_BULK_SIZE = 'elastically_bulk_size';

    private $indexer;
    private $indexBuilder;

    /** @var AbstractMappingReader */
    private $mappingReader;

    public function getIndexBuilder(): IndexBuilder
    {
        if (!$this->indexBuilder) {
            $this->indexBuilder = new IndexBuilder($this, $this->getMappingReader()->getOption('elastically_mappings_directory'));
        }

        return $this->indexBuilder;
    }

    public function getIndexer(): Indexer
    {
        if (!$this->indexer) {
            $this->indexer = new Indexer($this, $this->getSerializer(), $this->getConfigValue(self::CONFIG_BULK_SIZE) ?? $this->getMappingReader()->getOption(self::CONFIG_BULK_SIZE));
        }

        return $this->indexer;
    }

    public function getIndex($name): Index
    {
        $name = $this->getPrefixedIndex($name);

        return new Index($this, $name);
    }

    public function getPrefixedIndex(string $name): string
    {
        $prefix = $this->getConfigValue(self::CONFIG_INDEX_PREFIX) ??
            $this->getMappingReader()->getOption(self::CONFIG_INDEX_PREFIX);

        if ($prefix) {
            return sprintf('%s_%s', $prefix, $name);
        }

        return $name;
    }

    public function getIndexNameFromClass(string $className): string
    {
        $indexToClass = $this->getConfigValue(self::CONFIG_INDEX_CLASS_MAPPING) ??
            $this->getMappingReader()->getOption(self::CONFIG_INDEX_CLASS_MAPPING);

        $indexName = array_search($className, $indexToClass, true);

        if (!$indexName) {
            throw new RuntimeException(sprintf('The given type (%s) does not exist in the configuration.', $className));
        }

        return $indexName;
    }

    public function getPureIndexName(string $fullIndexName): string
    {
        $prefix = $this->getConfigValue(self::CONFIG_INDEX_PREFIX) ??
            $this->getMappingReader()->getOption(self::CONFIG_INDEX_PREFIX);

        if ($prefix) {
            $pattern = sprintf('/%s_(.+)_\d{4}-\d{2}-\d{2}-\d+/i', preg_quote($prefix, '/'));
        } else {
            $pattern = '/(.+)_\d{4}-\d{2}-\d{2}-\d+/i';
        }

        if (1 === preg_match($pattern, $fullIndexName, $matches)) {
            return $matches[1];
        }

        return $fullIndexName;
    }

    public function getSerializer(): SerializerInterface
    {
        $configSerializer = $this->getConfigValue(self::CONFIG_SERIALIZER) ??
            $this->getMappingReader()->getOption(self::CONFIG_SERIALIZER);

        if ($configSerializer) {
            return $configSerializer;
        }

        // Use a minimal default serializer
        return new Serializer([
            new ArrayDenormalizer(),
            new DateTimeNormalizer(),
            new ObjectNormalizer(null, null, null, new PhpDocExtractor()),
        ], [
            new JsonEncoder(),
        ]);
    }

    public function getSerializerContext($class): array
    {
        $configSerializer = $this->getConfigValue(self::CONFIG_SERIALIZER_CONTEXT_PER_CLASS)
            ?? $this->getMappingReader()->getOption(self::CONFIG_SERIALIZER_CONTEXT_PER_CLASS);

        return $configSerializer[$class] ?? [];
    }

    public function setMappingReader(AbstractMappingReader $mappingReader): void
    {
        $this->mappingReader = $mappingReader;
    }

    public function getMappingReader(): AbstractMappingReader
    {
        if (null === $this->mappingReader) {
            $this->mappingReader = $this->getConfigValue('mapping_reader') ?? new YamlMapping($this->getConfig());

            if (!$this->mappingReader instanceof AbstractMappingReader) {
                throw new \LogicException(sprintf("Wrong configuration for 'mapping_reader', it should be an instance of %s.", AbstractMappingReader::class));
            }
        }

        return $this->mappingReader;
    }
}
