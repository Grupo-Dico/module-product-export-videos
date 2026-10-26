<?php
/** Native CSV/consumer file lifecycle probe; no catalog or queue writes. */
declare(strict_types=1);

use LeanCommerce\ProductVideoExport\Model\CsvCleanupRegistry;
use LeanCommerce\ProductVideoExport\Model\CsvFileClaim;
use LeanCommerce\ProductVideoExport\Model\OwnedCsv;
use LeanCommerce\ProductVideoExport\Model\ScopedAdapterFactory;
use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use LeanCommerce\ProductVideoExport\Plugin\ConsumerCleanupPlugin;
use LeanCommerce\ProductVideoExport\Plugin\ExportCleanupPlugin;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Notification\NotifierInterface;
use Magento\ImportExport\Api\Data\ExportInfoInterface;
use Magento\ImportExport\Api\Data\LocalizedExportInfoInterface;
use Magento\ImportExport\Api\ExportManagementInterface;
use Magento\ImportExport\Model\Export;
use Magento\ImportExport\Model\Export\Adapter\Csv;
use Magento\ImportExport\Model\Export\Consumer;
use Magento\ImportExport\Model\Export\FileInfo;
use Psr\Log\NullLogger;

define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once (getenv('LCVX_RUNNER_SUPPORT') ?: __DIR__ . '/../../tests/integration/runner-support.php');
$root = lcvxIntegrationRoot();
$moduleRoot = rtrim(getenv('LCVX_MODULE_ROOT') ?: ($argv[1] ?? ''), '/') . '/';
$temporary = '/tmp/LCVX-csv-cleanup-runtime-' . bin2hex(random_bytes(8));
$report = [
    'case' => 'M04-native-csv-cleanup',
    'php' => PHP_VERSION,
    'catalog_writes' => false,
    'queue_messages' => false,
    'native_consumer_with_fixture_export_manager_and_notifier' => true,
    'results' => [],
    'limits' => [
        'Workflow plugins invoked explicitly; real interceptor discovery is outside this probe and requires the full export probe.',
        'No real queue transport, Admin, download or browser application acceptance.',
        'Read and rename failures injected only on an isolated directory adapter; native CSV and Consumer code execute.',
        'PHP 8.3 matches the confirmed client target; this probe does not certify the client database or installation.',
    ],
];
$connection = null;
$exitCode = 1;
$stage = 'bootstrap';

function lcvxCsvAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('LCVX: ' . $message);
    }
}

function lcvxCsvRemoveOwnedTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

