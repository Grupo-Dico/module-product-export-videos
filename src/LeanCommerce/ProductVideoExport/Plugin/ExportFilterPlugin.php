<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Plugin;

use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use LeanCommerce\ProductVideoExport\Model\Source\VideoPresenceOptions;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\Data\Collection;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Html\Select;
use Magento\ImportExport\Block\Adminhtml\Export\Filter;

/**
 * Render only the module's filter without Magento's empty selection placeholder.
 */
class ExportFilterPlugin
{
    /**
     * @param VideoPresenceOptions $options
     * @param PresenceParameters $parameters
     */
    public function __construct(
        private readonly VideoPresenceOptions $options,
        private readonly PresenceParameters $parameters
    ) {
    }

    /**
     * Translate the transient label after the Admin locale is available, before grid cells render.
     *
     * @param Filter $subject
     * @param Collection $collection
     * @return array
     */
    public function beforePrepareCollection(Filter $subject, Collection $collection): array
    {
        $attribute = $collection->getItemByColumnValue('attribute_code', PresenceParameters::KEY);
        if ($attribute instanceof Attribute && $this->isOwnAttribute($attribute)) {
            $attribute->setDefaultFrontendLabel((string)__('Video presence'));
        }
        return [$collection];
    }

    /**
     * Use Magento's select block, preserving all other attribute renderers unchanged.
     *
     * @param Filter $subject
     * @param mixed $result
     * @param mixed $value
     * @param Attribute $row
     * @param DataObject $column
     * @param mixed $isExport
     * @return mixed
     */
    public function afterDecorateFilter(
        Filter $subject,
        mixed $result,
        mixed $value,
        Attribute $row,
        DataObject $column,
        mixed $isExport
    ): mixed {
        if (!$this->isOwnAttribute($row)) {
            return $result;
        }

        $presence = $this->parameters->resolve(['export_filter' => $column->getValues()]);
        $select = $subject->getLayout()->createBlock(
            Select::class,
            '',
            ['data' => [
                'name' => $subject->getFilterElementName(PresenceParameters::KEY),
                'id' => $subject->getFilterElementId(PresenceParameters::KEY),
                'class' => 'admin__control-select select select-export-filter',
            ]]
        );
        return $select->setOptions($this->options->getAllOptions())
            ->setValue($presence?->getValue() ?? 'all')
            ->getHtml();
    }

    /**
     * Limit presentation changes to the transient filter registered by this module.
     *
     * @param Attribute $attribute
     * @return bool
     */
    private function isOwnAttribute(Attribute $attribute): bool
    {
        return $attribute->getAttributeCode() === PresenceParameters::KEY
            && $attribute->getSourceModel() === VideoPresenceOptions::class;
    }
}
