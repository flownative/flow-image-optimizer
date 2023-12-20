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
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\CollectionInterface;
use Neos\Flow\ResourceManagement\Exception as ResourceManagementException;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\ResourceMetaDataInterface;
use Neos\Flow\ResourceManagement\Target\Exception as TargetException;
use Neos\Flow\ResourceManagement\Target\TargetInterface;
use Psr\Log\LoggerInterface;

/**
 *
 */
class ImageOptimizerTarget implements TargetInterface
{
    protected string $name;

    protected array $options;

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
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    protected TargetInterface $realTarget;

    /**
     * @var OptimizerConfiguration[]
     */
    protected array $optimizerConfigurations = [];

    protected array $unpersistedObjects = [];

    protected array $boundForRemoval = [];

    public function injectTargetInstanceRegistry(TargetInstanceRegistry $targetInstanceRegistry): void
    {
        $targetInstanceRegistry->register($this);
    }

    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        $this->options = $options;

        $this->optimizerConfigurations = $this->prepareOptimizerConfigurations($options['mediaTypes']);
        $this->realTarget = new $options['targetClass']($name, $options['targetOptions']);
    }

    /**
     * Publishes the whole collection to this target
     *
     * @return void
     */
    public function publishCollection(CollectionInterface $collection, callable $callback = null)
    {
        foreach ($collection->getObjects($callback) as $resource) {
            $this->optimizeIfNeeded($resource);
        }
        $this->realTarget->publishCollection($collection, $callback);
    }

    /**
     * Publishes the given persistent resource from the given storage
     *
     * @param PersistentResource $resource The resource to publish
     * @param CollectionInterface $collection The collection the given resource belongs to
     * @return void
     * @throws ResourceManagementException
     * @throws TargetException
     */
    public function publishResource(PersistentResource $resource, CollectionInterface $collection)
    {
        $this->optimizeIfNeeded($resource);
        $this->realTarget->publishResource($resource, $collection);
    }

    private function optimizeIfNeeded(ResourceMetaDataInterface $resource): void
    {
        if ($this->needsToBeOptimized($resource)) {
            try {
                $optimizedResource = $this->optimizerService->optimize($resource->getStream(), $resource->getFilename(), $this->options['optimizedCollection'], $this->getOptimizerConfigurationForMediaType($resource->getMediaType()));
                $this->prepareForPersistence($optimizedResource, $resource->getSha1(), $resource->getFilename());
            } catch (\Exception $exception) {
                // Ignore the error and use the original resource
                $this->logger->warning(sprintf('Optimization of resource "%s" failed, using original, error: %s', $resource->getFilename(), $exception->getMessage()), LogEnvironment::fromMethodName(__METHOD__));
            }
        }
    }

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
        return $this->realTarget->getPublicStaticResourceUri($relativePathAndFilename);
    }

    /**
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

    protected function needsToBeOptimized(ResourceMetaDataInterface $resource): bool
    {
        return $this->shouldBeOptimized($resource->getMediaType())
            && $resource->getStream() !== false
            && !$this->isOptimized($resource->getSha1(), $resource->getFilename());
    }

    protected function shouldBeOptimized(string $mediaType): bool
    {
        return ($this->getOptimizerConfigurationForMediaType($mediaType) !== null);
    }

    protected function getOptimizerConfigurationForMediaType(string $mediaType): ?OptimizerConfiguration
    {
        return $this->optimizerConfigurations[$mediaType] ?? null;
    }

    protected function isOptimized(string $sha1, string $filename): bool
    {
        $optimized = $this->getOptimizedBySha1AndFilename($sha1, $filename);

        return $optimized !== null;
    }

    protected function getOptimizedBySha1AndFilename(string $sha1, string $filename): ?OptimizedResourceRelation
    {
        $originalResourceIdentificationHash = OptimizedResourceRelation::createOriginalResourceIdentificationHash($sha1, $filename);

        return $this->optimizedResourceRelationRepository->findByIdentifier($originalResourceIdentificationHash);
    }

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

    public function persist(): void
    {
        foreach ($this->unpersistedObjects as $unpersistedObject) {
            $this->doctrinePersistence->persist($unpersistedObject);
        }

        foreach ($this->boundForRemoval as $object) {
            $this->doctrinePersistence->remove($object);
        }

        $this->unpersistedObjects = [];
        $this->boundForRemoval = [];
        $this->doctrinePersistence->flush();
    }
}
