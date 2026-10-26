<?php
declare(strict_types=1);

namespace Magento\Framework {
    class Filesystem
    {
        public function __construct(public object $directory)
        {
        }

        public function getDirectoryWrite($code)
        {
            return $this->directory;
        }
    }
}

namespace Magento\Framework\App\Filesystem {
    class DirectoryList
    {
        public const VAR_IMPORT_EXPORT = 'var_import_export';
    }
}

namespace Magento\Framework\Math {
    class Random
    {
        public function getUniqueHash($prefix = '')
        {
            throw new \LogicException('Random generator must be mocked.');
        }
    }
}

namespace Magento\Framework\Filesystem\Directory {
    interface WriteInterface
    {
    }
}

namespace Magento\Framework\Filesystem\File {
    interface WriteInterface
    {
    }
}

namespace Magento\Framework\Serialize {
    interface SerializerInterface
    {
        public function serialize($data);
        public function unserialize($string);
    }
}

namespace Magento\ImportExport\Model {
    class Export
    {
        public function __construct(private array $data = [])
        {
        }

        public function getData($key = null)
        {
            return $key === null ? $this->data : ($this->data[$key] ?? null);
        }
    }
}

namespace Magento\ImportExport\Model\Export {
    class Consumer
    {
    }
}

namespace Magento\ImportExport\Api\Data {
    interface LocalizedExportInfoInterface
    {
        public function getEntity();
        public function getFileFormat();
        public function getExportFilter();
    }
}

namespace Magento\ImportExport\Model\Export\Adapter {
    class Csv extends AbstractAdapter
    {
        protected $_destination;
        protected $_directoryHandle;
        protected $_fileHandler;

        public function __construct($filesystem, $destination = null, $destinationDirectoryCode = 'var_import_export')
        {
            $this->_destination = $destination ?? 'importexport_unit';
            $this->_directoryHandle = $filesystem->getDirectoryWrite($destinationDirectoryCode);
            $this->_fileHandler = $this->_directoryHandle->openFile($this->_destination, 'w');
        }

        public function getContents()
        {
            if ($this->_fileHandler !== null) {
                $this->_fileHandler->close();
                $this->_fileHandler = null;
            }
            return $this->_directoryHandle->readFile($this->_destination);
        }

        public function destruct()
        {
            if ($this->_fileHandler !== null) {
                $this->_fileHandler->close();
                $this->_fileHandler = null;
                if (!str_contains($this->_destination, '/')) {
                    $this->_directoryHandle->delete($this->_destination);
                }
            }
        }

        public function writeRow(array $rowData)
        {
            return $this;
        }
    }

    class Factory
    {
        public function create($className, array $arguments = [])
        {
            throw new \LogicException('Stub factory must be mocked.');
        }
    }
}

namespace LeanCommerce\ProductVideoExport\Model {
    class OwnedCsvFactory
    {
        public function create(array $data = []): OwnedCsv
        {
            throw new \LogicException('Generated factory must be mocked.');
        }
    }
}
