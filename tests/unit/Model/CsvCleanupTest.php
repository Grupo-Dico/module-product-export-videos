<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Model;

use LeanCommerce\ProductVideoExport\Model\CsvCleanupRegistry;
use LeanCommerce\ProductVideoExport\Model\CsvFileClaim;
use LeanCommerce\ProductVideoExport\Model\OwnedCsv;
use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use LeanCommerce\ProductVideoExport\Plugin\ConsumerCleanupPlugin;
use LeanCommerce\ProductVideoExport\Plugin\ExportCleanupPlugin;
use LeanCommerce\ProductVideoExport\Test\Unit\Model\Fixture\MemoryExportDirectory;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\ImportExport\Api\Data\LocalizedExportInfoInterface;
use Magento\ImportExport\Model\Export;
use Magento\ImportExport\Model\Export\Consumer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use WeakReference;

require_once __DIR__ . '/../cleanup-stubs.php';
require_once __DIR__ . '/Fixture/MemoryExportDirectory.php';

final class CsvCleanupTest extends TestCase
{
    private CsvCleanupRegistry $registry;
    private ExportCleanupPlugin $exportPlugin;
    private ConsumerCleanupPlugin $consumerPlugin;
    private MemoryExportDirectory $directory;

    protected function setUp(): void
    {
        $this->registry = new CsvCleanupRegistry();
        $this->directory = new MemoryExportDirectory();
        $parameters = new PresenceParameters();
        $logger = new NullLogger();
        $this->exportPlugin = new ExportCleanupPlugin($this->registry, $parameters, $logger);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('unserialize')->willReturnCallback(
            static fn ($value) => json_decode($value, true, 512, JSON_THROW_ON_ERROR)
        );
        $this->consumerPlugin = new ConsumerCleanupPlugin($this->registry, $parameters, $serializer, $logger);
    }

    private function model(string $filename = '', bool $enabled = true): Export
    {
        return new Export([
            'entity' => 'catalog_product',
            'file_format' => 'csv',
            'file_name' => $filename,
            'export_filter' => $enabled ? [PresenceParameters::KEY => 'all'] : [],
        ]);
    }

    private function adapter(string $path): OwnedCsv
    {
        $claim = new CsvFileClaim($this->directory, $path, $this->directory->openFile($path, 'x'));
        $adapter = new OwnedCsv(new Filesystem($this->directory), $path);
        $adapter->claimDestination($claim);
        $this->registry->register($adapter);
        $this->directory->files[$path] = 'native CSV bytes';
        return $adapter;
    }

    private function info(bool $enabled = true): LocalizedExportInfoInterface
    {
        $info = $this->createMock(LocalizedExportInfoInterface::class);
        $info->method('getEntity')->willReturn('catalog_product');
        $info->method('getFileFormat')->willReturn('csv');
        $info->method('getExportFilter')->willReturn(json_encode($enabled ? [PresenceParameters::KEY => 'all'] : []));
        return $info;
    }

    public function testMetadataWriterIsKeptUntilDirectExportCompletes(): void
    {
        $model = $this->model();
        $adapter = null;
        $name = $this->exportPlugin->aroundGetFileName($model, function () use (&$adapter) {
            $adapter = $this->adapter('importexport_direct');
            return 'catalog_product.csv';
        });
        self::assertSame('catalog_product.csv', $name);
        self::assertTrue($this->directory->isFile('importexport_direct'));
        $this->exportPlugin->aroundGetContentType($model, static fn () => 'text/csv');
        $result = $this->exportPlugin->aroundExport($model, static fn () => $adapter->getContents());
        self::assertSame('native CSV bytes', $result);
        self::assertFalse($this->directory->isFile('importexport_direct'));
        self::assertFalse($this->registry->isActive());
    }

