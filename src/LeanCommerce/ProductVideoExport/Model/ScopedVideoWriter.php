<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use LeanCommerce\ProductVideoExport\Domain\VideoPresence;
use LeanCommerce\ProductVideoExport\Domain\VideoResult;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Model\Export\Adapter\AbstractAdapter;
use Throwable;

/**
 * Filters formed native rows and delegates serialization to the original adapter once.
 */
class ScopedVideoWriter extends AbstractAdapter
{
    /** @var array|null */
    private ?array $headers = null;

    /** @var bool */
    private bool $headersWritten = false;

    /** @var int */
    private int $writtenRows = 0;

    /**
     * Wrap an existing destination without invoking the parent filesystem constructor.
     *
     * @param AbstractAdapter $inner
     * @param ExportExecutionContext $context
     * @param VideoPresence $presence
     */
    public function __construct(
        private readonly AbstractAdapter $inner,
        private readonly ExportExecutionContext $context,
        private readonly VideoPresence $presence
    ) {
    }

    /**
     * Preserve the original adapter's content type.
     *
     * @return string
     */
    public function getContentType()
    {
        try {
            return $this->inner->getContentType();
        } catch (Throwable $failure) {
            $this->context->markFatal($failure);
            throw $failure;
        }
    }

    /**
     * Preserve the original adapter's filename extension.
     *
     * @return string
     */
    public function getFileExtension()
    {
        try {
            return $this->inner->getFileExtension();
        } catch (Throwable $failure) {
            $this->context->markFatal($failure);
            throw $failure;
        }
    }

    /**
     * Delay the header so zero accepted rows keep the native no-data behavior.
     *
     * @param array $headerColumns
     * @return $this
     * @throws Throwable
     */
    public function setHeaderCols(array $headerColumns)
    {
        try {
            $this->context->assertHealthy();
            if ($this->headers !== null) {
                throw new LocalizedException(__('The header column names are already set.'));
            }
            if ($headerColumns !== []) {
                $this->headers = $headerColumns;
            }
            return $this;
        } catch (Throwable $failure) {
            $this->context->markFatal($failure);
            throw $failure;
        }
    }

    /**
     * Emit accepted contexts while leaving native row values and CSV handling intact.
     *
     * @param array $rowData
     * @return $this
     * @throws Throwable
     */
    public function writeRow(array $rowData)
    {
        try {
            $this->context->assertHealthy();
            if (!$this->accepts($rowData)) {
                return $this;
            }
            if (!$this->headersWritten && $this->headers !== null) {
                $this->inner->setHeaderCols($this->headers);
                $this->headersWritten = true;
            }
            $this->inner->writeRow($rowData);
            $this->context->assertHealthy();
            $this->writtenRows++;
            $this->context->recordWrittenRow();
            return $this;
        } catch (Throwable $failure) {
            $this->context->markFatal($failure);
            throw $failure;
        }
    }

    /**
     * Let the native adapter flush and close, then prevent partial or header-only publication.
     *
     * @return string
     * @throws Throwable
     */
    public function getContents()
    {
        try {
            $this->context->assertHealthy();
            $contents = $this->inner->getContents();
            $this->context->assertHealthy();
            return $this->writtenRows === 0 ? '' : $contents;
        } catch (Throwable $failure) {
            $this->context->markFatal($failure);
            throw $failure;
        }
    }

    /**
     * Invoke only the adapter's existing public cleanup contract, without inspecting paths.
     *
     * @return void
     */
    public function cleanup(): void
    {
        try {
            if (is_callable([$this->inner, 'destruct'])) {
                $this->inner->destruct();
            }
        } catch (Throwable $failure) {
            $this->context->markFatal($failure);
            throw $failure;
        }
    }

    /**
     * Get successful writes, independently of the native processed-row counter.
     *
     * @return int
     */
    public function getWrittenRows(): int
    {
        return $this->writtenRows;
    }

    /**
     * Select filtered rows by the effective block; All also permits structural inherited blanks.
     *
     * @param array $rowData
     * @return bool
     * @throws LocalizedException
     */
    private function accepts(array $rowData): bool
    {
        if ($this->presence->getValue() === 'all') {
            return true;
        }
        $count = $rowData['video_count'] ?? null;
        if (!is_int($count) || $count < 0
            || !isset($rowData['has_video'], $rowData['video_urls'], $rowData['video_titles'])
            || $rowData['has_video'] !== ($count > 0 ? 1 : 0)
            || !is_string($rowData['video_urls']) || !is_string($rowData['video_titles'])
        ) {
            throw new LocalizedException(__('The product video export could not be completed.'));
        }
        return $this->presence->accepts(new VideoResult($count, $rowData['video_urls'], $rowData['video_titles']));
    }
}
