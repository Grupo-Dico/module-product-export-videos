<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Domain;

use InvalidArgumentException;

/**
 * Nullable scoped values: only null inherits from the base scope.
 */
class VideoMetadata
{
    /**
     * @param int|null $disabled
     * @param int|null $position
     * @param string|null $url
     * @param string|null $title
     */
    public function __construct(
        public readonly ?int $disabled = null,
        public readonly ?int $position = null,
        public readonly ?string $url = null,
        public readonly ?string $title = null
    ) {
        if ($disabled !== null && $disabled !== 0 && $disabled !== 1) {
            throw new InvalidArgumentException('Video disabled must be zero, one or null.');
        }
    }
}
