<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Domain;

/**
 * Represents the complete video block while preserving native row suppression.
 */
class VideoRepresentation
{
    /**
     * Materialize every filtered context; the writer selects accepted contexts later.
     *
     * @param VideoResult $result
     * @param VideoResult|null $base
     * @param bool $isBase
     * @param VideoPresence $mode
     * @return array<string, int|string>
     */
    public function forContext(VideoResult $result, ?VideoResult $base, bool $isBase, VideoPresence $mode): array
    {
        if ($mode->getValue() === 'all' && !$isBase && $base !== null && $result->toColumns() === $base->toColumns()) {
            return [];
        }
        return $result->toColumns();
    }
}
