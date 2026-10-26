<?php
declare(strict_types=1);

namespace Magento\Framework {
    class Phrase implements \Stringable
    {
        public function __construct(private string $text, private array $arguments = [])
        {
        }

        public function getText(): string
        {
            return $this->text;
        }

        public function __toString(): string
        {
            $result = $this->text;
            foreach ($this->arguments as $index => $argument) {
                $result = str_replace('%' . ($index + 1), (string) $argument, $result);
            }
            return $result;
        }
    }
}

namespace Magento\Framework\Exception {
    class LocalizedException extends \Exception
    {
        public function __construct(\Magento\Framework\Phrase $phrase, ?\Exception $cause = null, int $code = 0)
        {
            parent::__construct((string) $phrase, $code, $cause);
        }

        public function getRawMessage(): string
        {
            return $this->getMessage();
        }
    }
}

namespace Magento\ImportExport\Model\Export\Adapter {
    abstract class AbstractAdapter
    {
        public function __construct()
        {
            throw new \LogicException('The native filesystem constructor must not be used in unit tests.');
        }

        public function getContentType()
        {
            return 'application/octet-stream';
        }

        public function getFileExtension()
        {
            return '';
        }

        public function setHeaderCols(array $headerColumns)
        {
            return $this;
        }

        public function getContents()
        {
            return '';
        }

        abstract public function writeRow(array $rowData);
    }
}

namespace Magento\Eav\Model\Entity\Attribute\Source {
    abstract class AbstractSource
    {
        abstract public function getAllOptions();
    }
}

namespace {
    function __($text, ...$arguments): \Magento\Framework\Phrase
    {
        return new \Magento\Framework\Phrase((string) $text, $arguments);
    }
}
