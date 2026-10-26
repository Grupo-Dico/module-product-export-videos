<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Model;

use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PresenceParametersTest extends TestCase
{
    #[DataProvider('validModes')]
    public function testAcceptsBothNativeTransportConventions(string $group, string $mode): void
    {
        $result = (new PresenceParameters())->resolve([$group => [PresenceParameters::KEY => $mode]]);

        self::assertSame($mode, $result?->getValue());
    }

    public static function validModes(): iterable
    {
        foreach (['export_filter', 'exportFilter'] as $group) {
            foreach (['all', 'with', 'without'] as $mode) {
                yield $group . '-' . $mode => [$group, $mode];
            }
        }
    }

    #[DataProvider('legacyParameters')]
    public function testAbsenceDoesNotChangeLegacyParameters(array $parameters): void
    {
        self::assertNull((new PresenceParameters())->resolve($parameters));
    }

    public static function legacyParameters(): iterable
    {
        yield 'empty' => [[]];
        yield 'native filters only' => [['export_filter' => ['sku' => 'EXAMPLE']]];
        yield 'factory filters only' => [['exportFilter' => ['sku' => 'EXAMPLE']]];
        yield 'native invalidity remains native' => [['export_filter' => 'invalid-native-container']];
        yield 'null native container' => [['export_filter' => null]];
        yield 'unrelated top-level key' => [[PresenceParameters::KEY => 'with']];
    }

    #[DataProvider('invalidModes')]
    public function testPresentInvalidValueNeverBecomesAll(string $group, mixed $mode): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Select a valid video presence option.');

        (new PresenceParameters())->resolve([$group => [PresenceParameters::KEY => $mode]]);
    }

    public static function invalidModes(): iterable
    {
        foreach (['export_filter', 'exportFilter'] as $group) {
            foreach ([null, '', false, true, 0, 1, [], ['all'], 'ALL', ' with', 'with ', 'unknown'] as $index => $mode) {
                yield $group . '-' . $index => [$group, $mode];
            }
        }
    }

    public function testSameValueAcrossBothConventionsIsAccepted(): void
    {
        $result = (new PresenceParameters())->resolve([
            'export_filter' => [PresenceParameters::KEY => 'without'],
            'exportFilter' => [PresenceParameters::KEY => 'without'],
        ]);

        self::assertSame('without', $result?->getValue());
    }

    public function testConflictingConventionsAreRejected(): void
    {
        $this->expectException(LocalizedException::class);

        (new PresenceParameters())->resolve([
            'export_filter' => [PresenceParameters::KEY => 'with'],
            'exportFilter' => [PresenceParameters::KEY => 'without'],
        ]);
    }

    public function testReusingResolverDoesNotRetainPreviousSelection(): void
    {
        $resolver = new PresenceParameters();
        self::assertSame('with', $resolver->resolve(['export_filter' => [PresenceParameters::KEY => 'with']])?->getValue());
        self::assertNull($resolver->resolve([]));
        self::assertSame('all', $resolver->resolve(['exportFilter' => [PresenceParameters::KEY => 'all']])?->getValue());
    }
}
