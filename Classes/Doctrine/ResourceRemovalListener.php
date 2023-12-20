<?php
declare(strict_types=1);

namespace Flownative\ImageOptimizer\Doctrine;

use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\ORMException;
use Flownative\ImageOptimizer\Domain\Model\OptimizedResourceRelation;
use Flownative\ImageOptimizer\Domain\Repository\OptimizedResourceRelationRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\ResourceManagement\PersistentResource;

/**
 * @Flow\Scope("singleton")
 */
class ResourceRemovalListener
{
    /**
     * @Flow\Inject
     * @var OptimizedResourceRelationRepository
     */
    protected $optimizedResourceRelationRepository;

    protected array $seenOptimizedResourceRelations = [];

    /**
     * @throws IllegalObjectTypeException
     * @throws ORMException
     */
    public function preRemove(LifecycleEventArgs $event): void
    {
        $entity = $event->getEntity();
        if (!$entity instanceof PersistentResource) {
            return;
        }

        $optimizedResourceRelation = $this->optimizedResourceRelationRepository->findOneByOptimizedResource($entity);
        if ($optimizedResourceRelation instanceof OptimizedResourceRelation) {
            if (array_key_exists($optimizedResourceRelation->getOriginalResourceIdentificationHash(), $this->seenOptimizedResourceRelations)) {
                return;
            }
            $this->seenOptimizedResourceRelations[$optimizedResourceRelation->getOriginalResourceIdentificationHash()] = true;
            $this->optimizedResourceRelationRepository->remove($optimizedResourceRelation);
        }
    }
}
