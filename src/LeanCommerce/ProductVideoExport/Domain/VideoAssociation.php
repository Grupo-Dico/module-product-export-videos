<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Domain;

use InvalidArgumentException;

/**
 * One gallery association within a single product and store context.
 */
class VideoAssociation
{
    /**
     * @param int $valueId
     * @param string $mediaType
     * @param bool $globallyDisabled
     * @param VideoMetadata $defaultMetadata
     * @param VideoMetadata|null $storeMetadata
     */
    public function __construct(
        public readonly int $valueId,
        public readonly string $mediaType,
        public readonly bool $globallyDisabled,
        public readonly VideoMetadata $defaultMetadata,
        public readonly ?VideoMetadata $storeMetadata = null
    ) {
        if ($valueId <= 0) {
            throw new InvalidArgumentException('Video association identity must be positive.');
        }
    }
}
