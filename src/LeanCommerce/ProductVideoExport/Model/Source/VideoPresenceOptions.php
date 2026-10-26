<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

/**
 * Options for the transient catalog export filter; no persisted EAV attribute.
 */
class VideoPresenceOptions extends AbstractSource
{
    /**
     * @inheritdoc
     */
    public function getAllOptions(): array
    {
        return [
            ['value' => 'all', 'label' => __('All')],
            ['value' => 'with', 'label' => __('With video')],
            ['value' => 'without', 'label' => __('Without video')],
        ];
    }
}
