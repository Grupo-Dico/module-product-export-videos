<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use Bss\CustomProductAttributeExport\Model\Rewrite\CatalogImportExport\Export\Product as BssProductExport;
use Exception;
use LeanCommerce\ProductVideoExport\Api\VideoReaderInterface;
use LeanCommerce\ProductVideoExport\Domain\VideoPresence;
use LeanCommerce\ProductVideoExport\Domain\VideoRepresentation;
use LeanCommerce\ProductVideoExport\Domain\VideoResult;
use Magento\Catalog\Model\Product\LinkTypeProvider;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Catalog\Model\ResourceModel\ProductFactory;
use Magento\CatalogImportExport\Model\Export\Product\Type\Factory as ProductTypeFactory;
use Magento\CatalogImportExport\Model\Export\ProductFilterInterface;
use Magento\CatalogImportExport\Model\Export\RowCustomizerInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock\ItemFactory;
use Magento\Eav\Model\Config;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\ImportExport\Model\Export\ConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Extend native product export before row suppression while preserving Bss behavior.
 */
class ProductExport extends BssProductExport
{
    /** @var ExportExecutionContext */
    private readonly ExportExecutionContext $executionContext;

    /**
     * Preserve the parent's dependency names and pass its optional configuration unchanged.
     *
     * @param TimezoneInterface $localeDate
     * @param Config $config
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param ScopedExportLogger $logger
     * @param CollectionFactory $collectionFactory
     * @param ConfigInterface $exportConfig
     * @param ProductFactory $productFactory
     * @param AttributeSetCollectionFactory $attrSetColFactory
     * @param CategoryCollectionFactory $categoryColFactory
     * @param ItemFactory $itemFactory
     * @param OptionCollectionFactory $optionColFactory
     * @param AttributeCollectionFactory $attributeColFactory
     * @param ProductTypeFactory $_typeFactory
     * @param LinkTypeProvider $linkTypeProvider
     * @param RowCustomizerInterface $rowCustomizer
     * @param VideoReaderInterface $videoReader
     * @param PresenceParameters $presenceParameters
     * @param VideoRepresentation $videoRepresentation
     * @param ScopedVideoWriterFactory $videoWriterFactory
     * @param array $dateAttrCodes
     * @param ProductFilterInterface|null $filter
     * @param StockConfigurationInterface|null $stockConfiguration
     * @param float $estimatedMemoryPerProduct
     */
    public function __construct(
        TimezoneInterface $localeDate,
        Config $config,
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        ScopedExportLogger $logger,
        CollectionFactory $collectionFactory,
        ConfigInterface $exportConfig,
        ProductFactory $productFactory,
        AttributeSetCollectionFactory $attrSetColFactory,
        CategoryCollectionFactory $categoryColFactory,
        ItemFactory $itemFactory,
        OptionCollectionFactory $optionColFactory,
        AttributeCollectionFactory $attributeColFactory,
        ProductTypeFactory $_typeFactory,
        LinkTypeProvider $linkTypeProvider,
        RowCustomizerInterface $rowCustomizer,
        private readonly VideoReaderInterface $videoReader,
        private readonly PresenceParameters $presenceParameters,
        private readonly VideoRepresentation $videoRepresentation,
        private readonly ScopedVideoWriterFactory $videoWriterFactory,
        array $dateAttrCodes = [],
        ?ProductFilterInterface $filter = null,
        ?StockConfigurationInterface $stockConfiguration = null,
        float $estimatedMemoryPerProduct = 500000.0
    ) {
        $this->executionContext = $logger->getContext();
        parent::__construct(
            $localeDate,
            $config,
            $resource,
            $storeManager,
            $logger,
            $collectionFactory,
            $exportConfig,
            $productFactory,
            $attrSetColFactory,
            $categoryColFactory,
            $itemFactory,
            $optionColFactory,
            $attributeColFactory,
            $_typeFactory,
            $linkTypeProvider,
            $rowCustomizer,
            $dateAttrCodes,
            $filter,
            $stockConfiguration,
            $estimatedMemoryPerProduct
        );
    }

    /**
     * Validate the native factory's request before it publishes a queued export.
     *
     * @param array $parameters
     * @return $this
     */
    public function setParameters(array $parameters)
    {
        $this->presenceParameters->resolve($parameters);
        return parent::setParameters($parameters);
    }

    /**
     * Guard only requested exports and always restore the original writer and logger state.
     *
     * @return string
     * @throws LocalizedException
     */
    public function export()
    {
        $presence = $this->presenceParameters->resolve($this->_parameters);
        if ($presence === null) {
            return parent::export();
        }

        $this->executionContext->begin();
        $originalWriter = null;
        $writer = null;
        $error = null;
        $contents = '';
        try {
            $originalWriter = $this->getWriter();
            $writer = $this->videoWriterFactory->create([
                'inner' => $originalWriter,
                'context' => $this->executionContext,
                'presence' => $presence,
            ]);
            $this->setWriter($writer);
            $contents = parent::export();
            $this->executionContext->assertHealthy();
        } catch (Throwable $failure) {
            $this->executionContext->markFatal($failure);
            $error = $this->localizeFailure($failure);
        } finally {
            try {
                if ($writer !== null) {
                    $writer->cleanup();
                } elseif ($originalWriter !== null && is_callable([$originalWriter, 'destruct'])) {
                    $originalWriter->destruct();
                }
                $this->executionContext->assertHealthy();
            } catch (Throwable $failure) {
                $this->executionContext->markFatal($failure);
                $error ??= $this->localizeFailure($failure);
            }
            if ($originalWriter !== null) {
                parent::setWriter($originalWriter);
            }
            $this->executionContext->reset();
        }
        if ($error !== null) {
            throw $error;
        }
        return $contents;
    }

