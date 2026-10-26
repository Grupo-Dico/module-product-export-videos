<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Domain;

use InvalidArgumentException;
use LeanCommerce\ProductVideoExport\Domain\VideoPresence;
use LeanCommerce\ProductVideoExport\Domain\VideoResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoPresenceTest extends TestCase
{
    /** U02/U05: selection partitions product/store pairs; a SKU may occur in both modes. */
    public function testPresencePartitionsContextsUsingCountEvenWithEmptyLists(): void
    {
        $contexts = [
            'SKU-A/0' => new VideoResult(1, '', '', 1),
            'SKU-A/2' => new VideoResult(0, '', ''),
            'SKU-B/3' => new VideoResult(2, 'U|U', '|'),
            'SKU-C/0' => new VideoResult(0, '', ''),
        ];
        $all = VideoPresence::fromInput('all');
        $with = VideoPresence::fromInput('with');
        $without = VideoPresence::fromInput('without');
        $withKeys = [];
        $withoutKeys = [];
        foreach ($contexts as $key => $result) {
            self::assertTrue($all->accepts($result));
            self::assertNotSame($with->accepts($result), $without->accepts($result));
            if ($with->accepts($result)) {
                $withKeys[] = $key;
            }
            if ($without->accepts($result)) {
                $withoutKeys[] = $key;
            }
        }
        self::assertSame(['SKU-A/0', 'SKU-B/3'], $withKeys);
        self::assertSame(['SKU-A/2', 'SKU-C/0'], $withoutKeys);
    }

    #[DataProvider('invalidModes')]
    public function testPresentInvalidInputNeverFallsBackToAll(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        VideoPresence::fromInput($input);
    }

    public static function invalidModes(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'array' => [[]],
            'integer' => [0],
            'boolean' => [false],
            'unknown' => ['both'],
            'wrong case' => ['ALL'],
            'whitespace' => [' all'],
        ];
    }
}
