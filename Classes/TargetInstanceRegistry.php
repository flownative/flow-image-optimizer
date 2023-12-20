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

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class TargetInstanceRegistry
{
    /**
     * @var ImageOptimizerTarget[]
     */
    protected array $targetInstances = [];

    public function register(ImageOptimizerTarget $target): void
    {
        $this->targetInstances[] = $target;
    }

    /**
     * @return ImageOptimizerTarget[]
     */
    public function getRegisteredInstances(): array
    {
        return $this->targetInstances;
    }
}
