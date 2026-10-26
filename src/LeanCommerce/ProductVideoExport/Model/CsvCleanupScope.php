<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use Throwable;

/**
 * Owns only the adapters registered during one model or consumer execution.
 */
class CsvCleanupScope
{
    /** @var OwnedCsv[] */
    private array $adapters = [];

    /**
     * Register a successfully constructed adapter once.
     *
     * @param OwnedCsv $adapter
     * @return void
     */
    public function register(OwnedCsv $adapter): void
    {
        $this->adapters[spl_object_id($adapter)] = $adapter;
    }

    /**
     * Attempt every cleanup and propagate the first failure.
     *
     * @return void
     * @throws Throwable
     */
    public function cleanup(): void
    {
        $failure = null;
        $adapters = $this->adapters;
        $this->adapters = [];
        foreach ($adapters as $adapter) {
            try {
                $adapter->cleanupOwnedDestination();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
