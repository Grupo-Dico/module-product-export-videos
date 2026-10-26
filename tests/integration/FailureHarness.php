<?php
/** Test-only failure seams around native algorithms; never included in the module package. */
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Integration\Failures;

use Bss\CustomProductAttributeExport\Model\Rewrite\CatalogImportExport\Export\Product as BssProductExport;
use LeanCommerce\ProductVideoExport\Model\ExportExecutionContext;
use LeanCommerce\ProductVideoExport\Model\ProductExport;
use LeanCommerce\ProductVideoExport\Model\ScopedExportLogger;
use Magento\CatalogImportExport\Model\Export\RowCustomizerInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\ObjectManagerInterface;
use Magento\ImportExport\Api\Data\ExportInfoInterface;
use Magento\ImportExport\Api\ExportManagementInterface;
use Magento\ImportExport\Model\Export\Entity\Factory;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

class FailureState
{
    public string $variant = '';
    public string $flavor = '';
    public string $absoluteTmp = '';
    public int $faults = 0;
    public int $loads = 0;
    public int $stockCalls = 0;
    public int $customizerCalls = 0;
    public int $csvWrites = 0;
    public int $writesBeforeFault = 0;
    public int $faultBatchProducts = 0;
    public array $logs = [];
    public array $manager = [];
    public array $entities = [];

    public function fault(): void
    {
        ++$this->faults;
        $this->writesBeforeFault = $this->csvWrites;
    }

    public function comparableMessage(string $message): string
    {
        // Each trial owns a different path. Native filesystem errors include that path.
        // Normalize only this exact test artifact; preserve every other character.
        return str_replace($this->absoluteTmp, '<owned-temporary>', $message);
    }
}

trait FailureProductHooks
{
    private FailureState $failureState;
    private array $firstBatch = [];

    public function setFailureState(FailureState $state): void
    {
        $this->failureState = $state;
    }

    protected function getItemsPerPage()
    {
        $this->_itemsPerPage = 1;
        return parent::getItemsPerPage();
    }

    protected function loadCollection(): array
    {
        ++$this->failureState->loads;
        if ($this->failureState->variant === 'before_raw' && $this->failureState->loads === 1) {
            $this->failureState->fault();
            throw new \Error('LCVX: injected before raw');
        }
        if ($this->failureState->variant === 'cursor' && $this->failureState->loads === 2) {
            $this->failureState->fault();
            return $this->firstBatch;
        }
        $items = parent::loadCollection();
        if ($this->failureState->loads === 1) {
            $this->firstBatch = $items;
        }
        return $items;
    }

    protected function prepareCatalogInventory(array $productIds)
    {
        ++$this->failureState->stockCalls;
        if ($this->failureState->variant === 'stock' && $this->failureState->stockCalls === 2) {
            $this->failureState->fault();
            $this->failureState->faultBatchProducts = count($productIds);
            throw new \RuntimeException('LCVX: injected stock failure');
        }
        return parent::prepareCatalogInventory($productIds);
    }
}

class FailureVideoExport extends ProductExport
{
    use FailureProductHooks;
}

class FailureBssExport extends BssProductExport
{
    use FailureProductHooks;
}

class FailureRowCustomizer implements RowCustomizerInterface
{
    public function __construct(private RowCustomizerInterface $native, private FailureState $state)
    {
    }

    public function prepareData($collection, $productIds)
    {
        ++$this->state->customizerCalls;
        if ($this->state->variant === 'customizer' && $this->state->customizerCalls === 2) {
            $this->state->fault();
            $this->state->faultBatchProducts = count($productIds);
            throw new \RuntimeException('LCVX: injected customizer failure');
        }
        return $this->native->prepareData($collection, $productIds);
    }

    public function addHeaderColumns($columns)
    {
        $columns = $this->native->addHeaderColumns($columns);
        if ($this->state->variant === 'header') {
            $this->state->fault();
            $columns[] = 'has_video';
        }
        return $columns;
    }

    public function addData($dataRow, $productId)
    {
        return $this->native->addData($dataRow, $productId);
    }

