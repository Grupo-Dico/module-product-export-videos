<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use Magento\ImportExport\Model\Export;
use WeakMap;

/**
 * Shares scoped file ownership without retaining export models between worker jobs.
 */
class CsvCleanupRegistry
{
    /** @var WeakMap<Export, CsvCleanupScope> */
    private WeakMap $models;

    /** @var array<CsvCleanupScope|null> */
    private array $activeModels = [];

    /** @var CsvCleanupScope[] */
    private array $consumers = [];

    /**
     * Initialize the weak model map.
     */
    public function __construct()
    {
        $this->models = new WeakMap();
    }

    /**
     * Reuse a model's scope when metadata created its writer before export.
     *
     * @param Export $model
     * @return void
     */
    public function enterModel(Export $model): void
    {
        $this->models[$model] ??= new CsvCleanupScope();
        $this->activeModels[] = $this->models[$model];
    }

    /**
     * Restore the preceding model scope even for nested exports.
     *
     * @return void
     */
    public function leaveModel(): void
    {
        array_pop($this->activeModels);
    }

    /**
     * Suspend an outer enriched scope while a nested legacy operation runs.
     *
     * @return void
     */
    public function enterPassthrough(): void
    {
        $this->activeModels[] = null;
    }

    /**
     * Determine whether the dedicated factory is creating an enriched export's writer.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->activeModels !== []
            && $this->activeModels[array_key_last($this->activeModels)] !== null;
    }

    /**
     * Register with the model and nearest consumer, which owns the later publication boundary.
     *
     * @param OwnedCsv $adapter
     * @return void
     */
    public function register(OwnedCsv $adapter): void
    {
        if (!$this->isActive()) {
            return;
        }
        $this->activeModels[array_key_last($this->activeModels)]->register($adapter);
        if ($this->consumers !== []) {
            $this->consumers[array_key_last($this->consumers)]->register($adapter);
        }
    }

    /**
     * Forget a completed model and optionally clean its destination.
     *
     * @param Export $model
     * @param bool $cleanup
     * @return void
     */
    public function finishModel(Export $model, bool $cleanup): void
    {
        $scope = $this->models[$model] ?? null;
        unset($this->models[$model]);
        if ($cleanup && $scope !== null) {
            $scope->cleanup();
        }
    }

    /**
     * Begin ownership through the native consumer's publication attempt.
     *
     * @return void
     */
    public function enterConsumer(): void
    {
        $this->consumers[] = new CsvCleanupScope();
    }

    /**
     * Clean only this consumer's remaining temporary files after native publication returns.
     *
     * @return void
     */
    public function leaveConsumer(): void
    {
        $scope = array_pop($this->consumers);
        if ($scope !== null) {
            $scope->cleanup();
        }
    }
}
