<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Domain;

use LeanCommerce\ProductVideoExport\Domain\VideoPresence;
use LeanCommerce\ProductVideoExport\Domain\VideoRepresentation;
use LeanCommerce\ProductVideoExport\Domain\VideoResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoRepresentationTest extends TestCase
{
    /** U05: omitting all keys, rather than inserting blank keys, preserves native suppression. */
    public function testEqualStoreBlockIsOmittedInAll(): void
    {
        $base = new VideoResult(1, 'U', 'T');
        $localExplicitEqual = new VideoResult(1, 'U', 'T');

        self::assertSame([], $this->represent($localExplicitEqual, $base, false, 'all'));
        self::assertSame($base->toColumns(), $this->represent($base, $base, true, 'all'));
    }

    public function testAbsentBaseMakesEveryAvailableStoreExplicit(): void
    {
        $result = new VideoResult(1, 'U', 'T');
        self::assertSame($result->toColumns(), $this->represent($result, null, false, 'all'));
    }

    #[DataProvider('changedBlocks')]
    public function testAnyDifferenceEmitsTheWholeBlock(VideoResult $store): void
    {
        $base = new VideoResult(1, 'U', 'T');
        self::assertSame($store->toColumns(), $this->represent($store, $base, false, 'all'));
    }

    public static function changedBlocks(): array
    {
        return [
            'title only' => [new VideoResult(1, 'U', 'local')],
            'empty title explicit' => [new VideoResult(1, 'U', '')],
            'url only' => [new VideoResult(1, 'other', 'T')],
            'no videos' => [new VideoResult(0, '', '')],
            'additional association' => [new VideoResult(2, 'U|U', 'T|T')],
        ];
    }

    public function testDiagnosticsDoNotTurnEqualOutputIntoAStoreDifference(): void
    {
        $base = new VideoResult(1, '', '', 0);
        $store = new VideoResult(1, '', '', 1);
        self::assertSame([], $this->represent($store, $base, false, 'all'));
    }

    public function testNoVideoBlockCanInheritWithoutBecomingZeroInEveryRow(): void
    {
        $zero = new VideoResult(0, '', '');
        self::assertSame([], $this->represent($zero, $zero, false, 'all'));
        self::assertSame($zero->toColumns(), $this->represent($zero, null, false, 'all'));
    }

    /** U05: all raw contexts get a block, including rejected ones, so native pagination advances. */
    public function testFilteredModesMaterializeBothAcceptedAndRejectedContexts(): void
    {
        $with = new VideoResult(1, 'U', '');
        $without = new VideoResult(0, '', '');
        foreach (['with', 'without'] as $mode) {
            foreach ([$with, $without] as $result) {
                self::assertSame($result->toColumns(), $this->represent($result, $result, false, $mode));
            }
        }
    }

    private function represent(VideoResult $result, ?VideoResult $base, bool $isBase, string $mode): array
    {
        return (new VideoRepresentation())->forContext($result, $base, $isBase, VideoPresence::fromInput($mode));
    }
}
