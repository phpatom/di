<?php

namespace Atom\DI;

use ArrayAccess;
use Atom\DI\Contracts\ExtractorContract;
use Atom\DI\Contracts\DefinitionContract;
use Atom\DI\Contracts\StorageContract;
use Atom\DI\Definitions\BuildObject;
use Atom\DI\Definitions\DefinitionFactory;
use Atom\DI\Exceptions\NotFoundException;
use Atom\DI\Exceptions\ContainerException;
use Atom\DI\Exceptions\CircularDependencyException;
use Atom\DI\Exceptions\StorageNotFoundException;
use Atom\DI\Extraction\ContainerExtractor;
use Atom\DI\Extraction\ExtractionChain;
use Atom\DI\Extraction\FunctionExtractor;
use Atom\DI\Extraction\MethodExtractor;
use Atom\DI\Extraction\ObjectExtractor;
use Atom\DI\Extraction\ValueExtractor;
use Atom\DI\Extraction\WildcardExtractor;
use Atom\DI\Storage\FactoryStorage;
use Atom\DI\Storage\SingletonStorage;
use Atom\DI\Storage\ValueStorage;
use Atom\DI\Storage\WildcardStorage;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

class DIC implements ContainerInterface, ArrayAccess
{

    /**
     * @var array<ExtractorContract>
     */
    private $extractors = [];
    /**
     * @var StorageContract[]
     */
    private $container = [];

    /**
     * @var array
     */
    private $resolvedStorage = [];
    /**
     * @var callable
     */
    private $globalResolutionCallback;
    /**
     * @var array<string,callable>
     */
    private $resolutionCallback = [];
    /**
     * @var $instance DIC
     */
    private static $instance;

    /**
     * @var string
     */
    private $defaultStorageAlias = SingletonStorage::STORAGE_KEY;
    /**
     * @var ExtractionChain
     */
    private $chain;

    /**
     * @return DIC
     */
    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function clearInstance()
    {
        self::$instance = null;
    }

    /**
     * DIC constructor.
     * @throws ContainerException
     */
    public function __construct()
    {
        $this->addStorage(new FactoryStorage($this));
        $this->addStorage(new SingletonStorage($this));
        $this->addStorage(new ValueStorage($this));
        $this->addStorage(new WildcardStorage($this));
        $this->extractors = [
            MethodExtractor::class => new MethodExtractor(),
            ObjectExtractor::class => new ObjectExtractor(),
            FunctionExtractor::class => new FunctionExtractor(),
            ValueExtractor::class => new ValueExtractor(),
            ContainerExtractor::class => new ContainerExtractor(),
            WildcardExtractor::class => new WildcardExtractor()
        ];
        $this->chain = new ExtractionChain();
        self::$instance = $this;
    }

    /**
     * @param string $extractorClassName
     * @return ExtractorContract
     * @throws ContainerException
     */
    public function getExtractor(string $extractorClassName): ExtractorContract
    {
        if (!$this->hasExtractor($extractorClassName)) {
            throw new ContainerException("Unable to resolve the extractor [$extractorClassName]");
        }
        return $this->extractors[$extractorClassName];
    }

    public function hasExtractor($extractorClassName)
    {
        return array_key_exists($extractorClassName, $this->extractors);
    }

    public function addExtractor(ExtractorContract $extractor): void
    {
        $this->extractors[get_class($extractor)] = $extractor;
    }

    /**
     * Add a new storage. will throw if a storage with a similar key already exists
     * @param StorageContract $storage
     * @throws ContainerException
     */
    public function addStorage(StorageContract $storage)
    {
        if (array_key_exists($storageKey = $storage->getStorageKey(), $this->getContainer())) {
            throw new ContainerException("A storage with the key [$storageKey] already exists");
        }
        $this->container[$storage->getStorageKey()] = $storage;
    }


    /**
     * check if the storage exists
     * @param string $key
     * @return bool
     */
    public function hasStorage(string $key): bool
    {
        return array_key_exists($key, $this->container);
    }

    /**
     * get a storage
     * @param string $key
     * @return StorageContract
     * @throws StorageNotFoundException
     */
    public function getStorage(string $key): StorageContract
    {
        if (!$this->hasStorage($key)) {
            throw new StorageNotFoundException($key);
        }
        return $this->container[$key];
    }

    /**
     * return the container
     * @return StorageContract[]
     */
    public function getContainer(): array
    {
        return $this->container;
    }

