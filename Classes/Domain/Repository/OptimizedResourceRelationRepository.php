<?php
declare(strict_types=1);

namespace Flownative\ImageOptimizer\Domain\Repository;

/**
 * This file is part of the Flownative.ImageOptimizer package.
 *
 * (c) 2018 Christian Müller, Flownative GmbH
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\ImageOptimizer\Domain\Model\OptimizedResourceRelation;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Repository;

/**
 * @Flow\Scope("singleton")
 * @method OptimizedResourceRelation findByIdentifier(string $identifier)
 */
class OptimizedResourceRelationRepository extends Repository
{
}
