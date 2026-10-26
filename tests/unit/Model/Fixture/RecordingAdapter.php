<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Model\Fixture;

use Closure;
use Magento\ImportExport\Model\Export\Adapter\AbstractAdapter;
use Throwable;

final class RecordingAdapter extends AbstractAdapter
{
    public array $headers = [];
    public array $rows = [];
    public int $readCalls = 0;
    public int $cleanupCalls = 0;
    public string $contents = 'native,serialized,contents';
    public ?Throwable $headerFailure = null;
    public ?Throwable $writeFailure = null;
    public ?Throwable $readFailure = null;
    public ?Throwable $cleanupFailure = null;
    public ?Closure $onRead = null;
    public ?Closure $onWrite = null;

    public function __construct()
    {
    }

    public function getContentType()
    {
        return 'text/csv';
    }

    public function getFileExtension()
    {
        return 'csv';
    }

    public function setHeaderCols(array $headerColumns)
    {
        if ($this->headerFailure !== null) {
            throw $this->headerFailure;
        }
        $this->headers[] = $headerColumns;
        return $this;
    }

    public function writeRow(array $rowData)
    {
        if ($this->writeFailure !== null) {
            throw $this->writeFailure;
        }
        $this->rows[] = $rowData;
        if ($this->onWrite !== null) {
            ($this->onWrite)();
        }
        return $this;
    }

    public function getContents()
    {
        $this->readCalls++;
        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }
        if ($this->onRead !== null) {
            ($this->onRead)();
        }
        return $this->contents;
    }

    public function destruct(): void
    {
        $this->cleanupCalls++;
        if ($this->cleanupFailure !== null) {
            throw $this->cleanupFailure;
        }
    }
}
