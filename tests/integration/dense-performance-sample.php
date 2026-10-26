<?php
/** One diagnostic sample per process; invoke warmup + three measurements for each mode. */
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
$temporary = '/tmp/LCVX-dense-sample-' . bin2hex(random_bytes(8));
$connection = null;
$writer = null;
$exitCode = 1;
$stage = 'bootstrap';
$report = [
    'channel' => 'Dense synthetic native/Bss and ProductExport model performance',
    'mode' => $mode, 'iteration' => $iteration, 'php' => PHP_VERSION,
    'acceptance_threshold' => null,
    'limits' => [
        'Diagnostic sample only; PHP runtime reported explicitly, no production throughput claim.',
        'No queue wait, Admin, publication, download or spreadsheet application timing.',
        'Exact synthetic SKU prefix verified against the fixture manifest; no CSV retained.',
        'Test-only page size 2 for every mode; native pagination algorithms remain inherited.',
        'Three synthetic products with 0, 20 and 100 videos; this is not a product limit or a production throughput claim.',
        'Every process preloads identical effective video expectations before timing; bootstrap, construction and validation are excluded.',
        'Counter profiler and reader instrumentation apply to measurements; RSS peak covers the whole process.',
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

    $stage = 'verify-owned-dense-universe';
    $manifestPath = getenv('LCVX_DENSE_MANIFEST') ?: '';
    lcvxPerfAssert(is_file($manifestPath), 'Dense manifest is missing.');
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    lcvxPerfAssert(preg_match('/\ALCVX-IT-DENSE-[a-zA-Z0-9-]+-\z/', $manifest['sku_prefix'] ?? '') === 1, 'Dense prefix is invalid.');
    $filterValue = $manifest['sku_prefix'];
    $link = $om->get(Magento\Framework\EntityManager\MetadataPool::class)->getMetadata(Magento\Catalog\Api\Data\ProductInterface::class)->getLinkField();
    $productTable = $resource->getTableName('catalog_product_entity');
    $products = $connection->fetchAll($connection->select()->from($productTable, [$link, 'sku'])->where('sku LIKE ?', '%' . $filterValue . '%')->order($link . ' ASC'));
    $productIds = array_map(static fn (array $row): int => (int)$row[$link], $products);
    $skuToId = array_column($products, $link, 'sku');
    lcvxPerfAssert(count($products) === 3 && $productIds === array_column($manifest['products'], 'id'), 'Dense filter escaped the exact fixture universe.');
    $fixtureBySku = array_column($manifest['products'], null, 'sku');
    foreach ($products as $product) {
        lcvxPerfAssert(isset($fixtureBySku[$product['sku']]) && $fixtureBySku[$product['sku']]['id'] === (int)$product[$link], 'Dense fixture identity changed.');
    }
    // Independent oracle from original fixture strings: escape backslash before pipe.
    $encodeReference = static fn (array $values): string => implode('|', array_map(
        static fn (string $value): string => str_replace('|', '\\|', str_replace('\\', '\\\\', $value)),
        $values
    ));
    $stores = $om->get(Magento\Store\Model\StoreManagerInterface::class)->getStores(true);
    ksort($stores);
    $reader = $om->create(BatchVideoReader::class);
    $expected = [];
    $positive = 0;
    $maximumVideos = 0;
    foreach ($stores as $storeId => $store) {
        $results = $reader->read($productIds, (int)$storeId);
        $code = (int)$storeId === 0 ? '' : $store->getCode();
        foreach ($products as $product) {
            $value = $results[(int)$product[$link]];
            $fixture = $fixtureBySku[$product['sku']];
            $referenceColumns = ['has_video' => $fixture['count'] > 0 ? 1 : 0,
                'video_count' => $fixture['count'],
                'video_urls' => $encodeReference($fixture['expected']['urls']),
                'video_titles' => $encodeReference($fixture['expected']['titles'])];
            lcvxPerfAssert($value->toColumns() === $referenceColumns, 'Reader differs from the original fixture strings/count/order/escapes.');
            $expected[$product['sku'] . '|' . $code] = $referenceColumns;
            $positive += (int)($value->getCount() > 0);
            $maximumVideos = max($maximumVideos, $value->getCount());
        }
    }
    lcvxPerfAssert($positive > 0 && $positive < count($expected), 'Sample must contain both effective video and non-video contexts.');
    $report['dataset'] = [
        'synthetic_counts' => [0, 20, 100], 'long_title_input_characters' => 255,
        'sample_products' => count($products), 'store_contexts_per_product' => count($stores),
        'candidate_contexts' => count($expected), 'contexts_with_video' => $positive,
        'contexts_without_video' => count($expected) - $positive, 'maximum_effective_videos_per_context' => $maximumVideos,
        'page_size' => 2, 'candidate_pages' => (int)ceil(count($products) / 2),
        'universe_sha256' => hash('sha256', json_encode($productIds)),
        'filter_sha256' => hash('sha256', $filterValue),
        'effective_video_snapshot_sha256' => hash('sha256', json_encode($expected)),
    ];
    unset($results, $value);

    /** Keep Magento's profiler lifecycle, retaining only numeric counters after each completed query. */
    class LcvxPerfProfiler extends Magento\Framework\DB\Profiler
    {
        public int $queries = 0;
        public float $querySeconds = 0.0;
        public function queryEnd($queryId)
        {
            $result = parent::queryEnd($queryId);
            if ($result === self::STORED) {
                $profile = $this->getQueryProfile($queryId);
                $this->queries++;
                $this->querySeconds += $profile->getElapsedSecs();
                unset($this->_queryProfiles[$queryId]);
                return self::IGNORED;
            }
            return $result;
        }
    }
    class LcvxPerfBss extends BssExport
    {
        protected function getItemsPerPage()
        {
            $this->_itemsPerPage = 2;
            return parent::getItemsPerPage();
        }
    }
    class LcvxPerfExport extends ProductExport
    {
        protected function getItemsPerPage()
        {
            $this->_itemsPerPage = 2;
            return parent::getItemsPerPage();
        }
    }
    $profiler = new LcvxPerfProfiler(true);
    $recordingReader = new class($reader, $profiler) implements VideoReaderInterface {
        public array $batches = [];
        public array $seen = [];
        public function __construct(private readonly BatchVideoReader $inner, private readonly LcvxPerfProfiler $profiler)
        {
        }
        public function read(array $productIds, int $storeId): array
        {
            $before = $this->profiler->queries;
            $result = $this->inner->read($productIds, $storeId);
            $queries = $this->profiler->queries - $before;
            lcvxPerfAssert($queries === 1, 'A nonempty reader batch did not use exactly one query.');
            foreach ($productIds as $id) {
                lcvxPerfAssert(!isset($this->seen[$storeId][$id]), 'Product context was reread in another batch.');
                $this->seen[$storeId][$id] = true;
            }
            $this->batches[] = ['store_id' => $storeId, 'products' => count($productIds), 'queries' => $queries];
            return $result;
        }
    };
    $model = $om->create($mode === 'bss' ? LcvxPerfBss::class : LcvxPerfExport::class, ['videoReader' => $recordingReader]);
    $filters = ['sku' => $filterValue];
    if ($mode !== 'bss') {
        $filters[PresenceParameters::KEY] = $mode;
    }
    $model->setParameters(['entity' => 'catalog_product', 'export_filter' => $filters, 'skip_attr' => []]);
    $writer = $om->create(Csv::class, ['destination' => 'LCVX-performance.csv', 'destinationDirectoryCode' => DirectoryList::VAR_IMPORT_EXPORT]);
    $model->setWriter($writer);
    $connection->setProfiler($profiler);
    $stage = 'measure-export';
    gc_collect_cycles();
    memory_reset_peak_usage();
    $baselineMemory = memory_get_usage(true);
    $start = hrtime(true);
    $csv = $model->export();
    $duration = (hrtime(true) - $start) / 1_000_000_000;
    $peakMemory = memory_get_peak_usage(true);
    $rss = getrusage();
    $profiler->setEnabled(false);
    $report['metrics'] = [
        'export_seconds' => $duration, 'php_baseline_bytes' => $baselineMemory,
        'php_peak_bytes' => $peakMemory, 'php_peak_increase_bytes' => $peakMemory - $baselineMemory,
        'process_rss_peak_bytes' => ($rss['ru_maxrss'] ?? 0) * 1024,
        'queries_total' => $profiler->queries, 'query_seconds' => $profiler->querySeconds,
        'reader_queries' => count($recordingReader->batches),
        'reader_contexts_processed' => array_sum(array_column($recordingReader->batches, 'products')),
        'bytes' => strlen($csv), 'csv_sha256' => hash('sha256', $csv),
    ];
    $report['reader_batches'] = $recordingReader->batches;

    $stage = 'validate-completeness';
    [$header, $rows] = lcvxPerfParse($csv);
    $report['metrics']['rows_written'] = count($rows);
    $report['metrics']['columns'] = count($header);
    $report['metrics']['maximum_video_cell_bytes'] = 0;
    $report['metrics']['maximum_video_cell_characters'] = 0;
    $report['metrics']['header_sha256'] = hash('sha256', json_encode($header));
    $actual = [];
    $observedSkus = [];
    foreach ($rows as $row) {
        $sku = $row['sku'];
        lcvxPerfAssert(isset($skuToId[$sku]), 'Export escaped the candidate universe.');
        $observedSkus[$sku] = true;
        $key = $sku . '|' . $row['store_view_code'];
        lcvxPerfAssert(!isset($actual[$key]), 'A product/store context was emitted twice.');
        $actual[$key] = $row;
        foreach (['video_urls', 'video_titles'] as $field) {
            $report['metrics']['maximum_video_cell_bytes'] = max($report['metrics']['maximum_video_cell_bytes'], strlen($row[$field] ?? ''));
            $report['metrics']['maximum_video_cell_characters'] = max($report['metrics']['maximum_video_cell_characters'], mb_strlen($row[$field] ?? '', 'UTF-8'));
        }
    }
    if ($mode === 'bss' || $mode === 'all') {
        lcvxPerfAssert(count($observedSkus) === count($products), 'Export omitted a candidate product.');
    }
    if ($mode !== 'bss') {
        lcvxPerfAssert(array_slice($header, -4) === VideoResult::COLUMNS, 'The four derived columns are incomplete.');
        lcvxPerfAssert(count($recordingReader->batches) === (int)ceil(count($products) / 2) * count($stores), 'Reader calls do not match store/page batches.');
        lcvxPerfAssert($report['metrics']['reader_contexts_processed'] === count($expected), 'Reader did not process every candidate context.');
        // Compare serialized values after the same native CSV writer's escaping/protection.
        $expectedWriter = $om->create(Csv::class, ['destination' => 'LCVX-expected.csv', 'destinationDirectoryCode' => DirectoryList::VAR_IMPORT_EXPORT]);
        $expectedWriter->setHeaderCols(VideoResult::COLUMNS);
        foreach ($expected as $columns) {
            $expectedWriter->writeRow($columns);
        }
        [, $serializedExpected] = lcvxPerfParse($expectedWriter->getContents());
        $expectedWriter->destruct();
        $serializedExpected = array_combine(array_keys($expected), $serializedExpected);
        if ($mode === 'with' || $mode === 'without') {
            $accepted = array_filter($expected, static fn (array $columns): bool => ($mode === 'with') === ($columns['video_count'] > 0));
            lcvxPerfAssert(array_diff_key($accepted, $actual) === [] && array_diff_key($actual, $accepted) === [], 'Filtered contexts are incomplete or incorrect.');
        }
        foreach ($actual as $key => $row) {
            $columns = array_intersect_key($row, array_flip(VideoResult::COLUMNS));
            if ($mode === 'all' && array_values($columns) === ['', '', '', '']) {
                $baseKey = $row['sku'] . '|';
                lcvxPerfAssert($row['store_view_code'] !== '' && $expected[$key] === $expected[$baseKey], 'Blank video block does not represent inheritance.');
            } else {
                lcvxPerfAssert($columns === $serializedExpected[$key], 'Video values are truncated or differ from effective data.');
            }
        }
    } else {
        lcvxPerfAssert(!in_array('has_video', $header, true), 'Bss control was enriched.');
    }
    $report['integrity'] = ['independent_fixture_oracle_verified' => true, 'complete_candidate_universe' => true, 'complete_matching_contexts' => true,
        'serialized_video_values_verified' => $mode !== 'bss', 'one_query_per_reader_batch' => $mode !== 'bss'];
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
    $report['captured_utc'] = gmdate('c');
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($exitCode);