    public function testFailureAfterGetContentsRemovesFileWithoutAHandleAndPreservesCause(): void
    {
        $failure = new RuntimeException('native failure');
        try {
            $this->exportPlugin->aroundExport($this->model(), function () use ($failure) {
                $this->adapter('importexport_failure')->getContents();
                throw $failure;
            });
            self::fail('Expected original failure');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertFalse($this->directory->isFile('importexport_failure'));
        self::assertFalse($this->registry->isActive());
    }

    public function testZeroRowsStillCleansOnlyTheOwnedTemporary(): void
    {
        $this->directory->files['export/preexisting.csv'] = 'untouched';
        $result = $this->exportPlugin->aroundExport($this->model(), function () {
            $adapter = $this->adapter('importexport_empty');
            $this->directory->files['importexport_empty'] = '';
            return $adapter->getContents();
        });
        self::assertSame('', $result);
        self::assertSame(['export/preexisting.csv' => 'untouched'], $this->directory->files);
    }

    public function testQueueSuccessKeepsTemporaryUntilNativePublication(): void
    {
        $this->consumerPlugin->aroundProcess(new Consumer(), function () {
            $this->exportPlugin->aroundExport($this->model('ready.csv'), function () {
                return $this->adapter('export/ready.csv.tmp')->getContents();
            });
            self::assertTrue($this->directory->isFile('export/ready.csv.tmp'));
            $this->directory->files['export/ready.csv'] = $this->directory->files['export/ready.csv.tmp'];
            unset($this->directory->files['export/ready.csv.tmp']);
        }, $this->info());
        self::assertSame(['export/ready.csv' => 'native CSV bytes'], $this->directory->files);
    }

    public function testConsumerCaughtRenameFailureStillCleansRemainingTemporary(): void
    {
        $this->directory->files['export/ready.csv'] = 'previous final';
        $this->consumerPlugin->aroundProcess(new Consumer(), function () {
            $this->exportPlugin->aroundExport($this->model('ready.csv'), function () {
                return $this->adapter('export/ready.csv.tmp')->getContents();
            });
            // The native consumer may catch a publication error and return normally.
        }, $this->info());
        self::assertSame(['export/ready.csv' => 'previous final'], $this->directory->files);
    }

    public function testEscapingConsumerFailureCleansAndPreservesOriginalCause(): void
    {
        $failure = new RuntimeException('consumer failed');
        try {
            $this->consumerPlugin->aroundProcess(new Consumer(), function () use ($failure) {
                $this->exportPlugin->aroundExport($this->model('failed.csv'), function () {
                    return $this->adapter('export/failed.csv.tmp')->getContents();
                });
                throw $failure;
            }, $this->info());
            self::fail('Expected consumer exception');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame([], $this->directory->files);
        self::assertFalse($this->registry->isActive());
    }

    public function testNestedConsumersOnlyCleanTheirOwnRegisteredFiles(): void
    {
        $this->consumerPlugin->aroundProcess(new Consumer(), function () {
            $this->exportPlugin->aroundExport($this->model('outer.csv'), function () {
                return $this->adapter('export/outer.csv.tmp')->getContents();
            });
            $this->consumerPlugin->aroundProcess(new Consumer(), function () {
                $this->exportPlugin->aroundExport($this->model('inner.csv'), function () {
                    return $this->adapter('export/inner.csv.tmp')->getContents();
                });
            }, $this->info());
            self::assertTrue($this->directory->isFile('export/outer.csv.tmp'));
            self::assertFalse($this->directory->isFile('export/inner.csv.tmp'));
        }, $this->info());
        self::assertSame([], $this->directory->files);
    }

    public function testMalformedLegacyPayloadKeepsNativeValidation(): void
    {
        $info = $this->createMock(LocalizedExportInfoInterface::class);
        $info->method('getEntity')->willReturn('catalog_product');
        $info->method('getFileFormat')->willReturn('csv');
        $info->method('getExportFilter')->willReturn('not json');
        $failure = new RuntimeException('native malformed payload');
        try {
            $this->consumerPlugin->aroundProcess(new Consumer(), static function ($actualInfo) use ($info, $failure) {
                self::assertSame($info, $actualInfo);
                throw $failure;
            }, $info);
            self::fail('Expected native validation');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame([], $this->directory->opened);
    }

    public function testLegacyModelAndConsumerAreUnchanged(): void
    {
        $result = $this->consumerPlugin->aroundProcess(new Consumer(), function ($info) {
            self::assertInstanceOf(LocalizedExportInfoInterface::class, $info);
            return $this->exportPlugin->aroundExport($this->model('', false), function () {
                self::assertFalse($this->registry->isActive());
                return 'legacy bytes';
            });
        }, $this->info(false));
        self::assertSame('legacy bytes', $result);
        self::assertSame([], $this->directory->opened);
    }

    public function testNestedExportsAndFollowingJobAreIsolated(): void
    {
        $this->exportPlugin->aroundExport($this->model(), function () {
            $this->adapter('importexport_outer');
            $this->exportPlugin->aroundExport($this->model(), function () {
                return $this->adapter('importexport_inner')->getContents();
            });
            self::assertTrue($this->directory->isFile('importexport_outer'));
            self::assertFalse($this->directory->isFile('importexport_inner'));
            self::assertTrue($this->registry->isActive());
        });
        self::assertFalse($this->registry->isActive());
        $this->exportPlugin->aroundExport($this->model(), function () {
            return $this->adapter('importexport_following')->getContents();
        });
        self::assertSame([], $this->directory->files);
    }

    public function testMetadataScopeDoesNotKeepExportModelAlive(): void
    {
        $model = $this->model();
        $reference = WeakReference::create($model);
        $this->exportPlugin->aroundGetContentType($model, function () {
            $this->adapter('importexport_metadata');
            return 'text/csv';
        });
        unset($model);
        self::assertNull($reference->get());
        self::assertFalse($this->registry->isActive());
        self::assertFalse($this->directory->isFile('importexport_metadata'));
    }

    public function testNestedLegacyFailureTemporarilySuspendsOuterEnrichedScope(): void
    {
        $this->exportPlugin->aroundExport($this->model(), function () {
            $this->adapter('importexport_outer');
            try {
                $this->exportPlugin->aroundExport($this->model('', false), function () {
                    self::assertFalse($this->registry->isActive());
                    throw new RuntimeException('legacy failure');
                });
                self::fail('Expected legacy exception');
            } catch (RuntimeException $failure) {
                self::assertSame('legacy failure', $failure->getMessage());
            }
            self::assertTrue($this->registry->isActive());
        });
        self::assertSame([], $this->directory->files);
        self::assertFalse($this->registry->isActive());
    }

    public function testCleanupFailurePreventsDirectSuccess(): void
    {
        $this->expectExceptionMessage('cleanup failed');
        $this->exportPlugin->aroundExport($this->model(), function () {
            $adapter = $this->adapter('importexport_cleanup_failure');
            $contents = $adapter->getContents();
            $this->directory->failDelete = true;
            return $contents;
        });
    }

    public function testCleanupFailureDoesNotHideOriginalExportFailure(): void
    {
        $original = new RuntimeException('original');
        try {
            $this->exportPlugin->aroundExport($this->model(), function () use ($original) {
                $this->adapter('importexport_double_failure')->getContents();
                $this->directory->failDelete = true;
                throw $original;
            });
            self::fail('Expected original');
        } catch (RuntimeException $actual) {
            self::assertSame($original, $actual);
        }
        self::assertFalse($this->registry->isActive());
    }

    public function testConsumerCleanupDoesNotDeleteReusedPathAfterModelFailure(): void
    {
        $model = $this->model('same.csv');
        $path = 'export/same.csv.tmp';
        $this->registry->enterConsumer();
        $this->registry->enterModel($model);
        $adapter = $this->adapter($path);
        $adapter->getContents();
        $this->registry->finishModel($model, true);
        $this->registry->leaveModel();
        self::assertFalse($this->directory->isFile($path));
        $other = $this->directory->openFile($path, 'x');
        $this->directory->files[$path] = 'job B';
        $this->registry->leaveConsumer();
        self::assertSame('job B', $this->directory->readFile($path));
        self::assertSame([$path], $this->directory->deleted);
        $other->close();
    }

    public function testConsumerCleanupDoesNotDeleteReusedPathAfterNativeRename(): void
    {
        $model = $this->model('same.csv');
        $path = 'export/same.csv.tmp';
        $this->registry->enterConsumer();
        $this->registry->enterModel($model);
        $adapter = $this->adapter($path);
        $adapter->getContents();
        $this->registry->finishModel($model, false);
        $this->registry->leaveModel();
        $this->directory->renameFile($path, 'export/same.csv');
        $other = $this->directory->openFile($path, 'x');
        $this->directory->files[$path] = 'job B';
        $this->registry->leaveConsumer();
        self::assertSame('job B', $this->directory->readFile($path));
        self::assertSame('native CSV bytes', $this->directory->readFile('export/same.csv'));
        self::assertSame([], $this->directory->deleted);
        $other->close();
    }
}
