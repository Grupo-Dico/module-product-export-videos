<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Model;

use Error;
use LeanCommerce\ProductVideoExport\Model\ExportExecutionContext;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExportExecutionContextTest extends TestCase
{
    public function testInactiveContextDoesNotChangeLegacyErrorBehavior(): void
    {
        $context = new ExportExecutionContext();
        $context->markFatal(new RuntimeException('Legacy error'));
        $context->assertHealthy();
        self::assertFalse($context->isActive());
        self::assertFalse($context->hasFailed());
        self::assertNull($context->getFailure());
    }

    public function testFirstFatalCauseCannotBeOverwritten(): void
    {
        $context = new ExportExecutionContext();
        $first = new Error('Sensitive first detail');
        $context->begin();
        $context->markFatal($first);
        $context->markFatal(new RuntimeException('Later failure'));

        self::assertTrue($context->hasFailed());
        self::assertSame($first, $context->getFailure());
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The product video export could not be completed.');
        $context->assertHealthy();
    }

    public function testCriticalLogWithoutThrowableStillRemainsTheFirstFailure(): void
    {
        $context = new ExportExecutionContext();
        $context->begin();
        $context->markFatal();
        $context->markFatal(new RuntimeException('Later detail'));
        self::assertTrue($context->hasFailed());
        self::assertNull($context->getFailure());
    }

    public function testFailedRequestedThenLegacyThenSuccessfulRequestedDoNotShareState(): void
    {
        $context = new ExportExecutionContext();
        $context->begin();
        $context->recordWrittenRow();
        $context->markFatal(new RuntimeException('First request failed'));
        $context->reset();

        self::assertFalse($context->isActive());
        self::assertFalse($context->hasFailed());
        self::assertSame(0, $context->getWrittenRows());
        $context->markFatal(new RuntimeException('Unrelated legacy log'));
        $context->assertHealthy();

        $context->begin();
        $context->recordWrittenRow();
        $context->assertHealthy();
        self::assertTrue($context->isActive());
        self::assertSame(1, $context->getWrittenRows());
        self::assertNull($context->getFailure());
    }

    public function testSeparateExecutionInstancesNeverShareFatalState(): void
    {
        $first = new ExportExecutionContext();
        $second = new ExportExecutionContext();
        $first->begin();
        $second->begin();
        $first->markFatal();
        $second->assertHealthy();
        self::assertFalse($second->hasFailed());
    }
}
