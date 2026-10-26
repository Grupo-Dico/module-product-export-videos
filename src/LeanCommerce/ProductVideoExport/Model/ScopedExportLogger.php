<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * Observes the exporter's swallowed failures without replacing the application's logger.
 */
class ScopedExportLogger extends AbstractLogger
{
    /**
     * @param LoggerInterface $logger
     * @param ExportExecutionContext $context
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ExportExecutionContext $context
    ) {
    }

    /**
     * Mark active critical events before delegating the original event unchanged.
     *
     * @param mixed $level
     * @param string|Stringable $message
     * @param array $context
     * @return void
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::CRITICAL && $this->context->isActive()) {
            $failure = $message instanceof Throwable ? $message : ($context['exception'] ?? null);
            $this->context->markFatal($failure instanceof Throwable ? $failure : null);
        }
        $this->logger->log($level, $message, $context);
    }

    /**
     * Share the exact execution context with the exporter and its scoped writer.
     *
     * @return ExportExecutionContext
     */
    public function getContext(): ExportExecutionContext
    {
        return $this->context;
    }
}