    /**
     * return the default storage
     * @return StorageContract
     * @throws StorageNotFoundException
     */
    public function getDefaultStorage(): StorageContract
    {
        return $this->getStorage($this->defaultStorageAlias);
    }

    /**
     * return the default storage alias
     * @return string
     */
    public function getDefaultStorageAlias(): string
    {
        return $this->defaultStorageAlias;
    }

    /**
     * return the factory storage
     * @return FactoryStorage
     * @throws StorageNotFoundException
     */
    public function factories(): FactoryStorage
    {
        /**
         * @var FactoryStorage $result
         */
        $result = $this->getStorage(FactoryStorage::STORAGE_KEY);
        return $result;
    }

    /**
     * return the singleton storage
     * @return SingletonStorage
     * @throws StorageNotFoundException
     */
    public function singletons(): SingletonStorage
    {
        /** @var SingletonStorage $result */
        $result = $this->getStorage(SingletonStorage::STORAGE_KEY);
        return $result;
    }

    /**
     * return the value storage
     * @return ValueStorage
     * @throws StorageNotFoundException
     */
    public function bindings(): ValueStorage
    {
        /**
         * @var ValueStorage $result
         */
        $result = $this->getStorage(ValueStorage::STORAGE_KEY);
        return $result;
    }

    /**
     * return the wildcard storage
     * @return WildcardStorage
     * @throws StorageNotFoundException
     */
    public function wildcards(): WildcardStorage
    {
        /**
         * @var WildcardStorage $result
         */
        $result = $this->getStorage(WildcardStorage::STORAGE_KEY);
        return $result;
    }

