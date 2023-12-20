<?php
declare(strict_types=1);

namespace Flownative\ImageOptimizer\Service;

/**
 * This file is part of the Flownative.ImageOptimizer package.
 *
 * (c) 2018 Christian Müller, Flownative GmbH
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Eel\Exception as EelException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ResourceManagement\Exception as ResourceManagementException;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Utility\Algorithms;
use Neos\Flow\Utility\Environment;
use Neos\Flow\Utility\Exception;
use Neos\Utility\Exception\FilesException;
use Neos\Utility\Files;

/**
 * Service to optimize file streams and return optimized Resources
 *
 * @Flow\Scope("singleton")
 */
class OptimizerService
{
    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var Environment
     */
    protected $environment;

    /**
     * Tries to optimize the binary content provided via $stream, using the given $filename and $optimizationConfiguration.
     * Returning a PersistentResource stored in the given $resourceCollectionName
     *
     * @param resource $stream file/binary stream resource handler (fopen)
     * @param string $filename
     * @param string $resourceCollectionName
     * @param OptimizerConfiguration $optimizationConfiguration
     * @return PersistentResource
     * @throws ResourceManagementException
     * @throws EelException
     * @throws \RuntimeException
     */
    public function optimize($stream, string $filename, string $resourceCollectionName, OptimizerConfiguration $optimizationConfiguration): PersistentResource
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $outFileExtension = $optimizationConfiguration->getOutFileExtension() !== '' ? $optimizationConfiguration->getOutFileExtension() : $extension;
        $randomString = Algorithms::generateRandomString(13);
        $originalTemporaryPathAndFilename = $this->generateTemporaryPathAndFilename('OptimizerOriginal-' . $randomString, $extension);
        $optimizedTemporaryPathAndFilename = $this->generateTemporaryPathAndFilename('OptimizerOptimized-' . $randomString, $outFileExtension);

        $originalTemporaryStream = fopen($originalTemporaryPathAndFilename, 'wb+');
        stream_copy_to_stream($stream, $originalTemporaryStream);
        fclose($originalTemporaryStream);

        $commandString = $optimizationConfiguration->getPreparedCommandString(['originalPath' => escapeshellarg($originalTemporaryPathAndFilename), 'optimizedPath' => escapeshellarg($optimizedTemporaryPathAndFilename)]);
        exec($commandString, $output, $result);

        if (!file_exists($optimizedTemporaryPathAndFilename)) {
            Files::unlink($originalTemporaryPathAndFilename);
            throw new \RuntimeException(sprintf("Optimization not successful for command: %s\nExit status code: %s\nOutput:\n%s", $commandString, $result, implode(chr(10), $output)), 1601633863);
        }

        $bestResultPathAndFilename = (filesize($originalTemporaryPathAndFilename) <= filesize($optimizedTemporaryPathAndFilename)) ? $originalTemporaryPathAndFilename : $optimizedTemporaryPathAndFilename;
        $resource = $this->resourceManager->importResource($bestResultPathAndFilename, $resourceCollectionName);
        $resource->setFilename($filename);
        Files::unlink($originalTemporaryPathAndFilename);
        Files::unlink($optimizedTemporaryPathAndFilename);

        return $resource;
    }

    /**
     * @throws Exception
     * @throws FilesException
     */
    protected function generateTemporaryPathAndFilename(string $filename, string $extension): string
    {
        return ($this->environment->getPathToTemporaryDirectory() . $filename . '.' . $extension);
    }
}
