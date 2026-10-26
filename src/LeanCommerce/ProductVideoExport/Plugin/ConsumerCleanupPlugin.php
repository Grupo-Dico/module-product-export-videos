<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Plugin;

use LeanCommerce\ProductVideoExport\Model\CsvCleanupRegistry;
use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\ImportExport\Api\Data\LocalizedExportInfoInterface;
use Magento\ImportExport\Model\Export\Consumer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cleans owned queue temporaries even when the native consumer catches publication errors.
 */
class ConsumerCleanupPlugin
{
    /**
     * @param CsvCleanupRegistry $registry
     * @param PresenceParameters $parameters
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CsvCleanupRegistry $registry,
        private readonly PresenceParameters $parameters,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Preserve the native process and clean only adapters registered by the enclosed export.
     *
     * @param Consumer $subject
     * @param callable $proceed
     * @param LocalizedExportInfoInterface $exportInfo
     * @return mixed
     * @throws Throwable
     */
    public function aroundProcess(Consumer $subject, callable $proceed, LocalizedExportInfoInterface $exportInfo)
    {
        if (!$this->isRequested($exportInfo)) {
            return $proceed($exportInfo);
        }
        $this->registry->enterConsumer();
        $failure = null;
        try {
            return $proceed($exportInfo);
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try {
                $this->registry->leaveConsumer();
            } catch (Throwable $cleanupFailure) {
                if ($failure === null) {
                    throw $cleanupFailure;
                }
                try {
                    $this->logger->critical('Unable to clean an owned product video export temporary file.', [
                        'exception' => $cleanupFailure,
                    ]);
                } catch (Throwable) {
                    // Preserve the native consumer failure even if reporting is unavailable.
                }
            }
        }
    }

    /**
     * Activate only for a valid enriched DTO; native code retains validation of all other input.
     *
     * @param LocalizedExportInfoInterface $exportInfo
     * @return bool
     */
    private function isRequested(LocalizedExportInfoInterface $exportInfo): bool
    {
        if ($exportInfo->getEntity() !== 'catalog_product' || $exportInfo->getFileFormat() !== 'csv') {
            return false;
        }
        try {
            $filters = $this->serializer->unserialize($exportInfo->getExportFilter());
            return is_array($filters) && $this->parameters->resolve(['export_filter' => $filters]) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