    /**
     * @param DefinitionContract $definition
     * @param string|null $key
     * @return mixed
     * @throws ContainerException
     */
    public function extract(DefinitionContract $definition, ?string $key = null)
    {
        $extractionParameter = $definition->getExtractionParameter();
        $extractor = $this->getExtractor($extractorClassName = $definition->getExtractorClassName());
        if (!$extractor->isValidExtractionParameter($extractionParameter)) {
            $extractionParameterClassName = get_class($extractionParameter);
            throw new ContainerException("[$extractionParameterClassName] is not a valid parameter 
                for the extractor [$extractorClassName]");
        }
        $result = $extractor->extract($extractionParameter, $this);
        if ($definition->getResolutionCallback() != null) {
            $result = $definition->getResolutionCallback()($result, $this) ?? $result;
        }
        if ($this->globalResolutionCallback != null) {
            $callback = $this->globalResolutionCallback;
            $callback($result, $this);
        }
        if ($key != null && array_key_exists($key, $this->resolutionCallback)) {
            $result = $this->resolutionCallback[$key]($result, $this) ?? $result;
        }
        return $result;
    }

    /**
     * @param DefinitionContract $definition
     * @return mixed
     * @throws ContainerException
     */
    public function interpret(DefinitionContract $definition)
    {
        $this->getExtractionChain()->clear();
        $result = $this->extract($definition);
        $this->getExtractionChain()->clear();
        return $result;
    }

    /**
     * @param DefinitionContract $definition
     * @param string $dependencyAlias
     * @return mixed
     * @throws CircularDependencyException
     * @throws ContainerException
     */
    public function extractDependency(DefinitionContract $definition, string $dependencyAlias)
    {
        $this->chain->append($dependencyAlias);
        return $this->extract($definition, $dependencyAlias);
    }


    /**
     * Return a value store inside the container
     * @param $alias
     * @param string|null $storage
     * @param array $args
     * @param bool $makeIfNotAvailable
     * @return mixed|void
     * @throws CircularDependencyException
     * @throws ContainerException
     * @throws NotFoundException
     * @throws StorageNotFoundException
     */
    public function get($alias, ?string $storage = null, $args = [], $makeIfNotAvailable = true)
    {
        $this->chain->clear();
        $result = $this->getDependency($alias, $storage, $args, $makeIfNotAvailable);
        $this->chain->clear();
        return $result;
    }

    /**
     * Return a value store inside de container
     * @param string $alias
     * @param array $args
     * @param $storage
     * @param bool $makeIfNotAvailable
     * @return mixed|void
     * @throws ContainerException
     * @throws NotFoundException
     * @throws StorageNotFoundException
     * @throws CircularDependencyException
     */
    public function getDependency(string $alias, ?string $storage = null, $args = [], bool $makeIfNotAvailable = true)
    {
        $this->chain->append($alias);
        if (!$this->has($alias, $storage) && $makeIfNotAvailable) {
            return $this->make($alias, is_array($args) ? $args : [$args], false);
        }
        if (!is_null($storage)) {
            return $this->getStorage($storage)->get($alias);
        }
        $storage = $this->getStorageFor($alias);
        return $storage->get($alias);
    }

    /**
     * check if the container can build the object that has the given alias
     * @param $alias
     * @param string|null $storage
     * @return bool
     * @throws StorageNotFoundException
     */
    public function has($alias, ?string $storage = null)
    {
        if (!is_null($storage)) {
            return $this->getStorage($storage)->has($alias);
        }
        if (isset($this->resolvedStorage[$alias])) {
            return true;
        }
        foreach ($this->container as $storage) {
            if ($storage->has($alias)) {
                $this->resolvedStorage[$alias] = $storage->getStorageKey();
                return true;
            }
        }
        return false;
    }

    /**
     * @param $alias
     * @return StorageContract
     * @throws NotFoundException
     */
    public function getStorageFor($alias): StorageContract
    {
        if (array_key_exists($alias, $this->resolvedStorage)) {
            return $this->container[$this->resolvedStorage[$alias]];
        }
        foreach ($this->container as $storage) {
            if ($storage->has($alias)) {
                $this->resolvedStorage[$alias] = $storage->getStorageKey();
                return $storage;
            }
        }
        throw new NotFoundException($alias);
    }


    /**
     * parse the the [storage::key] notation
     *
     * @param $offset
     * @return array
     */
    private function parseOffset($offset)
    {
        $result = explode('::', $offset);
        if (count($result) <= 1) {
            return [
                'value' => $offset
            ];
        }
        $storage = $result[0];
        unset($result[0]);
        $value = implode('::', $result);
        if (!$this->hasStorage($storage)) {
            return [
                'value' => $offset
            ];
        }
        return [
            'storage' => $storage,
            'value' => $value
        ];
    }

    /**
     * @param mixed $offset
     * @return bool
     * @throws StorageNotFoundException
     */
    public function offsetExists($offset)
    {
        $data = $this->parseOffset($offset);
        return $this->has($data['value'], $data['container']);
    }

    /**
     * @param mixed $offset
     * @return mixed|void
     * @throws ContainerException
     * @throws NotFoundException
     * @throws CircularDependencyException
     */
    public function offsetGet($offset)
    {
        $data = $this->parseOffset($offset);
        return $this->get($data['value'], $data['storage'] ?? null, []);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @throws ContainerException
     * @throws StorageNotFoundException
     */
    public function offsetSet($offset, $value)
    {
        $data = $this->parseOffset($offset);
        if (!array_key_exists("storage", $data)) {
            $this->getDefaultStorage()->store($offset, $value);
            return;
        }
        $storage = $data["storage"];
        $key = $data["value"];
        $this->getStorage($storage)->store($key, $value);
    }

    /**
     * @param mixed $offset
     * @throws StorageNotFoundException
     */
    public function offsetUnset($offset)
    {
        $data = $this->parseOffset($offset);
        if (array_key_exists("storage", $data)) {
            $storage = $data["storage"];
            $key = $data["value"];
            $this->getStorage($storage)->remove($key);
            return;
        }
        foreach ($this->getContainer() as $storageKey => $storage) {
            $storage->remove($storageKey);
        }
    }

    /**
     * @param string $alias
     * @param array $params
     * @param bool $clearChain
     * @return mixed
     * @throws ContainerException
     */
    public function make(string $alias, array $params = [], bool $clearChain = true)
    {
        $definition = new BuildObject($alias, $params);
        if ($clearChain) {
            $result = $this->interpret($definition);
        } else {
            $result = $this->extract($definition, $alias);
        }
        unset($definition);
        return $result;
    }


    /**
     * Return all the available extractors
     * @return ExtractorContract[]
     */
    public function getExtractors(): array
    {
        return $this->extractors;
    }

    /**
     * @return ExtractionChain
     */
    public function getExtractionChain(): ExtractionChain
    {
        return $this->chain;
    }

    /**
     * @return DefinitionFactory
     */
    public function as()
    {
        return $this->new();
    }

    /**
     * @return DefinitionFactory
     */
    public function new()
    {
        return new DefinitionFactory();
    }

    public function resolved($key, ?callable $callback = null)
    {
        if ($callback != null && !is_string($key)) {
            throw new InvalidArgumentException("The resolution callback must be a valid callable");
        }
        if ($callback == null) {
            $this->globalResolutionCallback = $key;
        } else {
            $this->resolutionCallback[$key] = $callback;
        }
    }
}
