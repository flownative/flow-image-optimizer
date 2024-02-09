<?php
declare(strict_types=1);

namespace Flownative\ImageOptimizer\Domain\Model;

/**
 * This file is part of the Flownative.ImageOptimizer package.
 *
 * (c) 2018 Christian Müller, Flownative GmbH
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ResourceManagement\PersistentResource;

/**
 * @Flow\Entity
 */
class OptimizedResourceRelation
{
    /**
     * @Flow\Identity
     * @ORM\Id
     * @var string
     */
    protected $originalResourceIdentificationHash;

    /**
     * @var PersistentResource
     * @ORM\OneToOne(cascade={"PERSIST", "REMOVE"}, orphanRemoval=true)
     */
    protected $optimizedResource;

    public function __construct(string $originalResourceIdentificationHash, PersistentResource $optimizedResource)
    {
        $this->originalResourceIdentificationHash = $originalResourceIdentificationHash;
        $this->optimizedResource = $optimizedResource;
    }

    public static function createFromResourceSha1AndFilename(string $sha1, string $filename, PersistentResource $optimizedResource): OptimizedResourceRelation
    {
        return new static(static::createOriginalResourceIdentificationHash($sha1, $filename), $optimizedResource);
    }

    public static function createOriginalResourceIdentificationHash(string $sha1, string $filename): string
    {
        return hash('sha256', $sha1 . $filename);
    }

    public function getOriginalResourceIdentificationHash(): string
    {
        return $this->originalResourceIdentificationHash;
    }

    public function getOptimizedResource(): PersistentResource
    {
        return $this->optimizedResource;
    }
}
