<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Model;

use LeanCommerce\ProductVideoExport\Domain\VideoPresence;
use LeanCommerce\ProductVideoExport\Domain\VideoResult;
use LeanCommerce\ProductVideoExport\Model\ExportExecutionContext;
use LeanCommerce\ProductVideoExport\Model\ScopedVideoWriter;
use LeanCommerce\ProductVideoExport\Test\Unit\Model\Fixture\RecordingAdapter;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/Fixture/RecordingAdapter.php';

final class ScopedVideoWriterTest extends TestCase
{
    public function testAllPreservesStructuralRowsAndDefersHeaderUntilActualWrite(): void
    {
        [$writer, $inner, $context] = $this->writer('all');
        $headers = array_merge(['sku', 'store_view_code', 'name'], VideoResult::COLUMNS);
        $row = ['sku' => '001', 'store_view_code' => 'oriente', 'name' => '=Original'];

        self::assertSame($writer, $writer->setHeaderCols($headers));
        self::assertSame([], $inner->headers);
        self::assertSame($writer, $writer->writeRow($row));
        self::assertSame([$headers], $inner->headers);
        self::assertSame([$row], $inner->rows);
        self::assertSame(1, $context->getWrittenRows());
        self::assertSame('text/csv', $writer->getContentType());
        self::assertSame('csv', $writer->getFileExtension());
        self::assertSame($inner->contents, $writer->getContents());
    }

    #[DataProvider('filteredCases')]
    public function testFilteredModesContinueAfterRejectedRowsAndWriteOriginalValuesOnce(string $mode, int $wanted): void
    {
        [$writer, $inner, $context] = $this->writer($mode);
        $writer->setHeaderCols(array_merge(['sku'], VideoResult::COLUMNS));
        $rejected = $this->row(1 - $wanted, 'same-sku');
        $accepted = $this->row($wanted, 'same-sku');

        $writer->writeRow($rejected);
        $writer->writeRow($rejected);
        self::assertSame([], $inner->headers);
        self::assertSame([], $inner->rows);
        $writer->writeRow($accepted);
        $writer->writeRow($rejected);
        $writer->writeRow($accepted);

        self::assertCount(1, $inner->headers);
        self::assertSame([$accepted, $accepted], $inner->rows);
        self::assertSame(2, $writer->getWrittenRows());
        self::assertSame(2, $context->getWrittenRows());
    }

    public static function filteredCases(): array
    {
        return ['with' => ['with', 1], 'without' => ['without', 0]];
    }

    public function testZeroAcceptedRowsFlushTheAdapterWithoutPublishingAHeaderOnlyFile(): void
    {
        [$writer, $inner] = $this->writer('with');
        $writer->setHeaderCols(VideoResult::COLUMNS);
        $writer->writeRow($this->row(0));

        self::assertSame('', $writer->getContents());
        self::assertSame(1, $inner->readCalls);
        self::assertSame([], $inner->headers);
        self::assertSame([], $inner->rows);
    }

    #[DataProvider('invalidRows')]
    public function testIncompleteFilteredBlocksBecomeFatal(array $row): void
    {
        [$writer, $inner, $context] = $this->writer('with');
        try {
            $writer->writeRow($row);
            self::fail('An invalid block must not be discarded silently.');
        } catch (LocalizedException) {
            self::assertTrue($context->hasFailed());
            self::assertSame([], $inner->rows);
        }
    }

    public static function invalidRows(): array
    {
        return [
            'missing count' => [['has_video' => 1, 'video_urls' => 'U', 'video_titles' => 'T']],
            'structural blanks' => [['video_count' => '', 'has_video' => '', 'video_urls' => '', 'video_titles' => '']],
            'negative count' => [['video_count' => -1, 'has_video' => 0, 'video_urls' => '', 'video_titles' => '']],
            'inconsistent indicator' => [['video_count' => 1, 'has_video' => 0, 'video_urls' => 'U', 'video_titles' => 'T']],
            'missing title' => [['video_count' => 1, 'has_video' => 1, 'video_urls' => 'U']],
        ];
    }

    #[DataProvider('failureStages')]
    public function testNativeFailuresAreRethrownAndMarkFatal(string $stage): void
    {
        [$writer, $inner, $context] = $this->writer('all');
        $failure = new RuntimeException('Underlying failure');
        $inner->{$stage . 'Failure'} = $failure;
        $writer->setHeaderCols(VideoResult::COLUMNS);

        try {
            if ($stage === 'header' || $stage === 'write') {
                $writer->writeRow($this->row(1));
            } elseif ($stage === 'read') {
                $writer->getContents();
            } else {
                $writer->cleanup();
            }
            self::fail('The native failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
            self::assertSame($failure, $context->getFailure());
            self::assertTrue($context->hasFailed());
        }
    }

    public static function failureStages(): array
    {
        return ['header' => ['header'], 'write' => ['write'], 'read' => ['read'], 'cleanup' => ['cleanup']];
    }

    public function testFatalAfterPartialWritePreventsReadingPartialContents(): void
    {
        [$writer, $inner, $context] = $this->writer('all');
        $writer->writeRow($this->row(1));
        $context->markFatal(new RuntimeException('Absorbed later batch error'));

        try {
            $writer->getContents();
            self::fail('Partial contents must not be returned.');
        } catch (LocalizedException) {
            self::assertSame(0, $inner->readCalls);
            self::assertSame(1, $writer->getWrittenRows());
        }
    }

    public function testFailureRecordedDuringReadIsCheckedBeforeReturningContents(): void
    {
        [$writer, $inner, $context] = $this->writer('all');
        $writer->writeRow($this->row(1));
        $failure = new RuntimeException('Read logged a critical error');
        $inner->onRead = static fn () => $context->markFatal($failure);

        try {
            $writer->getContents();
            self::fail('Content from a failed adapter must not be returned.');
        } catch (LocalizedException) {
            self::assertSame(1, $inner->readCalls);
            self::assertSame($failure, $context->getFailure());
        }
    }

    public function testFailureRecordedDuringWriteDoesNotIncrementSuccessCount(): void
    {
        [$writer, $inner, $context] = $this->writer('all');
        $inner->onWrite = static fn () => $context->markFatal();

        try {
            $writer->writeRow($this->row(1));
            self::fail('A swallowed write failure must still fail the operation.');
        } catch (LocalizedException) {
            self::assertSame(0, $writer->getWrittenRows());
            self::assertSame(0, $context->getWrittenRows());
        }
    }

    public function testDuplicateHeadersFailBeforeMutatingTheNativeAdapter(): void
    {
        [$writer, $inner, $context] = $this->writer('all');
        $writer->setHeaderCols(VideoResult::COLUMNS);

        try {
            $writer->setHeaderCols(VideoResult::COLUMNS);
            self::fail('A duplicate header must fail.');
        } catch (LocalizedException) {
            self::assertTrue($context->hasFailed());
            self::assertSame([], $inner->headers);
        }
    }

    public function testCleanupUsesOnlyExistingPublicAdapterContract(): void
    {
        [$writer, $inner] = $this->writer('all');
        $writer->cleanup();
        self::assertSame(1, $inner->cleanupCalls);
    }

    private function writer(string $mode): array
    {
        $context = new ExportExecutionContext();
        $context->begin();
        $inner = new RecordingAdapter();
        return [new ScopedVideoWriter($inner, $context, VideoPresence::fromInput($mode)), $inner, $context];
    }

    private function row(int $count, string $sku = '001'): array
    {
        return ['sku' => $sku] + (new VideoResult($count, $count ? '=U' : '', $count ? '=T' : ''))->toColumns();
    }
}
