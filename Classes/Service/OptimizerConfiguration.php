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

use Neos\Eel\CompilingEvaluator;
use Neos\Eel\Exception as EelException;
use Neos\Eel\Utility;
use Neos\Flow\Annotations as Flow;

/**
 * A plain object to hold configuration for a specific optimizer.
 */
class OptimizerConfiguration
{
    protected string $binaryPath;

    protected string $argumentsExpression;

    protected string $outFileExtension;

    /**
     * @Flow\Inject(lazy=false)
     * @var CompilingEvaluator
     */
    protected $eelEvaluator;

    /**
     * @param string $binaryPath Path to the binary that should do the optimization of the resource.
     * @param string $argumentsExpression An EEL expression to build the arguments for the transformation
     * @param string $outFileExtension Used to overwrite the outfile extension if needed. (Eg. if the optimizer transforms from JPEG to PNG).
     */
    public function __construct(string $binaryPath, string $argumentsExpression, string $outFileExtension)
    {
        $this->binaryPath = $binaryPath;
        $this->argumentsExpression = $argumentsExpression;
        $this->outFileExtension = $outFileExtension;
    }

    public function getBinaryPath(): string
    {
        return $this->binaryPath;
    }

    public function getArgumentsExpression(): string
    {
        return $this->argumentsExpression;
    }

    public function getOutFileExtension(): string
    {
        return $this->outFileExtension;
    }

    /**
     * @return mixed
     * @throws EelException
     */
    protected function getArguments(array $contextVariables)
    {
        return Utility::evaluateEelExpression($this->argumentsExpression, $this->eelEvaluator, $contextVariables);
    }

    /**
     * The result should be directly callable via "exec" for example.
     *
     * @throws EelException
     */
    public function getPreparedCommandString(array $contextVariables): string
    {
        return $this->getBinaryPath() . ' ' . $this->getArguments($contextVariables);
    }
}
