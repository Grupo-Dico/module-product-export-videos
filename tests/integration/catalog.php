<?php
/** Full-catalog completeness in one read-only snapshot; timings are diagnostic, not a repeated benchmark. */
declare(strict_types=1);

use Bss\CustomProductAttributeExport\Model\Rewrite\CatalogImportExport\Export\Product as BssExport;
use LeanCommerce\ProductVideoExport\Api\VideoReaderInterface;
use LeanCommerce\ProductVideoExport\Domain\VideoResult;
use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use LeanCommerce\ProductVideoExport\Model\ProductExport;
use LeanCommerce\ProductVideoExport\Model\ResourceModel\BatchVideoReader;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\ImportExport\Model\Export\Adapter\Csv;

$mode = getenv('LCVX_PERF_MODE') ?: 'bss';
$iteration = getenv('LCVX_PERF_RUN') ?: 'warmup';
define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once __DIR__ . '/runner-support.php';
$root = lcvxIntegrationRoot();
$temporary = '/tmp/LCVX-catalog-' . bin2hex(random_bytes(8));
$connection = null;
$writer = null;
$exitCode = 1;
$stage = 'bootstrap';
$report = [
    'channel' => 'Full-catalog direct model completeness in one read-only snapshot',
    'php' => PHP_VERSION, 'acceptance_threshold' => null, 'modes' => [],
    'limits' => [
        'One run per mode in the same database snapshot, not a repeated timing benchmark.',
        'No queue, browser, publication, download or spreadsheet acceptance.',
        'No CSV, SKU, title or URL is retained in the report; temporary CSV files are removed.',
        'Native page sizing is unchanged; parser validates rows incrementally to bound test memory.',
    ],
];

function lcvxPerfAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('LCVX: ' . $message);
    }
}

function lcvxPerfParse(string $csv): array
{
    if ($csv === '') {
        return [[], []];
    }
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $header = fgetcsv($stream, 0, ',', '"', "\0");
    lcvxPerfAssert(is_array($header), 'CSV header is missing.');
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', "\0")) !== false) {
        lcvxPerfAssert(count($row) === count($header) && $row !== $header, 'CSV is incomplete or repeats its header.');
        $rows[] = array_combine($header, $row);
    }
    fclose($stream);
    return [$header, $rows];
}