try {
    lcvxCsvAssert(is_file($moduleRoot . 'Model/OwnedCsv.php'), 'Expected explicitly supplied module snapshot.');
    lcvxCsvAssert(mkdir($temporary, 0700), 'Could not create owned runtime.');
    require $root . '/app/bootstrap.php';
    $loader = require $root . '/vendor/autoload.php';
    $loader->addPsr4('LeanCommerce\\ProductVideoExport\\', $moduleRoot);
    $params = $_SERVER;
    $paths = [];
    foreach (['var', 'var_export', 'import_export', 'log', 'cache', 'session', 'tmp', 'generated', 'code', 'metadata'] as $code) {
        mkdir($temporary . '/' . $code, 0700);
        $paths[$code] = ['path' => $temporary . '/' . $code];
    }
    $params[Bootstrap::INIT_PARAM_FILESYSTEM_DIR_PATHS] = $paths;
    $params[ObjectManagerFactory::INIT_PARAM_DEPLOYMENT_CONFIG] = [
        'cache' => ['frontend' => [
            'default' => ['backend' => 'Cm_Cache_Backend_File', 'backend_options' => ['cache_dir' => $temporary . '/cache']],
            'page_cache' => ['backend' => 'Cm_Cache_Backend_File', 'backend_options' => ['cache_dir' => $temporary . '/cache']],
        ]],
        'session' => ['save' => 'files', 'save_path' => $temporary . '/session'],
        'lock' => ['provider' => 'file', 'config' => ['path' => $temporary . '/tmp']],
    ];
    class LcvxCsvEnvironmentFactory extends Magento\Framework\App\EnvironmentFactory
    {
        public function createEnvironment()
        {
            return new Magento\Framework\App\ObjectManager\Environment\Developer($this);
        }
    }
    $objectManagerFactory = new class(
        Bootstrap::createFilesystemDirectoryList(BP, $params),
        Bootstrap::createFilesystemDriverPool($params),
        Bootstrap::createConfigFilePool()
    ) extends ObjectManagerFactory {
        protected $envFactoryClassName = LcvxCsvEnvironmentFactory::class;
    };
    $om = Bootstrap::create(BP, $params, $objectManagerFactory)->getObjectManager();
    $connection = $om->get(ResourceConnection::class)->getConnection();
    $connection->query('SET SESSION TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $report['database_transaction_read_only'] = true;
    foreach (['cache', 'log', 'code', 'metadata', 'import_export'] as $code) {
        lcvxCsvAssert(str_starts_with($om->get(DirectoryList::class)->getPath($code), $temporary . '/'), 'Runtime isolation failed.');
    }
    $report['source_sha256'] = [];
    foreach (['Model/OwnedCsv.php', 'Model/CsvFileClaim.php', 'Model/CsvCleanupScope.php', 'Model/CsvCleanupRegistry.php', 'Model/ScopedAdapterFactory.php', 'Plugin/ExportCleanupPlugin.php', 'Plugin/ConsumerCleanupPlugin.php'] as $path) {
        $report['source_sha256'][$path] = hash_file('sha256', $moduleRoot . $path);
    }
    $filesystem = $om->get(Filesystem::class);
    $directory = $filesystem->getDirectoryWrite(DirectoryList::VAR_IMPORT_EXPORT);
    $registry = $om->get(CsvCleanupRegistry::class);
    $factory = $om->create(ScopedAdapterFactory::class);
    $exportPlugin = $om->create(ExportCleanupPlugin::class, ['logger' => new NullLogger()]);
    $consumerPlugin = $om->create(ConsumerCleanupPlugin::class, ['logger' => new NullLogger()]);
    $model = static function (string $filename = '', bool $enabled = true) use ($om): Export {
        return $om->create(Export::class)->setData([
            'entity' => 'catalog_product',
            'file_format' => 'csv',
            'file_name' => $filename,
            'export_filter' => $enabled ? [PresenceParameters::KEY => 'all'] : [],
        ]);
    };
    $row = [
        'equal' => '=1+1', 'plus' => '+2', 'minus' => '-3',
        'video_urls' => 'https://example.test/watch?v=a,b&x="quoted"|https://example.test/á',
        'video_titles' => "Árbol, \"línea\"\nsegunda|barra\\final",
    ];
    $stage = 'native-parity';
    $native = new Csv($filesystem, 'native.csv');
    $native->writeRow($row);
    $expected = $native->getContents();
    $direct = $model();
    $adapter = null;
    $exportPlugin->aroundGetFileName($direct, function () use (&$adapter, $factory) {
        $adapter = $factory->create(Csv::class);
        return 'catalog_product.csv';
    });
    lcvxCsvAssert($adapter instanceof OwnedCsv, 'Factory did not select scoped OwnedCsv.');
    $actual = $exportPlugin->aroundExport($direct, function () use ($adapter, $row) {
        $adapter->writeRow($row);
        return $adapter->getContents();
    });
    lcvxCsvAssert($actual === $expected, 'OwnedCsv changed native bytes.');
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $actual);
    rewind($stream);
    fgetcsv($stream, 0, ',', '"', "\0");
    $parsed = fgetcsv($stream, 0, ',', '"', "\0");
    fclose($stream);
    lcvxCsvAssert(array_slice($parsed, 0, 3) === [' =1+1', ' +2', ' -3'], 'Native formula prefix behavior differs.');
    lcvxCsvAssert(array_slice($parsed, 3) === array_slice(array_values($row), 3), 'CSV escaping changed values.');
    lcvxCsvAssert($directory->read() === ['native.csv'], 'Direct temporary remained after getContents.');
    $directory->delete('native.csv');
    $report['results']['direct_metadata_native_bytes_and_cleanup'] = ['status' => 'PASS', 'bytes' => strlen($actual), 'sha256' => hash('sha256', $actual)];

    // This fixture is a native directory with failures restricted to its own files.
    $pool = $om->get(DriverPool::class);
    $faultDirectory = new class(new Magento\Framework\Filesystem\File\WriteFactory($pool), $pool->getDriver(DriverPool::FILE), $directory->getAbsolutePath()) extends Write {
        public ?string $failRead = null;
        public ?string $failWrite = null;
        public ?string $failRename = null;
        public function readFile($path, $flag = null, $context = null)
        {
            if ($path === $this->failRead) {
                throw new FileSystemException(__('LCVX injected read failure'));
            }
            return parent::readFile($path, $flag, $context);
        }
        public function openFile($path, $mode = 'w')
        {
            if ($mode === 'w' && $path === $this->failWrite) {
                throw new FileSystemException(__('LCVX injected constructor failure'));
            }
            return parent::openFile($path, $mode);
        }
        public function renameFile($path, $newPath, Magento\Framework\Filesystem\Directory\WriteInterface|null $targetDirectory = null)
        {
            if ($path === $this->failRename) {
                throw new FileSystemException(__('LCVX injected rename failure'));
            }
            return parent::renameFile($path, $newPath, $targetDirectory);
        }
    };
    $faultFilesystem = new class($faultDirectory) extends Filesystem {
        public function __construct(private readonly Write $directory)
        {
        }
        public function getDirectoryWrite($directoryCode, $driverCode = DriverPool::FILE)
        {
            return $this->directory;
        }
    };
    $stage = 'read-failure';
    $faultDirectory->failRead = 'read-failure.csv';
    try {
        $exportPlugin->aroundExport($model(), function () use ($faultFilesystem, $faultDirectory, $registry, $row) {
            $claim = new CsvFileClaim($faultDirectory, 'read-failure.csv', $faultDirectory->openFile('read-failure.csv', 'x'));
            $writer = new OwnedCsv($faultFilesystem, 'read-failure.csv');
            $writer->claimDestination($claim);
            $registry->register($writer);
            $writer->writeRow($row);
            return $writer->getContents();
        });
        throw new RuntimeException('Expected read failure.');
    } catch (FileSystemException $failure) {
        lcvxCsvAssert($failure->getMessage() === 'LCVX injected read failure', 'Original read failure was replaced.');
    }
    lcvxCsvAssert(!$directory->isFile('read-failure.csv'), 'Failed read leaked a closed-handle temporary.');
    $report['results']['getContents_failure_after_native_handle_close'] = ['status' => 'PASS'];

    $stage = 'constructor-failure';
    $faultDirectory->failWrite = 'construction-failure.csv';
    $faultFactory = $om->create(ScopedAdapterFactory::class, ['filesystem' => $faultFilesystem]);
    try {
        $exportPlugin->aroundExport($model(), static fn () => $faultFactory->create(Csv::class, [
            'filesystem' => $faultFilesystem, 'destination' => 'construction-failure.csv',
        ]));
        throw new RuntimeException('Expected constructor failure.');
    } catch (FileSystemException $failure) {
        lcvxCsvAssert($failure->getMessage() === 'LCVX injected constructor failure', 'Original constructor failure was replaced.');
    }
    lcvxCsvAssert(!$directory->isFile('construction-failure.csv'), 'Constructor failure leaked a reservation.');
    $report['results']['native_constructor_failure_removes_reserved_path'] = ['status' => 'PASS'];

    $stage = 'existing-destination';
    $directory->writeFile('existing.csv', 'other owner');
    try {
        $exportPlugin->aroundExport($model(), static fn () => $factory->create(Csv::class, ['destination' => 'existing.csv']));
        throw new RuntimeException('Expected collision failure.');
    } catch (LocalizedException $failure) {
        lcvxCsvAssert($failure->getMessage() === 'The product video export temporary file already exists.', 'Unexpected collision failure.');
    }
    lcvxCsvAssert($directory->readFile('existing.csv') === 'other owner', 'Existing file changed.');
    $directory->delete('existing.csv');
    $report['results']['preexisting_destination_preserved'] = ['status' => 'PASS'];

    $stage = 'legacy';
    $legacyBytes = $exportPlugin->aroundExport($model('', false), function () use ($factory, $row) {
        $writer = $factory->create(Csv::class, ['destination' => 'legacy.csv']);
        lcvxCsvAssert(get_class($writer) === Csv::class, 'Legacy adapter replaced.');
        $writer->writeRow($row);
        return $writer->getContents();
    });
    lcvxCsvAssert($legacyBytes === $expected && $directory->isFile('legacy.csv'), 'Legacy bytes or lifecycle changed.');
    $directory->delete('legacy.csv');
    $report['results']['legacy_exact_native_class_bytes_and_lifecycle'] = ['status' => 'PASS'];

    $notifier = new class implements NotifierInterface {
        public int $major = 0;
        public int $critical = 0;
        public function add($severity, $title, $description, $url = '', $isInternal = true) { return $this; }
        public function addCritical($title, $description, $url = '', $isInternal = true) { $this->critical++; return $this; }
        public function addMajor($title, $description, $url = '', $isInternal = true) { $this->major++; return $this; }
        public function addMinor($title, $description, $url = '', $isInternal = true) { return $this; }
        public function addNotice($title, $description, $url = '', $isInternal = true) { return $this; }
    };
    $exportManager = new class implements ExportManagementInterface {
        public Closure $execute;
        public function export(ExportInfoInterface $exportInfo) { return ($this->execute)($exportInfo); }
    };
    $consumer = new Consumer(new NullLogger(), $exportManager, $faultFilesystem, $notifier, $om->get(FileInfo::class));
    foreach (['success', 'caught-rename-failure'] as $variant) {
        $stage = 'native-consumer-' . $variant;
        $filename = 'LCVX-' . $variant . '.csv';
        $path = 'export/' . $filename . '.tmp';
        $faultDirectory->failRename = $variant === 'success' ? null : $path;
        if ($variant !== 'success') {
            $directory->writeFile('export/' . $filename, 'previous final');
        }
        $info = $om->create(LocalizedExportInfoInterface::class);
        $info->setEntity('catalog_product');
        $info->setFileFormat('csv');
        $info->setFileName($filename);
        $info->setExportFilter(json_encode([PresenceParameters::KEY => 'all']));
        $exportManager->execute = function () use ($filename, $path, $exportPlugin, $model, $factory, $row, $directory) {
            $exportPlugin->aroundExport($model($filename), function () use ($factory, $path, $row) {
                $writer = $factory->create(Csv::class, ['destination' => $path]);
                $writer->writeRow($row);
                return $writer->getContents();
            });
            lcvxCsvAssert($directory->isFile($path), 'Queue temporary was removed before native publish.');
            return '__RESULT_WRITTEN_TO_FILE__';
        };
        $consumerPlugin->aroundProcess($consumer, [$consumer, 'process'], $info);
        lcvxCsvAssert(!$directory->isFile($path), 'Consumer left owned temporary.');
        $final = $directory->readFile('export/' . $filename);
        lcvxCsvAssert($final === ($variant === 'success' ? $expected : 'previous final'), 'Final file content changed incorrectly.');
        $directory->delete('export/' . $filename);
        $report['results']['native_consumer_' . $variant] = ['status' => 'PASS'];
    }
    lcvxCsvAssert($notifier->major === 1 && $notifier->critical === 1, 'Native notification branch differed.');
    foreach (['model-failure', 'publication-rename'] as $interleaving) {
        $stage = 'ownership-' . $interleaving;
        $path = 'export/LCVX-reused.csv.tmp';
        $final = 'export/LCVX-published.csv';
        $registry->enterConsumer();
        try {
            $exportPlugin->aroundExport($model('LCVX-reused.csv'), function () use ($factory, $path, $row, $interleaving) {
                $writer = $factory->create(Csv::class, ['destination' => $path]);
                $writer->writeRow($row);
                $writer->getContents();
                if ($interleaving === 'model-failure') {
                    throw new RuntimeException('LCVX expected model failure');
                }
            });
        } catch (RuntimeException $failure) {
            lcvxCsvAssert($failure->getMessage() === 'LCVX expected model failure', 'Unexpected interleaving failure.');
        }
        if ($interleaving === 'publication-rename') {
            $directory->renameFile($path, $final);
        }
        lcvxCsvAssert(!$directory->isFile($path), 'Job A did not release its path.');
        $otherReservation = $directory->openFile($path, 'x');
        $otherWriter = new Csv($filesystem, $path);
        $otherWriter->writeRow(['owner' => 'unrelated job B']);
        $otherBytes = $otherWriter->getContents();
        $registry->leaveConsumer();
        lcvxCsvAssert($directory->isFile($path) && $directory->readFile($path) === $otherBytes, 'Cleanup removed job B.');
        if ($interleaving === 'publication-rename') {
            lcvxCsvAssert($directory->readFile($final) === $expected, 'Published job A changed.');
            $directory->delete($final);
        }
        $otherReservation->close();
        $directory->delete($path);
        $report['results']['ownership_' . $interleaving] = [
            'status' => 'PASS',
            'recreated_path_survived' => true,
            'actual_native_csv_and_local_filesystem' => true,
            'deterministic_interleaving_not_parallel_processes' => true,
        ];
    }
    lcvxCsvAssert(!$registry->isActive(), 'Export scope leaked.');
    lcvxCsvAssert($directory->read('export') === [], 'Owned queue files remained.');
    $report['native_notifications_to_fixture'] = ['major' => $notifier->major, 'critical' => $notifier->critical];
    $report['status'] = 'PASS';
    $exitCode = 0;
} catch (Throwable $failure) {
    $report['status'] = 'FAIL';
    $report['failure'] = ['stage' => $stage, 'class' => get_class($failure), 'message' => $failure->getMessage()];
} finally {
    if ($connection !== null && $connection->getTransactionLevel() > 0) {
        $connection->rollBack();
    }
    try {
        lcvxCsvRemoveOwnedTree($temporary);
        $report['runtime_removed'] = !file_exists($temporary);
    } catch (Throwable $failure) {
        $report['runtime_removed'] = false;
        $report['cleanup_failure'] = $failure->getMessage();
        $exitCode = 1;
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($exitCode);
