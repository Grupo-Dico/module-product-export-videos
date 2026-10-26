<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Math\Random;
use Magento\ImportExport\Model\Export\Adapter\Csv;
use Magento\ImportExport\Model\Export\Adapter\Factory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Composes the native factory only for the export model's dedicated constructor argument.
 */
class ScopedAdapterFactory extends Factory
{
    /**
     * Delegate all other adapters and avoid constructing or accessing an object manager.
     *
     * @param Factory $nativeFactory
     * @param OwnedCsvFactory $ownedCsvFactory
     * @param CsvCleanupRegistry $registry
     * @param Filesystem $filesystem
     * @param Random $random
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Factory $nativeFactory,
        private readonly OwnedCsvFactory $ownedCsvFactory,
        private readonly CsvCleanupRegistry $registry,
        private readonly Filesystem $filesystem,
        private readonly Random $random,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Replace only the exact native CSV class while an enriched export scope is active.
     *
     * @param string $className
     * @param array $arguments
     * @return \Magento\ImportExport\Model\Export\Adapter\AbstractAdapter
     * @throws Throwable
     */
    public function create($className, array $arguments = [])
    {
        if (!$this->registry->isActive() || $className !== Csv::class) {
            return $this->nativeFactory->create($className, $arguments);
        }

        $claim = null;
        $destination = $arguments['destination'] ?? null;
        if (!$destination) {
            $destination = $this->random->getUniqueHash('importexport_lc_');
            $arguments['destination'] = $destination;
        }
        if (is_string($destination) && $destination !== '' && $destination !== '0') {
            $directory = $this->filesystem->getDirectoryWrite(
                $arguments['destinationDirectoryCode'] ?? DirectoryList::VAR_IMPORT_EXPORT
            );
            if ($directory->isExist($destination)) {
                throw new LocalizedException(__('The product video export temporary file already exists.'));
            }
            // Exclusive creation is the ownership claim; never truncate an existing destination.
            $reservation = $directory->openFile($destination, 'x');
            try {
                $claim = new CsvFileClaim($directory, $destination, $reservation);
            } catch (Throwable $failure) {
                try {
                    $reservation->close();
                } catch (Throwable $cleanupFailure) {
                    $this->reportCleanupFailure($cleanupFailure);
                }
                throw $failure;
            }
        }

        try {
            $adapter = $this->ownedCsvFactory->create($arguments);
        } catch (Throwable $failure) {
            if ($claim !== null) {
                try {
                    $claim->release();
                } catch (Throwable $cleanupFailure) {
                    $this->reportCleanupFailure($cleanupFailure);
                }
            }
            throw $failure;
        }
        if ($claim !== null) {
            $adapter->claimDestination($claim);
        }
        $this->registry->register($adapter);
        return $adapter;
    }

    /**
     * Release a claimed file after construction failed without hiding its original cause.
     *
     * @param Throwable $cleanupFailure
     * @return void
     */
    private function reportCleanupFailure(Throwable $cleanupFailure): void
    {
        try {
            $this->logger->critical('Unable to clean an owned product video export temporary file.', [
                'exception' => $cleanupFailure,
            ]);
        } catch (Throwable) {
            // Preserve the original construction failure even if reporting is unavailable.
        }
    }
}
