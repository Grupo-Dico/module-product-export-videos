<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use Magento\ImportExport\Model\Export\Adapter\Csv;
use Throwable;

/**
 * Retains native CSV serialization and exposes cleanup for this adapter's destination.
 */
class OwnedCsv extends Csv
{
    /** @var CsvFileClaim|null */
    private ?CsvFileClaim $claim = null;

    /**
     * Transfer the factory's exclusive reservation to this adapter.
     *
     * @param CsvFileClaim $claim
     * @return void
     */
    public function claimDestination(CsvFileClaim $claim): void
    {
        $this->claim = $claim;
    }

    /**
     * Close native output without the parent's path-only unlink; the claim owns deletion.
     *
     * @return void
     */
    public function destruct()
    {
        if (is_object($this->_fileHandler)) {
            try {
                $this->_fileHandler->close();
            } finally {
                $this->_fileHandler = null;
            }
        }
    }

    /**
     * Dispose abandoned metadata writers without replacing an exception during unwinding.
     */
    public function __destruct()
    {
        try {
            $this->cleanupOwnedDestination();
        } catch (Throwable) {
            // Explicit workflow cleanup reports failures; destruction remains best effort.
        }
    }

    /**
     * Close the native handle and remove only the destination created for this adapter.
     *
     * @return void
     * @throws Throwable
     */
    public function cleanupOwnedDestination(): void
    {
        $failure = null;
        try {
            $this->destruct();
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        try {
            $this->claim?->release();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
