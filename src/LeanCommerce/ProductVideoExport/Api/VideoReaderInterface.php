<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Api;

use LeanCommerce\ProductVideoExport\Domain\VideoResult;

/**
 * Read effective video data for one export batch and store context.
 *
 * @api
 */
interface VideoReaderInterface
{
    /**
     * Read by product link-field IDs, including an empty result for products without videos.
     *
     * IDs use the product metadata link field, not necessarily the public entity ID.
     * No product, gallery or configuration data is changed by this operation.
     *
     * @param int[] $productIds Positive product link-field IDs in the current batch.
     * @param int $storeId Store context; zero is the base scope.
     * @return array<int, VideoResult>
     * @throws \InvalidArgumentException When an ID or the store context is invalid.
     * @throws \DomainException When the source contains contradictory association data.
     */
    public function read(array $productIds, int $storeId): array;
}
