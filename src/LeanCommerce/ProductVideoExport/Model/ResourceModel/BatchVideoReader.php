<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model\ResourceModel;

use DomainException;
use InvalidArgumentException;
use LeanCommerce\ProductVideoExport\Api\VideoReaderInterface;
use LeanCommerce\ProductVideoExport\Domain\VideoAggregator;
use LeanCommerce\ProductVideoExport\Domain\VideoAssociation;
use LeanCommerce\ProductVideoExport\Domain\VideoMetadata;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\Config;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Compose Magento's read-only batch query, including its ProductVideo plugin.
 */
class BatchVideoReader implements VideoReaderInterface
{
    /**
     * @param Gallery $gallery
     * @param Config $eavConfig
     * @param MetadataPool $metadataPool
     * @param VideoAggregator $aggregator
     */
    public function __construct(
        private readonly Gallery $gallery,
        private readonly Config $eavConfig,
        private readonly MetadataPool $metadataPool,
        private readonly VideoAggregator $aggregator
    ) {
    }

    /**
     * @inheritdoc
     */
    public function read(array $productIds, int $storeId): array
    {
        $associations = $this->initializeBatch($productIds, $storeId);
        if ($associations === []) {
            return [];
        }

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $attributeId = (int)$this->eavConfig->getAttribute(Product::ENTITY, 'media_gallery')->getAttributeId();
        if ($attributeId <= 0) {
            throw new DomainException('The product media_gallery attribute is unavailable.');
        }

        $select = $this->gallery->createBatchBaseSelect($storeId, $attributeId);
        $this->retainRequiredColumns($select, $linkField);
        $connection = $this->gallery->getConnection();
        $mainAlias = $this->gallery->getMainTableAlias();
        $productColumn = $connection->quoteIdentifier('entity.' . $linkField);
        $select->columns(['global_disabled' => 'disabled'], $mainAlias)
            ->where($productColumn . ' IN (?)', array_keys($associations))
            ->where($connection->quoteIdentifier($mainAlias . '.media_type') . ' = ?', 'external-video')
            ->reset(Select::ORDER)
            ->order('entity.' . $linkField . ' ASC')
            ->order($mainAlias . '.value_id ASC');

        foreach ($connection->fetchAll($select) as $row) {
            $productId = (int)$row[$linkField];
            if (!array_key_exists($productId, $associations)) {
                throw new DomainException('The video query returned a product outside its batch.');
            }
            $associations[$productId][] = new VideoAssociation(
                (int)$row['value_id'],
                (string)$row['media_type'],
                (int)$row['global_disabled'] !== 0,
                new VideoMetadata(
                    $row['disabled'] === null ? null : (int)$row['disabled'],
                    $row['position'] === null ? null : (int)$row['position'],
                    $row['video_url'] === null ? null : (string)$row['video_url'],
                    $row['video_title'] === null ? null : (string)$row['video_title']
                )
            );
        }

        $results = [];
        foreach ($associations as $productId => $videos) {
            $results[$productId] = $this->aggregator->aggregate($videos);
        }
        return $results;
    }

    /**
     * Validate the internal caller's context and deduplicate batch IDs without querying.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int, VideoAssociation[]>
     */
    private function initializeBatch(array $productIds, int $storeId): array
    {
        if ($storeId < 0) {
            throw new InvalidArgumentException('A video store context cannot be negative.');
        }
        $associations = [];
        foreach ($productIds as $productId) {
            if (!is_int($productId) || $productId <= 0) {
                throw new InvalidArgumentException('Video batch IDs must be positive integers.');
            }
            $associations[$productId] = [];
        }
        return $associations;
    }

    /**
     * Keep native projected expressions so per-field inheritance remains owned by Magento.
     *
     * The original query resolves all table names and constrains globally disabled entries.
     * Effective disabled selection, association conflicts and output order belong to the domain.
     * Missing plugin columns fail explicitly instead of silently exporting empty metadata.
     *
     * @param Select $select
     * @param string $linkField
     * @return void
     */
    private function retainRequiredColumns(Select $select, string $linkField): void
    {
        $required = array_fill_keys(
            [$linkField, 'value_id', 'media_type', 'disabled', 'position', 'video_url', 'video_title'],
            true
        );
        $columns = [];
        foreach ($select->getPart(Select::COLUMNS) as [$table, $expression, $alias]) {
            $name = $alias ?? $expression;
            if (!is_string($name) || !isset($required[$name])) {
                continue;
            }
            if (array_key_exists($name, $columns)) {
                throw new DomainException('The native video query contains ambiguous projected columns.');
            }
            $columns[$name] = [$table, $expression];
        }
        if (array_diff_key($required, $columns) !== []) {
            throw new DomainException('The native video query is missing required projected columns.');
        }

        $select->reset(Select::COLUMNS);
        foreach ($columns as $name => [$table, $expression]) {
            $select->columns([$name => $expression], $table);
        }
    }
}
