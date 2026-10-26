<?php
declare(strict_types=1);

namespace Magento\Framework {
    class DataObject
    {
        public function __construct(protected array $data = [])
        {
        }

        public function getData(?string $key = null): mixed
        {
            return $key === null ? $this->data : ($this->data[$key] ?? null);
        }
    }
}

namespace Magento\Eav\Model\Entity {
    class Attribute extends \Magento\Framework\DataObject
    {
        public function getAttributeCode(): ?string
        {
            return $this->getData('attribute_code');
        }

        public function getSourceModel(): ?string
        {
            return $this->getData('source_model');
        }

        public function setDefaultFrontendLabel(string $label): self
        {
            $this->data['frontend_label'] = $label;
            return $this;
        }
    }
}

namespace Magento\Framework\Data {
    class Collection
    {
        public function __construct(private array $items = [])
        {
        }

        public function getItemByColumnValue(string $column, mixed $value): ?\Magento\Framework\DataObject
        {
            foreach ($this->items as $item) {
                if ($item->getData($column) === $value) {
                    return $item;
                }
            }
            return null;
        }
    }
}

namespace Magento\ImportExport\Block\Adminhtml\Export {
    class Filter
    {
    }
}
