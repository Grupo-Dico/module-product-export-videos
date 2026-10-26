<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Domain;

use InvalidArgumentException;
use LeanCommerce\ProductVideoExport\Domain\VideoListCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class VideoListCodecTest extends TestCase
{
    /** U04: independently specified wire values protect escape order and empty positions. */
    #[DataProvider('encodedCases')]
    public function testKnownEncodingAndDecoding(array $values, string $encoded): void
    {
        $codec = new VideoListCodec();
        self::assertSame($encoded, $codec->encode($values));
        self::assertSame($values, $codec->decode($encoded, count($values)));
    }

    public static function encodedCases(): array
    {
        return [
            'no videos' => [[], ''],
            'one empty' => [[''], ''],
            'two empty' => [['', ''], '|'],
            'extreme empty' => [['', 'middle', ''], '|middle|'],
            'pipe' => [['a|b'], 'a\\|b'],
            'slash' => [['a\\b'], 'a\\\\b'],
            'slash then pipe' => [['\\|'], '\\\\\\|'],
            'consecutive slash' => [['\\\\'], '\\\\\\\\'],
            'unicode and CSV content' => [['Vídeo 日本語 😀', 'a,"b"'], 'Vídeo 日本語 😀|a,"b"'],
        ];
    }

    /** U04: fixed local seed, real parser, no global RNG state or byte-based Unicode damage. */
    public function testSeededRoundTripForMixedLists(): void
    {
        $codec = new VideoListCodec();
        $random = new Randomizer(new Mt19937(20261020));
        $tokens = ['', 'a', '0', '\\', '|', '\\|', '"', ',', 'ñ', '日本語', '😀', "\r\n", "\t", ' ', '='];
        for ($case = 0; $case < 1000; $case++) {
            $values = [];
            $count = $random->getInt(0, 12);
            for ($entry = 0; $entry < $count; $entry++) {
                $value = '';
                $length = $random->getInt(0, 24);
                for ($token = 0; $token < $length; $token++) {
                    $value .= $tokens[$random->getInt(0, count($tokens) - 1)];
                }
                $values[] = $value;
            }
            self::assertSame($values, $codec->decode($codec->encode($values), $count), 'Seed 20261020, case ' . $case);
        }
    }

    #[DataProvider('invalidCases')]
    public function testMalformedListsAreNotSilentlyReinterpreted(string $encoded, int $count): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new VideoListCodec())->decode($encoded, $count);
    }

    public static function invalidCases(): array
    {
        return [
            'negative count' => ['', -1],
            'data with zero count' => ['a', 0],
            'trailing escape' => ['a\\', 1],
            'unknown escape' => ['a\\q', 1],
            'too few positions' => ['a', 2],
            'too many positions' => ['a|b', 1],
        ];
    }

    /** U03/U06: each CRLF is one space, without trim, collapse or formula treatment. */
    #[DataProvider('titleCases')]
    public function testTitleNormalization(string $input, string $expected): void
    {
        self::assertSame($expected, (new VideoListCodec())->normalizeTitle($input));
    }

    public static function titleCases(): array
    {
        return [
            'CRLF unit' => ["a\r\nb", 'a b'],
            'individual controls' => ["a\rb\nc\td", 'a b c d'],
            'adjacent controls' => ["\r\n\r\n\t\r\n", '    '],
            'spaces preserved' => [" a  b \t", ' a  b  '],
            'empty' => ['', ''],
            'zero string' => ['0', '0'],
            'formula remains logical content' => ['=1+1', '=1+1'],
            'other prefixes' => ["+x|-x|@x", '+x|-x|@x'],
            'leading controls become spaces only' => ["\t=1+1", ' =1+1'],
        ];
    }
}
