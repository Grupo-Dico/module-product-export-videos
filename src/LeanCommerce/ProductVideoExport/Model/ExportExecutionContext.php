<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use Magento\Framework\Exception\LocalizedException;
use Throwable;

/**
 * Tracks one requested export and preserves its first fatal cause.
 */
class ExportExecutionContext
{
    /** @var bool */
    private bool $active = false;

    /** @var bool */
    private bool $failed = false;

    /** @var Throwable|null */
    private ?Throwable $failure = null;

    /** @var int */
    private int $writtenRows = 0;

    /**
     * Start an independent execution without inheriting a previous attempt's state.
     *
     * @return void
     */
    public function begin(): void
    {
        $this->reset();
        $this->active = true;
    }

    /**
     * Return to the native passthrough state after an execution.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->active = false;
        $this->failed = false;
        $this->failure = null;
        $this->writtenRows = 0;
    }

    /**
     * Determine whether guards belong to the current requested export.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Record only the first failure while active, including critical logs without an exception.
     *
     * @param Throwable|null $failure
     * @return void
     */
    public function markFatal(?Throwable $failure = null): void
    {
        if ($this->active && !$this->failed) {
            $this->failed = true;
            $this->failure = $failure;
        }
    }

    /**
     * Prevent a partially completed export from being returned as success.
     *
     * @return void
     * @throws LocalizedException
     */
    public function assertHealthy(): void
    {
        if ($this->failed) {
            throw new LocalizedException(__('The product video export could not be completed.'));
        }
    }

    /**
     * Read the monotonic failure state for this attempt.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->failed;
    }

    /**
     * Retrieve the first throwable without copying its message into user-facing output.
     *
     * @return Throwable|null
     */
    public function getFailure(): ?Throwable
    {
        return $this->failure;
    }

    /**
     * Count a row only after the underlying writer accepted it.
     *
     * @return void
     */
    public function recordWrittenRow(): void
    {
        $this->writtenRows++;
    }

    /**
     * Get a diagnostic count, not a proof that all native batches completed.
     *
     * @return int
     */
    public function getWrittenRows(): int
    {
        return $this->writtenRows;
    }
}
