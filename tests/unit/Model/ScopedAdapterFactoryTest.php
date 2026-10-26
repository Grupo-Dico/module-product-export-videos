<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Model;

use LeanCommerce\ProductVideoExport\Model\CsvCleanupRegistry;
use LeanCommerce\ProductVideoExport\Model\OwnedCsv;
use LeanCommerce\ProductVideoExport\Model\OwnedCsvFactory;
use LeanCommerce\ProductVideoExport\Model\ScopedAdapterFactory;
use LeanCommerce\ProductVideoExport\Test\Unit\Model\Fixture\MemoryExportDirectory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Math\Random;
use Magento\ImportExport\Model\Export;
use Magento\ImportExport\Model\Export\Adapter\Csv;
use Magento\ImportExport\Model\Export\Adapter\Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__ . '/../cleanup-stubs.php';
require_once __DIR__ . '/Fixture/MemoryExportDirectory.php';

final class ScopedAdapterFactoryTest extends TestCase
{
    private CsvCleanupRegistry $registry;
    private MemoryExportDirectory $directory;
    private Factory $native;
    private OwnedCsvFactory $owned;
    private ScopedAdapterFactory $factory;
    private Random $random;

    protected function setUp(): void
    {
        $this->registry = new CsvCleanupRegistry();
        $this->directory = new MemoryExportDirectory();
        $this->native = $this->createMock(Factory::class);
        $this->owned = $this->createMock(OwnedCsvFactory::class);
        $this->random = $this->createMock(Random::class);
        $this->random->method('getUniqueHash')->with('importexport_lc_')->willReturn('importexport_lc_test');
        $this->factory = new ScopedAdapterFactory(
            $this->native,
            $this->owned,
            $this->registry,
            new Filesystem($this->directory),
            $this->random,
            new NullLogger()
        );
    }

    public function testInactiveNativeCsvIsDelegatedWithExactArguments(): void
    {
        $arguments = ['destination' => 'export/legacy.csv.tmp'];
        $expected = new \stdClass();
        $this->native->expects(self::once())->method('create')->with(Csv::class, $arguments)->willReturn($expected);
        $this->owned->expects(self::never())->method('create');
        self::assertSame($expected, $this->factory->create(Csv::class, $arguments));
        self::assertSame([], $this->directory->opened);
    }

    public function testCustomAdapterIsNeverSubstitutedEvenInsideScope(): void
    {
        $this->registry->enterModel(new Export());
        $this->native->expects(self::once())->method('create')->with('Vendor\\CustomCsv', []);
        $this->owned->expects(self::never())->method('create');
        $this->factory->create('Vendor\\CustomCsv');
        $this->registry->leaveModel();
        self::assertSame([], $this->directory->opened);
    }

    public function testQueueDestinationIsExclusivelyClaimedThenWrittenByNativeConstructor(): void
    {
        $model = new Export();
        $this->registry->enterModel($model);
        $arguments = ['destination' => 'export/owned.csv.tmp'];
        $this->owned->expects(self::once())->method('create')->with($arguments)->willReturnCallback(
            fn ($data) => new OwnedCsv(new Filesystem($this->directory), $data['destination'])
        );
        $adapter = $this->factory->create(Csv::class, $arguments);
        self::assertInstanceOf(OwnedCsv::class, $adapter);
        self::assertSame([
            ['export/owned.csv.tmp', 'x'],
            ['export/owned.csv.tmp', 'w'],
        ], $this->directory->opened);
        $this->registry->finishModel($model, true);
        $this->registry->leaveModel();
        self::assertSame([], $this->directory->files);
    }

    public function testPreexistingTemporaryIsNotTruncatedOrDeleted(): void
    {
        $this->registry->enterModel(new Export());
        $this->directory->files['export/existing.csv.tmp'] = 'other owner';
        $this->owned->expects(self::never())->method('create');
        try {
            $this->factory->create(Csv::class, ['destination' => 'export/existing.csv.tmp']);
            self::fail('Expected collision');
        } catch (LocalizedException $failure) {
            self::assertSame('The product video export temporary file already exists.', $failure->getMessage());
        }
        self::assertSame(['export/existing.csv.tmp' => 'other owner'], $this->directory->files);
        self::assertSame([], $this->directory->opened);
        self::assertSame([], $this->directory->deleted);
    }

    public function testCollisionBetweenExistenceCheckAndExclusiveOpenDoesNotDeleteOtherFile(): void
    {
        $this->registry->enterModel(new Export());
        $this->directory->raceOnExclusiveOpen = true;
        $this->owned->expects(self::never())->method('create');
        try {
            $this->factory->create(Csv::class, ['destination' => 'export/race.csv.tmp']);
            self::fail('Expected exclusive creation failure');
        } catch (RuntimeException $failure) {
            self::assertSame('exclusive open failed', $failure->getMessage());
        }
        self::assertSame(['export/race.csv.tmp' => 'other owner'], $this->directory->files);
        self::assertSame([], $this->directory->deleted);
    }

    public function testConstructionFailureCleansOnlyTheReservationAndPreservesException(): void
    {
        $this->registry->enterModel(new Export());
        $this->directory->files['export/final.csv'] = 'keep';
        $failure = new RuntimeException('constructor failed');
        $this->owned->method('create')->willThrowException($failure);
        try {
            $this->factory->create(Csv::class, ['destination' => 'export/new.csv.tmp']);
            self::fail('Expected original failure');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame(['export/final.csv' => 'keep'], $this->directory->files);
    }

    public function testDirectConstructionFailureCleansTheKnownGeneratedReservation(): void
    {
        $this->registry->enterModel(new Export());
        $this->directory->files['unrelated.csv'] = 'keep';
        $failure = new RuntimeException('direct constructor failed');
        $this->owned->expects(self::once())->method('create')
            ->with(['destination' => 'importexport_lc_test'])->willThrowException($failure);
        try {
            $this->factory->create(Csv::class);
            self::fail('Expected original failure');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame([['importexport_lc_test', 'x']], $this->directory->opened);
        self::assertSame(['importexport_lc_test'], $this->directory->deleted);
        self::assertSame(['unrelated.csv' => 'keep'], $this->directory->files);
    }
}
