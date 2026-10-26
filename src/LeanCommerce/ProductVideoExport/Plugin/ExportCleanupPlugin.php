<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Plugin;

use LeanCommerce\ProductVideoExport\Model\CsvCleanupRegistry;
use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use Magento\ImportExport\Model\Export;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bounds owned CSV files to the enriched model execution, including early metadata calls.
 */
class ExportCleanupPlugin
{
    /**
     * @param CsvCleanupRegistry $registry
     * @param PresenceParameters $parameters
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CsvCleanupRegistry $registry,
        private readonly PresenceParameters $parameters,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Keep successful queue output until the native consumer publishes it.
     *
     * @param Export $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundExport(Export $subject, callable $proceed)
    {
        return $this->execute($subject, $proceed, true);
    }

    /**
     * Track the writer if the native model creates it while resolving the filename.
     *
     * @param Export $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundGetFileName(Export $subject, callable $proceed)
    {
        return $this->execute($subject, $proceed, false);
    }

    /**
     * Track the writer if content type is requested before export.
     *
     * @param Export $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundGetContentType(Export $subject, callable $proceed)
    {
        return $this->execute($subject, $proceed, false);
    }

    /**
     * Run a native operation without changing legacy validation or failure behavior.
     *
     * @param Export $subject
     * @param callable $proceed
     * @param bool $export
     * @return mixed
     * @throws Throwable
     */
    private function execute(Export $subject, callable $proceed, bool $export)
    {
        if ($subject->getData('entity') !== 'catalog_product'
            || $subject->getData('file_format') !== 'csv'
            || $this->parameters->resolve($subject->getData()) === null
        ) {
            $this->registry->enterPassthrough();
            try {
                return $proceed();
            } finally {
                $this->registry->leaveModel();
            }
        }
        $this->registry->enterModel($subject);
        $failure = null;
        try {
            return $proceed();
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try {
                if ($export || $failure !== null) {
                    $this->registry->finishModel(
                        $subject,
                        $failure !== null || (string)$subject->getData('file_name') === ''
                    );
                }
            } catch (Throwable $cleanupFailure) {
                if ($failure === null) {
                    throw $cleanupFailure;
                }
                try {
                    $this->logger->critical('Unable to clean an owned product video export temporary file.', [
                        'exception' => $cleanupFailure,
                    ]);
                } catch (Throwable) {
                    // Preserve the native export failure even if reporting is unavailable.
                }
            } finally {
                $this->registry->leaveModel();
            }
        }
    }
}