    /**
     * Append to the completed native/Bss/customizer headers without mutating the native cache.
     *
     * @return string[]
     * @throws Throwable
     */
    public function _getHeaderColumns()
    {
        if ($this->presenceParameters->resolve($this->_parameters) === null) {
            return parent::_getHeaderColumns();
        }
        try {
            $this->executionContext->assertHealthy();
            $headers = parent::_getHeaderColumns();
            if (array_intersect(VideoResult::COLUMNS, $headers) !== []) {
                throw new LocalizedException(__('Video export columns conflict with existing export columns.'));
            }
            return array_merge($headers, VideoResult::COLUMNS);
        } catch (Throwable $failure) {
            $this->executionContext->markFatal($failure);
            throw $failure;
        }
    }

    /**
     * Enrich every native raw context before native suppression and final writer selection.
     *
     * @return array
     * @throws Throwable
     */
    protected function collectRawData()
    {
        $presence = $this->presenceParameters->resolve($this->_parameters);
        if ($presence === null) {
            return parent::collectRawData();
        }
        try {
            $this->executionContext->assertHealthy();
            $rawData = parent::collectRawData();
            $this->executionContext->assertHealthy();
            return $this->enrichContexts($rawData, $presence);
        } catch (Throwable $failure) {
            $this->executionContext->markFatal($failure);
            throw $failure;
        }
    }

    /**
     * Read once per store in this batch, retaining IDs rather than loading products again.
     *
     * @param array $rawData
     * @return array<int, array<int, VideoResult>>
     */
    private function readContextResults(array $rawData): array
    {
        $idsByStore = [];
        foreach ($rawData as $productData) {
            foreach ($productData as $storeId => $row) {
                if (!array_key_exists($storeId, $this->_storeIdToCode)
                    || !isset($row['store_id']) || (int)$row['store_id'] !== (int)$storeId
                ) {
                    throw new LocalizedException(__('The product video export contains an invalid store context.'));
                }
                if (array_intersect(VideoResult::COLUMNS, array_keys($row)) !== []) {
                    throw new LocalizedException(__('Video export columns conflict with existing export columns.'));
                }
                $linkId = (int)($row['product_link_id'] ?? 0);
                if ($linkId <= 0) {
                    throw new LocalizedException(__('The product video export contains an invalid product context.'));
                }
                $idsByStore[(int)$storeId][$linkId] = $linkId;
            }
        }

        $results = [];
        foreach ($idsByStore as $storeId => $ids) {
            $results[$storeId] = $this->videoReader->read(array_values($ids), $storeId);
            $missingUrlCount = 0;
            foreach ($ids as $linkId) {
                if (!isset($results[$storeId][$linkId]) || !$results[$storeId][$linkId] instanceof VideoResult) {
                    throw new LocalizedException(__('The product video export returned an incomplete video batch.'));
                }
                $missingUrlCount += $results[$storeId][$linkId]->getMissingUrlCount();
            }
            if ($missingUrlCount > 0) {
                $this->_logger->warning(
                    'The product video export contains enabled videos without URLs.',
                    ['stage' => 'video_batch', 'store_id' => $storeId, 'missing_url_count' => $missingUrlCount]
                );
            }
        }
        return $results;
    }

    /**
     * Apply the domain representation without dropping nonmatching contexts before pagination.
     *
     * @param array $rawData
     * @param VideoPresence $presence
     * @return array
     */
    private function enrichContexts(array $rawData, VideoPresence $presence): array
    {
        $results = $this->readContextResults($rawData);
        foreach ($rawData as $productId => $productData) {
            $base = null;
            if (isset($productData[Store::DEFAULT_STORE_ID])) {
                $baseLinkId = (int)$productData[Store::DEFAULT_STORE_ID]['product_link_id'];
                $base = $results[Store::DEFAULT_STORE_ID][$baseLinkId];
            }
            foreach ($productData as $storeId => $row) {
                $result = $results[(int)$storeId][(int)$row['product_link_id']];
                $block = $this->videoRepresentation->forContext(
                    $result,
                    $base,
                    (int)$storeId === Store::DEFAULT_STORE_ID,
                    $presence
                );
                $rawData[$productId][$storeId] = $row + $block;
            }
        }
        return $rawData;
    }

    /**
     * Keep existing localized errors and avoid exposing raw database or serialization details.
     *
     * @param Throwable $failure
     * @return LocalizedException
     */
    private function localizeFailure(Throwable $failure): LocalizedException
    {
        if ($failure instanceof LocalizedException) {
            return $failure;
        }
        return new LocalizedException(
            __('The product video export could not be completed.'),
            $failure instanceof Exception ? $failure : null
        );
    }
}