try {
    lcvxPerfAssert(in_array($mode, ['bss', 'all', 'with', 'without'], true), 'Unknown measurement mode.');
    lcvxPerfAssert(in_array($iteration, ['warmup', '1', '2', '3'], true), 'Unknown measurement iteration.');
    lcvxPerfAssert(mkdir($temporary, 0700), 'Could not create owned runtime.');
    require $root . '/app/bootstrap.php';
    $loader = require $root . '/vendor/autoload.php';
    $moduleRoot = rtrim(getenv('LCVX_MODULE_ROOT') ?: $root . '/var/lcvx-product-video-export/module', '/') . '/';
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
    if (getenv('LCVX_DB_HOST') !== false) {
        $installedDeployment = require $root . '/app/etc/env.php';
        $params[ObjectManagerFactory::INIT_PARAM_DEPLOYMENT_CONFIG]['db'] = $installedDeployment['db'];
        $params[ObjectManagerFactory::INIT_PARAM_DEPLOYMENT_CONFIG]['db']['connection']['default']['host'] = getenv('LCVX_DB_HOST');
        unset($installedDeployment);
    }
    class LcvxPerfEnvironmentFactory extends Magento\Framework\App\EnvironmentFactory
    {
        public function createEnvironment()
        {
            return new Magento\Framework\App\ObjectManager\Environment\Developer($this);
        }
    }
    $factory = new class(
        Bootstrap::createFilesystemDirectoryList(BP, $params),
        Bootstrap::createFilesystemDriverPool($params),
        Bootstrap::createConfigFilePool()
    ) extends ObjectManagerFactory {
        protected $envFactoryClassName = LcvxPerfEnvironmentFactory::class;
    };
    $om = Bootstrap::create(BP, $params, $factory)->getObjectManager();
    $om->configure([Magento\Framework\Logger\Handler\Base::class => ['arguments' => ['filePath' => $temporary]]]);
    $om->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    $om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('adminhtml'));
    $document = new DOMDocument();
    lcvxPerfAssert($document->load($moduleRoot . 'etc/di.xml'), 'Could not read module DI XML.');
    $om->configure($om->get(Magento\Framework\ObjectManager\Config\Mapper\Dom::class)->convert($document));
    foreach (['cache', 'log', 'code', 'metadata', 'import_export'] as $code) {
        lcvxPerfAssert(str_starts_with($om->get(DirectoryList::class)->getPath($code), $temporary . '/'), 'Writable runtime path is not isolated.');
    }
    $resource = $om->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $connection->query('SET SESSION TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $report['preflight'] = ['read_only_transaction' => true, 'memory_limit' => ini_get('memory_limit'), 'disk_free_bytes' => disk_free_space($temporary)];
    $report['source_sha256'] = [];
    foreach (['Model/ProductExport.php', 'Model/ResourceModel/BatchVideoReader.php', 'Model/ScopedVideoWriter.php', 'Domain/VideoAggregator.php'] as $file) {
        $report['source_sha256'][$file] = hash_file('sha256', $moduleRoot . $file);
    }


    $stage = 'full-universe';
    $link = $om->get(Magento\Framework\EntityManager\MetadataPool::class)->getMetadata(Magento\Catalog\Api\Data\ProductInterface::class)->getLinkField();
    $products = $connection->fetchAll($connection->select()->from($resource->getTableName('catalog_product_entity'), [$link, 'sku'])->order($link . ' ASC'));
    $ids = array_map(static fn (array $row): int => (int)$row[$link], $products);
    $skuToId = array_column($products, $link, 'sku');
    $idToSku = array_column($products, 'sku', $link);
    $stores = $om->get(Magento\Store\Model\StoreManagerInterface::class)->getStores(true);
    ksort($stores);
    $reader = $om->create(BatchVideoReader::class);
    $positive = [];
    foreach ($stores as $storeId => $store) {
        $code = (int)$storeId === 0 ? '' : $store->getCode();
        foreach (array_chunk($ids, 500) as $batch) {
            foreach ($reader->read($batch, (int)$storeId) as $id => $result) {
                if ($result->getCount() > 0) {
                    $positive[$idToSku[$id] . '|' . $code] = $result->toColumns();
                }
            }
        }
    }
    $zero = ['has_video' => 0, 'video_count' => 0, 'video_urls' => '', 'video_titles' => ''];
    $zeroSerialized = array_map('strval', $zero);
    $expectedWriter = $om->create(Csv::class, ['destination' => 'LCVX-catalog-expected.csv']);
    $expectedWriter->setHeaderCols(VideoResult::COLUMNS);
    foreach ($positive as $columns) { $expectedWriter->writeRow($columns); }
    [, $serialized] = lcvxPerfParse($expectedWriter->getContents());
    $expectedWriter->destruct();
    $serializedPositive = array_combine(array_keys($positive), $serialized);
    unset($expectedWriter, $serialized, $result, $batch);
    $candidateContexts = count($ids) * count($stores);
    $report['dataset'] = ['catalog_products' => count($ids), 'stores' => count($stores),
        'candidate_contexts' => $candidateContexts, 'positive_contexts' => count($positive),
        'negative_contexts' => $candidateContexts - count($positive),
        'universe_sha256' => hash('sha256', json_encode($ids)),
        'positive_video_snapshot_sha256' => hash('sha256', json_encode($positive)),
        'page_size' => 'native adaptive'];
    $report['preflight']['memory_available_kib'] = preg_match('/MemAvailable:\s+(\d+)/', (string)file_get_contents('/proc/meminfo'), $memory) ? (int)$memory[1] : null;
    // Resource guards protect the shared laboratory, not product performance acceptance.
    lcvxPerfAssert($report['preflight']['disk_free_bytes'] > 1024 * 1024 * 1024, 'Insufficient free disk for isolated full-catalog probe.');
    lcvxPerfAssert($report['preflight']['memory_available_kib'] > 1024 * 1024, 'Insufficient available host memory for isolated full-catalog probe.');
    class LcvxCatalogProfiler extends Magento\Framework\DB\Profiler
    {
        public int $queries = 0;
        public function queryEnd($queryId)
        {
            $result = parent::queryEnd($queryId);
            if ($result === self::STORED) {
                $this->queries++;
                unset($this->_queryProfiles[$queryId]);
                return self::IGNORED;
            }
            return $result;
        }
    }
    $nativeHashes = [];
    $nativeHeader = [];
    foreach (['bss', 'all', 'with', 'without'] as $currentMode) {
        $stage = 'export-' . $currentMode;
        $profiler = new LcvxCatalogProfiler(true);
        $instrumented = new class($reader, $profiler) implements VideoReaderInterface {
            public array $seen = [];
            public array $batches = [];
            public function __construct(private readonly BatchVideoReader $inner, private readonly LcvxCatalogProfiler $profiler) {}
            public function read(array $productIds, int $storeId): array
            {
                $before = $this->profiler->queries;
                $result = $this->inner->read($productIds, $storeId);
                lcvxPerfAssert($this->profiler->queries - $before === 1, 'Reader performed more than one query per batch/store.');
                foreach ($productIds as $id) {
                    lcvxPerfAssert(!isset($this->seen[$storeId][$id]), 'A product/store was read in more than one batch.');
                    $this->seen[$storeId][$id] = true;
                }
                $this->batches[] = ['store_id' => $storeId, 'products' => count($productIds), 'queries' => 1];
                return $result;
            }
        };
        $model = $om->create($currentMode === 'bss' ? BssExport::class : ProductExport::class, ['videoReader' => $instrumented]);
        $filters = $currentMode === 'bss' ? [] : [PresenceParameters::KEY => $currentMode];
        $model->setParameters(['entity' => 'catalog_product', 'export_filter' => $filters, 'skip_attr' => []]);
        $writer = $om->create(Csv::class, ['destination' => 'LCVX-catalog-' . $currentMode . '.csv']);
        $model->setWriter($writer);
        $connection->setProfiler($profiler);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $start = hrtime(true);
        $csv = $model->export();
        $seconds = (hrtime(true) - $start) / 1e9;
        $peak = memory_get_peak_usage(true);
        $profiler->setEnabled(false);
        $metrics = ['export_seconds' => $seconds, 'php_peak_bytes' => $peak, 'bytes' => strlen($csv),
            'csv_sha256' => hash('sha256', $csv), 'queries' => $profiler->queries,
            'reader_queries' => count($instrumented->batches),
            'reader_contexts' => array_sum(array_column($instrumented->batches, 'products'))];
        // php://temp spills large output instead of constructing an array of every cell.
        $stream = fopen('php://temp/maxmemory:2097152', 'w+');
        fwrite($stream, $csv);
        unset($csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', "\0");
        lcvxPerfAssert(is_array($header), 'Full catalog has no header.');
        if ($currentMode === 'bss') { $nativeHeader = $header; }
        else { lcvxPerfAssert($header === array_merge($nativeHeader, VideoResult::COLUMNS), 'Native headers changed.'); }
        $observed = [];
        $seenProducts = [];
        $matchedNative = 0;
        $rowCount = 0;
        while (($cells = fgetcsv($stream, 0, ',', '"', "\0")) !== false) {
            lcvxPerfAssert(count($cells) === count($header) && $cells !== $header, 'CSV width or repeated header error.');
            $row = array_combine($header, $cells);
            lcvxPerfAssert(isset($skuToId[$row['sku']]), 'Export escaped the full catalog snapshot.');
            $key = $row['sku'] . '|' . $row['store_view_code'];
            lcvxPerfAssert(!isset($observed[$key]), 'Duplicate product/store row in full catalog.');
            $observed[$key] = true;
            $seenProducts[$row['sku']] = true;
            $rowCount++;
            if ($currentMode === 'bss') {
                $nativeHashes[$key] = hash('sha256', json_encode($cells));
                continue;
            }
            $columns = array_intersect_key($row, array_flip(VideoResult::COLUMNS));
            $expected = $positive[$key] ?? $zero;
            if ($currentMode === 'with' || $currentMode === 'without') {
                lcvxPerfAssert(($currentMode === 'with') === ($expected['video_count'] > 0), 'Presence filter returned an incorrect context.');
            }
            if ($currentMode === 'all' && array_values($columns) === ['', '', '', '']) {
                lcvxPerfAssert($row['store_view_code'] !== '' && $expected === ($positive[$row['sku'] . '|'] ?? $zero), 'Blank block does not represent inheritance.');
            } else {
                lcvxPerfAssert($columns === ($serializedPositive[$key] ?? $zeroSerialized), 'Video block differs or is truncated.');
            }
            if (isset($nativeHashes[$key])) {
                $nativeCells = array_slice($cells, 0, count($nativeHeader));
                lcvxPerfAssert(hash('sha256', json_encode($nativeCells)) === $nativeHashes[$key], 'Native data changed in an existing context.');
                $matchedNative++;
            }
        }
        fclose($stream);
        $writer->destruct();
        $writer = null;
        if ($currentMode === 'bss' || $currentMode === 'all') {
            lcvxPerfAssert(count($seenProducts) === count($ids), 'Full catalog omitted products.');
        }
        if ($currentMode === 'all') {
            lcvxPerfAssert($matchedNative === count($nativeHashes), 'Existing native contexts disappeared.');
        }
        if ($currentMode !== 'bss') {
            lcvxPerfAssert($metrics['reader_contexts'] === $candidateContexts, 'Not every candidate context reached the reader.');
        }
        if ($currentMode === 'with' || $currentMode === 'without') {
            $expectedCount = $currentMode === 'with' ? count($positive) : $candidateContexts - count($positive);
            lcvxPerfAssert($rowCount === $expectedCount, 'Filtered full-catalog contexts are incomplete.');
        }
        $metrics['rows'] = $rowCount;
        $metrics['columns'] = count($header);
        $metrics['integrity'] = 'PASS';
        $report['modes'][$currentMode] = $metrics;
        unset($observed, $seenProducts, $model, $instrumented, $profiler, $row, $cells);
        gc_collect_cycles();
    }
    $report['result'] = 'PASS';
    $exitCode = 0;
} catch (Throwable $failure) {
    $report['result'] = 'FAIL';
    $report['stage'] = $stage;
    $report['exception'] = get_class($failure);
    $report['message'] = str_starts_with($failure->getMessage(), 'LCVX: ')
        ? $failure->getMessage() : 'Runtime failure; source data and native exception text intentionally excluded.';
    $report['location'] = basename($failure->getFile()) . ':' . $failure->getLine();
} finally {
    if ($writer !== null) {
        $writer->destruct();
    }
    if ($connection !== null && $connection->getTransactionLevel() > 0) {
        $connection->rollBack();
    }
    if (is_dir($temporary)) {
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($tree as $item) {
            if ($item->isDir() && !$item->isLink()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); }
        }
        rmdir($temporary);
    }
    $report['owned_runtime_removed'] = !file_exists($temporary);
    if (!$report['owned_runtime_removed']) { $report['result'] = 'FAIL'; $exitCode = 1; }
    $report['captured_utc'] = gmdate('c');
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($exitCode);