    public function getAdditionalRowsCount($additionalRowsCount, $productId)
    {
        return $this->native->getAdditionalRowsCount($additionalRowsCount, $productId);
    }
}

class RecordingLogger extends AbstractLogger
{
    public function __construct(private FailureState $state)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $text = $message instanceof Throwable ? $message->getMessage() : (string)$message;
        $this->state->logs[] = [
            'level' => $level,
            'exception' => $message instanceof Throwable ? get_class($message) : null,
            'message_sha256' => hash('sha256', $this->state->comparableMessage($text)),
            'own_path_normalized' => str_contains($text, $this->state->absoluteTmp),
            'native_cursor_critical' => str_starts_with($text, 'Product export pagination did not advance.'),
        ];
    }
}

class FailureEntityFactory extends Factory
{
    public function __construct(
        ObjectManagerInterface $objectManager,
        private FailureState $state,
        private RowCustomizerInterface $customizer,
        private RecordingLogger $recorder
    ) {
        parent::__construct($objectManager);
    }

    public function create($className)
    {
        if ($className !== ProductExport::class) {
            return parent::create($className);
        }
        $selected = $this->state->flavor === 'bss' ? FailureBssExport::class : FailureVideoExport::class;
        $logger = $this->state->flavor === 'bss'
            ? $this->recorder : new ScopedExportLogger($this->recorder, new ExportExecutionContext());
        $entity = $this->_objectManager->create($selected, [
            'logger' => $logger,
            'rowCustomizer' => new FailureRowCustomizer($this->customizer, $this->state),
        ]);
        $entity->setFailureState($this->state);
        $this->state->entities[] = get_class($entity);
        return $entity;
    }
}

class FailureDriver extends File
{
    public function __construct(private FailureState $state)
    {
        parent::__construct();
    }

    public function filePutCsv($resource, array $data, $delimiter = ',', $enclosure = '"')
    {
        $own = (stream_get_meta_data($resource)['uri'] ?? '') === $this->state->absoluteTmp;
        if ($own && $this->state->variant === 'write' && $this->state->csvWrites === 2) {
            $this->state->fault();
            throw new FileSystemException(__('LCVX: injected CSV write failure'));
        }
        $result = parent::filePutCsv($resource, $data, $delimiter, $enclosure);
        if ($own) {
            ++$this->state->csvWrites;
        }
        return $result;
    }

    public function fileGetContents($path, $flag = null, $context = null)
    {
        if ($path === $this->state->absoluteTmp && $this->state->variant === 'contents') {
            $this->state->fault();
            throw new FileSystemException(__('LCVX: injected getContents failure'));
        }
        return parent::fileGetContents($path, $flag, $context);
    }
}

class FailureFilesystem extends Filesystem
{
    private Write $directory;

    public function __construct(private Filesystem $native, DriverPool $pool, FailureState $state)
    {
        $this->directory = new Write(
            new \Magento\Framework\Filesystem\File\WriteFactory($pool),
            new FailureDriver($state),
            $native->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::VAR_IMPORT_EXPORT)->getAbsolutePath()
        );
    }

    public function getDirectoryWrite($directoryCode, $driverCode = DriverPool::FILE)
    {
        return $directoryCode === \Magento\Framework\App\Filesystem\DirectoryList::VAR_IMPORT_EXPORT
            ? $this->directory : $this->native->getDirectoryWrite($directoryCode, $driverCode);
    }
}

class RecordingExportManager implements ExportManagementInterface
{
    public function __construct(private ExportManagementInterface $native, private FailureState $state)
    {
    }

    public function export(ExportInfoInterface $exportInfo)
    {
        try {
            $result = $this->native->export($exportInfo);
            $this->state->manager = ['returned' => $result, 'exception' => null];
            return $result;
        } catch (Throwable $failure) {
            $this->state->manager = [
                'returned' => null, 'exception' => get_class($failure),
                'message_sha256' => hash('sha256', $this->state->comparableMessage($failure->getMessage())),
            ];
            throw $failure;
        }
    }
}
