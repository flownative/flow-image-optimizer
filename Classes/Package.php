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

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\SignalSlot\Dispatcher;

/**
 * Package object for Flow package management
 */
class Package extends \Neos\Flow\Package\Package
{
    /**
     * Persist changes to our optimizer target.
     *
     * Resources are published using postPersist, so any new resources that are created in this target are
     * created after flushing changes. Thus we need to persist them here on our own.
     *
     * @return void
     * @throws \Neos\Flow\Exception
     */
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getEarlyInstance(Dispatcher::class);
        $dispatcher->connect(Bootstrap::class, 'finishedRuntimeRun', function () use ($bootstrap) {
            /** @var TargetInstanceRegistry $registry */
            $registry = $bootstrap->getObjectManager()->get(TargetInstanceRegistry::class);
            foreach ($registry->getRegisteredInstances() as $targetInstance) {
                $targetInstance->persist();
            }
        });
    }
}
