<?php
declare(strict_types=1);

namespace Flownative\ImageOptimizer;

/**
 * This file is part of the Flownative.ImageOptimizer package.
 *
 * (c) 2018 Christian Müller, Flownative GmbH
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\EntityManagerInterface;
use Flownative\ImageOptimizer\Domain\Model\OptimizedResourceRelation;
use Flownative\ImageOptimizer\Domain\Repository\OptimizedResourceRelationRepository;
use Flownative\ImageOptimizer\Service\OptimizerConfiguration;
use Flownative\ImageOptimizer\Service\OptimizerService;
use Neos\Eel\Exception as EelException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\CollectionInterface;
use Neos\Flow\ResourceManagement\Exception as ResourceManagementException;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\ResourceMetaDataInterface;
use Neos\Flow\ResourceManagement\Storage\StorageObject;
use Neos\Flow\ResourceManagement\Target\Exception as TargetException;
use Neos\Flow\ResourceManagement\Target\TargetInterface;

/**
 *
 */
class ImageOptimizerTarget implements TargetInterface
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var array
     */
    protected $options;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $doctrinePersistence;

    /**
     * @Flow\Inject
     * @var OptimizerService
     */
    protected $optimizerService;

    /**
     * @Flow\Inject
     * @var OptimizedResourceRelationRepository
     */
    protected $optimizedResourceRelationRepository;

    /**
     * @var TargetInterface
     */
    protected $realTarget;

    /**
     * @var OptimizerConfiguration[]
     */
    protected $optimizerConfigurations = [];

    /**
     * @var object[]
     */
    protected $unpersistedObjects = [];

    /**
     * @var array
     */
    protected $boundForRemoval = [];

    /**
     * @param TargetInstanceRegistry $targetInstanceRegistry
     * @return void
     */
    public function injectTargetInstanceRegistry(TargetInstanceRegistry $targetInstanceRegistry): void
    {
        $targetInstanceRegistry->register($this);
    }

    /**
     * Constructor
     *
     * @param string $name Name of this target instance, according to the resource settings
     * @param array $options Options for this target
     */
    public function __construct($name, array $options = [])
    {
        $this->name = $name;
        $this->options = $options;

        $this->optimizerConfigurations = $this->prepareOptimizerConfigurations($options['mediaTypes']);
        $this->realTarget = new $options['targetClass']($name, $options['targetOptions']);
    }

    /**
     * Publishes the whole collection to this target
     *
     * @param CollectionInterface $collection The collection to publish
     * @param callable $callback Function called after each resource publishing
     * @return void
     * @throws EelException
     * @throws ResourceManagementException
     */
    public function publishCollection(CollectionInterface $collection, callable $callback = null)
    {
        /** @var StorageObject $resource */
        foreach ($collection->getObjects($callback) as $resource) {
            if ($this->needsToBeOptimized($resource)) {
                try {
                    $optimizedResource = $this->optimizerService->optimize($resource->getStream(), $resource->getFilename(), $this->options['optimizedCollection'], $this->getOptimizerConfigurationForMediaType($resource->getMediaType()));
                    $this->prepareForPersistence($optimizedResource, $resource->getSha1(), $resource->getFilename());
                } catch (\Exception $exception) {
                    // Ignore the error and use the original resource
                }
            }
        }
        $this->realTarget->publishCollection($collection, $callback);
    }

    /**
     * Publishes the given persistent resource from the given storage
     *
     * @param PersistentResource $resource The resource to publish
     * @param CollectionInterface $collection The collection the given resource belongs to
     * @return void
     * @throws EelException
     * @throws ResourceManagementException
     * @throws TargetException
     */
    public function publishResource(PersistentResource $resource, CollectionInterface $collection)
    {
        if ($this->needsToBeOptimized($resource)) {
            try {
                $optimizedResource = $this->optimizerService->optimize($resource->getStream(), $resource->getFilename(), $this->options['optimizedCollection'], $this->getOptimizerConfigurationForMediaType($resource->getMediaType()));
                $this->prepareForPersistence($optimizedResource, $resource->getSha1(), $resource->getFilename());
            } catch (\Exception $exception) {
                // Ignore the error and use the original resource
            }
        }
        $this->realTarget->publishResource($resource, $collection);
    }

    /**
     * @param PersistentResource $optimizedResource
     * @param string $sha1
     * @param string $filename
     * @return void
     */
    protected function prepareForPersistence(PersistentResource $optimizedResource, string $sha1, string $filename): void
    {
        $this->doctrinePersistence->detach($optimizedResource);
        $optimizedResourceRelation = OptimizedResourceRelation::createFromResourceSha1AndFilename($sha1, $filename, $optimizedResource);
        $this->unpersistedObjects[$optimizedResource->getSha1()] = $optimizedResource;
        $this->unpersistedObjects[$optimizedResourceRelation->getOriginalResourceIdentificationHash()] = $optimizedResourceRelation;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param PersistentResource $resource
     * @return void
     */
    public function unpublishResource(PersistentResource $resource)
    {
        if ($this->needsToBeOptimized($resource)) {
            $optimizedResourceRelation = $this->getOptimizedBySha1AndFilename($resource->getSha1(), $resource->getFilename());
            assert($optimizedResourceRelation !== null);
            $this->boundForRemoval[] = $optimizedResourceRelation;
            $this->boundForRemoval[] = $optimizedResourceRelation->getOptimizedResource();
        }

        $this->realTarget->unpublishResource($resource);
    }

    /**
     * @param string $relativePathAndFilename
     * @return string
     */
    public function getPublicStaticResourceUri($relativePathAndFilename)
    {
        $this->realTarget->getPublicStaticResourceUri($relativePathAndFilename);
    }

    /**
     * @param PersistentResource $resource
     * @return string
     * @throws TargetException
     */
    public function getPublicPersistentResourceUri(PersistentResource $resource)
    {
        if ($this->shouldBeOptimized($resource->getMediaType())
            && $this->isOptimized($resource->getSha1(), $resource->getFilename())
        ) {
            $optimizedResourceRelation = $this->getOptimizedBySha1AndFilename($resource->getSha1(), $resource->getFilename());
            assert($optimizedResourceRelation !== null);
            return $this->resourceManager->getPublicPersistentResourceUri($optimizedResourceRelation->getOptimizedResource());
        }

        return $this->realTarget->getPublicPersistentResourceUri($resource);
    }

    /**
     * @param ResourceMetaDataInterface $resource
     * @return bool
     */
    protected function needsToBeOptimized(ResourceMetaDataInterface $resource): bool
    {
        return $this->shouldBeOptimized($resource->getMediaType())
            && $resource->getStream() !== false
            && !$this->isOptimized($resource->getSha1(), $resource->getFilename());
    }

    /**
     * @param string $mediaType
     * @return bool
     */
    protected function shouldBeOptimized(string $mediaType): bool
    {
        return ($this->getOptimizerConfigurationForMediaType($mediaType) !== null);
    }

    /**
     * @param string $mediaType
     * @return OptimizerConfiguration|null
     */
    protected function getOptimizerConfigurationForMediaType(string $mediaType): ?OptimizerConfiguration
    {
        return $this->optimizerConfigurations[$mediaType] ?? null;
    }

    /**
     * @param string $sha1
     * @param string $filename
     * @return bool
     */
    protected function isOptimized(string $sha1, string $filename): bool
    {
        $optimized = $this->getOptimizedBySha1AndFilename($sha1, $filename);

        return $optimized !== null;
    }

    /**
     * @param string $sha1
     * @param string $filename
     * @return OptimizedResourceRelation|null
     */
    protected function getOptimizedBySha1AndFilename(string $sha1, string $filename): ?OptimizedResourceRelation
    {
        $originalResourceIdentificationHash = OptimizedResourceRelation::createOriginalResourceIdentificationHash($sha1, $filename);

        return $this->optimizedResourceRelationRepository->findByIdentifier($originalResourceIdentificationHash);
    }

    /**
     * @param array $rawOptions
     * @return array
     */
    protected function prepareOptimizerConfigurations(array $rawOptions): array
    {
        $result = [];
        foreach ($rawOptions as $mediaType => $options) {
            if ($options === null) {
                continue;
            }
            $result[$mediaType] = new OptimizerConfiguration($options['binaryPath'], $options['arguments'], $options['outfileExtension'] ?? '');
        }

        return $result;
    }

    /**
     * @return void
     */
    public function persist(): void
    {
        foreach (array_values($this->unpersistedObjects) as $unpersistedObject) {
            $this->doctrinePersistence->persist($unpersistedObject);
        }

        foreach (array_values($this->boundForRemoval) as $object) {
            $this->doctrinePersistence->remove($object);
        }

        $this->unpersistedObjects = [];
        $this->boundForRemoval = [];
        $this->doctrinePersistence->flush();
    }
}
