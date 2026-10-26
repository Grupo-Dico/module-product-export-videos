<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Domain;

use DomainException;
use LeanCommerce\ProductVideoExport\Domain\VideoAggregator;
use LeanCommerce\ProductVideoExport\Domain\VideoAssociation;
use LeanCommerce\ProductVideoExport\Domain\VideoListCodec;
use LeanCommerce\ProductVideoExport\Domain\VideoMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoAggregatorTest extends TestCase
{
    /** U01: global disable cannot be overridden; base disable can be. */
    #[DataProvider('eligibilityCases')]
    public function testEligibilityUsesEffectiveEnablement(
        bool $global,
        ?int $base,
        ?int $store,
        string $mediaType,
        int $expectedCount
    ): void {
        $result = $this->aggregator()->aggregate([
            new VideoAssociation(
                1,
                $mediaType,
                $global,
                new VideoMetadata(disabled: $base, url: 'U', title: 'T'),
                new VideoMetadata(disabled: $store)
            ),
        ]);

        self::assertSame($expectedCount, $result->getCount());
        self::assertSame($expectedCount, $result->toColumns()['has_video']);
    }

    public static function eligibilityCases(): array
    {
        return [
            'both absent defaults enabled' => [false, null, null, 'external-video', 1],
            'base enabled and view inherits' => [false, 0, null, 'external-video', 1],
            'base disabled and view inherits' => [false, 1, null, 'external-video', 0],
            'explicit zero enables disabled base' => [false, 1, 0, 'external-video', 1],
            'explicit one disables enabled base' => [false, 0, 1, 'external-video', 0],
            'global disable wins' => [true, 0, 0, 'external-video', 0],
            'image is not video' => [false, 0, 0, 'image', 0],
        ];
    }

    /** U01/I3: identity is not URL, and a repeated identical join row is one association. */
    public function testAssociationIdentityControlsCount(): void
    {
        $first = $this->association(1, url: 'same', title: 'First');
        $result = $this->aggregator()->aggregate([
            $first,
            $this->association(2, url: 'same', title: 'Second'),
            clone $first,
        ]);

        self::assertSame([
            'has_video' => 1,
            'video_count' => 2,
            'video_urls' => 'same|same',
            'video_titles' => 'First|Second',
        ], $result->toColumns());
    }

    public function testConflictingJoinRowsFailInsteadOfSelectingAnArbitraryValue(): void
    {
        $this->expectException(DomainException::class);
        $this->aggregator()->aggregate([
            $this->association(7, title: 'First'),
            $this->association(7, title: 'Conflicting'),
        ]);
    }

    /** U01 regression: duplicate detection must not coerce NULL, zero, empty or numeric strings. */
    #[DataProvider('strictDuplicateCases')]
    public function testDuplicateValuesMustMatchStrictlyInEitherOrder(
        VideoAssociation $first,
        VideoAssociation $second
    ): void {
        $this->expectException(DomainException::class);
        $this->aggregator()->aggregate([$first, $second]);
    }

    public static function strictDuplicateCases(): iterable
    {
        $base = new VideoMetadata(disabled: 1, position: 9, url: 'Base URL', title: 'Base');
        $pairs = [
            'combined NULL versus explicit zero and empty title' => [
                new VideoMetadata(disabled: null, title: null),
                new VideoMetadata(disabled: 0, title: ''),
            ],
            'disabled NULL versus zero' => [
                new VideoMetadata(disabled: null, title: 'Same'),
                new VideoMetadata(disabled: 0, title: 'Same'),
            ],
            'position NULL versus zero' => [
                new VideoMetadata(disabled: 0, position: null),
                new VideoMetadata(disabled: 0, position: 0),
            ],
            'title NULL versus empty' => [
                new VideoMetadata(disabled: 0, title: null),
                new VideoMetadata(disabled: 0, title: ''),
            ],
            'title numeric strings' => [
                new VideoMetadata(disabled: 0, title: '0'),
                new VideoMetadata(disabled: 0, title: '00'),
            ],
            'URL NULL versus empty' => [
                new VideoMetadata(disabled: 0, url: null),
                new VideoMetadata(disabled: 0, url: ''),
            ],
            'URL numeric strings' => [
                new VideoMetadata(disabled: 0, url: '0'),
                new VideoMetadata(disabled: 0, url: '00'),
            ],
        ];
        foreach ($pairs as $case => [$left, $right]) {
            $first = new VideoAssociation(7, 'external-video', false, $base, $left);
            $second = new VideoAssociation(7, 'external-video', false, $base, $right);
            yield $case . ' forward' => [$first, $second];
            yield $case . ' reverse' => [$second, $first];
        }
        $first = new VideoAssociation(7, 'external-video', false, new VideoMetadata(title: null));
        $second = new VideoAssociation(7, 'external-video', false, new VideoMetadata(title: ''));
        yield 'base title NULL versus empty forward' => [$first, $second];
        yield 'base title NULL versus empty reverse' => [$second, $first];
    }

    public function testExactDuplicateValuesDeduplicateAcrossIndependentObjectsAndClones(): void
    {
        $first = new VideoAssociation(
            7,
            'external-video',
            false,
            new VideoMetadata(disabled: 1, position: 9, url: 'U', title: 'Base'),
            new VideoMetadata(disabled: 0, position: 0, title: '')
        );
        $independent = new VideoAssociation(
            7,
            'external-video',
            false,
            new VideoMetadata(disabled: 1, position: 9, url: 'U', title: 'Base'),
            new VideoMetadata(disabled: 0, position: 0, title: '')
        );

        self::assertSame([
            'has_video' => 1,
            'video_count' => 1,
            'video_urls' => 'U',
            'video_titles' => '',
        ], $this->aggregator()->aggregate([$first, clone $first, $independent])->toColumns());
    }

    /** U01: NULL resolves per field; explicit zero and empty strings are retained. */
    public function testScopedFieldsInheritIndependently(): void
    {
        $result = $this->aggregator()->aggregate([
            new VideoAssociation(
                5,
                'external-video',
                false,
                new VideoMetadata(disabled: 1, position: 9, url: 'base-url', title: 'base-title'),
                new VideoMetadata(disabled: 0, position: 0, title: '')
            ),
            $this->association(1, position: 1, url: 'second-url', title: '0'),
        ]);

        self::assertSame([
            'has_video' => 1,
            'video_count' => 2,
            'video_urls' => 'base-url|second-url',
            'video_titles' => '|0',
        ], $result->toColumns());
    }

    /** U02: missing URLs retain their list position and association count. */
    public function testMissingUrlsDoNotDropEnabledAssociations(): void
    {
        $result = $this->aggregator()->aggregate([
            $this->association(1, url: null, title: ''),
            $this->association(2, url: 'U', title: 'Middle'),
            new VideoAssociation(
                3,
                'external-video',
                false,
                new VideoMetadata(url: 'must-not-inherit', title: 'base'),
                new VideoMetadata(url: '', title: '')
            ),
        ]);

        self::assertSame([
            'has_video' => 1,
            'video_count' => 3,
            'video_urls' => '|U|',
            'video_titles' => '|Middle|',
        ], $result->toColumns());
        self::assertSame(2, $result->getMissingUrlCount());
    }

    #[DataProvider('emptyCases')]
    public function testNoEligibleVideosProduceAnExplicitZeroBlock(array $associations): void
    {
        self::assertSame([
            'has_video' => 0,
            'video_count' => 0,
            'video_urls' => '',
            'video_titles' => '',
        ], $this->aggregator()->aggregate($associations)->toColumns());
    }

    public static function emptyCases(): array
    {
        return [
            'no associations' => [[]],
            'only globally disabled' => [[
                new VideoAssociation(1, 'external-video', true, new VideoMetadata(url: 'U', title: 'T')),
            ]],
            'only disabled in base' => [[
                new VideoAssociation(1, 'external-video', false, new VideoMetadata(disabled: 1)),
            ]],
        ];
    }

    /** U03: one ordering aligns both lists, including zero, ties and NULL last. */
    public function testOrderingKeepsUrlsAndTitlesAligned(): void
    {
        $result = $this->aggregator()->aggregate([
            $this->association(9, position: null, url: 'U9', title: 'T9'),
            $this->association(4, position: 2, url: 'U4', title: 'T4'),
            $this->association(7, position: null, url: 'U7', title: 'T7'),
            $this->association(3, position: 2, url: 'U3', title: 'T3'),
            $this->association(8, position: 0, url: 'U8', title: 'T8'),
        ]);

        self::assertSame('U8|U3|U4|U7|U9', $result->toColumns()['video_urls']);
        self::assertSame('T8|T3|T4|T7|T9', $result->toColumns()['video_titles']);
    }

    /** U03/U06: URL bytes remain intact; only title controls normalize. */
    public function testNormalizationDoesNotAlterUrlsOrNeutralizeFormulaPrefixes(): void
    {
        $result = $this->aggregator()->aggregate([
            $this->association(1, url: "=U\r\n\t", title: "=T\r\n\r\n\t\r\n  "),
            $this->association(2, url: '+U', title: '+T'),
            $this->association(3, url: '-U', title: '-T'),
            $this->association(4, url: '@U', title: '@T'),
        ]);

        self::assertSame("=U\r\n\t|+U|-U|@U", $result->toColumns()['video_urls']);
        self::assertSame('=T      |+T|-T|@T', $result->toColumns()['video_titles']);
    }

    public function testIterableInputIsConsumedWithoutAssumingAnArray(): void
    {
        $associations = (function (): \Generator {
            yield $this->association(2, title: 'Second');
            yield $this->association(1, title: 'First');
        })();

        self::assertSame('First|Second', $this->aggregator()->aggregate($associations)->toColumns()['video_titles']);
    }

    private function aggregator(): VideoAggregator
    {
        return new VideoAggregator(new VideoListCodec());
    }

    private function association(
        int $id,
        ?int $position = null,
        ?string $url = 'U',
        ?string $title = 'T'
    ): VideoAssociation {
        return new VideoAssociation(
            $id,
            'external-video',
            false,
            new VideoMetadata(position: $position, url: $url, title: $title)
        );
    }
}
