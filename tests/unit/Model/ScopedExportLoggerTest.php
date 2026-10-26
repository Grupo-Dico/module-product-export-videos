<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Model;

use LeanCommerce\ProductVideoExport\Model\ExportExecutionContext;
use LeanCommerce\ProductVideoExport\Model\ScopedExportLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class ScopedExportLoggerTest extends TestCase
{
    public function testInactiveCriticalDelegatesOriginalEventUnchanged(): void
    {
        $inner = $this->recorder();
        $context = new ExportExecutionContext();
        $logger = new ScopedExportLogger($inner, $context);
        $message = new RuntimeException('Original message');
        $data = ['exception' => $message, 'batch' => 7];

        $logger->critical($message, $data);

        self::assertSame([['critical', $message, $data]], $inner->events);
        self::assertSame($context, $logger->getContext());
        self::assertFalse($context->hasFailed());
    }

    public function testActiveCriticalMarksFatalWithoutThrowingAndStillDelegates(): void
    {
        $inner = $this->recorder();
        $context = new ExportExecutionContext();
        $context->begin();
        $logger = new ScopedExportLogger($inner, $context);
        $failure = new RuntimeException('Native failure');

        $logger->critical('Native batch failed', ['exception' => $failure]);
        $logger->critical(new RuntimeException('Later failure'));

        self::assertTrue($context->hasFailed());
        self::assertSame($failure, $context->getFailure());
        self::assertCount(2, $inner->events);
        self::assertSame(['critical', 'Native batch failed', ['exception' => $failure]], $inner->events[0]);
    }

    public function testActiveCriticalWithThrowableAsMessageCapturesTheCause(): void
    {
        $inner = $this->recorder();
        $context = new ExportExecutionContext();
        $context->begin();
        $failure = new RuntimeException('Failure');

        (new ScopedExportLogger($inner, $context))->critical($failure);

        self::assertSame($failure, $context->getFailure());
        self::assertSame($failure, $inner->events[0][1]);
    }

    public function testOtherLevelsDoNotIntroduceFailurePolicy(): void
    {
        $inner = $this->recorder();
        $context = new ExportExecutionContext();
        $context->begin();
        $logger = new ScopedExportLogger($inner, $context);
        $logger->warning('Warning');
        $logger->error('Error');
        $logger->info('Info');

        self::assertFalse($context->hasFailed());
        self::assertCount(3, $inner->events);
    }

    private function recorder(): AbstractLogger
    {
        return new class extends AbstractLogger {
            public array $events = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->events[] = [$level, $message, $context];
            }
        };
    }
}
