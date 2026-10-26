<?php
/** Focused export mechanism probe: read-only database session and exclusively owned temporary files. */
declare(strict_types=1);

use Bss\CustomProductAttributeExport\Model\Rewrite\CatalogImportExport\Export\Product as BssProductExport;
use LeanCommerce\ProductVideoExport\Api\VideoReaderInterface;
use LeanCommerce\ProductVideoExport\Domain\VideoResult;
use LeanCommerce\ProductVideoExport\Model\ExportExecutionContext;
use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use LeanCommerce\ProductVideoExport\Model\ProductExport;
use LeanCommerce\ProductVideoExport\Model\ResourceModel\BatchVideoReader;
use LeanCommerce\ProductVideoExport\Model\ScopedExportLogger;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\ImportExport\Model\Export\Adapter\Csv;

define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once (getenv('LCVX_RUNNER_SUPPORT') ?: __DIR__ . '/../../tests/integration/runner-support.php');
$root = lcvxIntegrationRoot();
$temporary = '/tmp/LCVX-product-export-' . bin2hex(random_bytes(8));
$report = [
    'case' => 'M02-M04-direct-product-export',
    'php' => PHP_VERSION,
    'database_transaction_read_only' => true,
    'module_installed_by_probe' => false,
    'transport' => 'Direct native/Bss and ProductExport model calls; no Admin, publisher or consumer.',
    'limits' => [
        'Existing catalog sample only; no synthetic store overrides or video-only rows created.',
        'No queue, browser, ACL, notification, download or cross-process concurrency acceptance.',
        'Native Csv temporary destinations are owned by this probe; production cleanup is a separate mechanism.',
    ],
    'results' => [],
];
$connection = null;
$exitCode = 1;
$stage = 'bootstrap';

function lcvxAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('LCVX: ' . $message);
    }
}

function lcvxParseCsv(string $csv): array
{
    if ($csv === '') {
        return [[], []];
    }
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $header = fgetcsv($stream, 0, ',', '"', "\0");
    lcvxAssert(is_array($header), 'CSV header missing.');
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', "\0")) !== false) {
        lcvxAssert(count($row) === count($header), 'CSV row width differs from header.');
        lcvxAssert($row !== $header, 'CSV header repeated as data.');
        $rows[] = $row;
    }
    fclose($stream);
    return [$header, $rows];
}

function lcvxRemoveOwnedTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

try {
    lcvxAssert(mkdir($temporary, 0700), 'Could not create owned temporary directory.');
    require $root . '/app/bootstrap.php';
    $loader = require $root . '/vendor/autoload.php';
    $moduleRoot = rtrim(getenv('LCVX_MODULE_ROOT') ?: $root . '/var/lcvx-product-video-export/module', '/') . '/';
    $loader->addPsr4('LeanCommerce\\ProductVideoExport\\', $moduleRoot);
    $params = $_SERVER;
    $paths = [];
    foreach (['var', 'var_export', 'import_export', 'log', 'cache', 'session', 'tmp', 'generated', 'code', 'metadata'] as $code) {
        $directory = $temporary . '/' . $code;
        mkdir($directory, 0700);
        $paths[$code] = ['path' => $directory];
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
    // A sibling PHP container shares the network, but not the MySQL Unix socket.
    if (getenv('LCVX_DB_HOST') !== false) {
        $installedDeployment = require $root . '/app/etc/env.php';
        $params[ObjectManagerFactory::INIT_PARAM_DEPLOYMENT_CONFIG]['db'] = $installedDeployment['db'];
        $params[ObjectManagerFactory::INIT_PARAM_DEPLOYMENT_CONFIG]['db']['connection']['default']['host'] =
            getenv('LCVX_DB_HOST');
        unset($installedDeployment);
    }
    // Existing compiled metadata predates this uninstalled module. Use Magento's developer DI
    // environment in this process only, keeping every writable runtime directory above isolated.
    class LcvxProbeEnvironmentFactory extends Magento\Framework\App\EnvironmentFactory
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
        protected $envFactoryClassName = LcvxProbeEnvironmentFactory::class;
    };
    $bootstrap = Bootstrap::create(BP, $params, $objectManagerFactory);
    $om = $bootstrap->getObjectManager();
    // Native log handlers otherwise prepend BP rather than the overridden DirectoryList.
    $om->configure([
        Magento\Framework\Logger\Handler\Base::class => ['arguments' => ['filePath' => $temporary]],
    ]);
    $om->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    $om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('adminhtml'));
    $diDocument = new DOMDocument();
    lcvxAssert($diDocument->load($moduleRoot . 'etc/di.xml'), 'Could not read module DI XML.');
    $moduleDi = $om->get(Magento\Framework\ObjectManager\Config\Mapper\Dom::class)->convert($diDocument);
    $om->configure($moduleDi);
    $report['module_di_loaded'] = 'Actual etc/di.xml through native Dom mapper, process-only developer DI.';
    foreach (['cache', 'log', 'code', 'metadata', 'import_export'] as $code) {
        lcvxAssert(
            str_starts_with($om->get(DirectoryList::class)->getPath($code), $temporary . '/'),
            'A writable runtime path is not isolated.'
        );
    }
    $deployment = $om->get(Magento\Framework\App\DeploymentConfig::class);
    lcvxAssert($deployment->get('cache/frontend/default/backend') === 'Cm_Cache_Backend_File', 'Cache backend is not isolated.');
    $resource = $om->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $connection->query('SET SESSION TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $report['temporary_runtime_isolated'] = true;
    $report['source_sha256'] = [];
    foreach (['Model/ProductExport.php', 'Model/ScopedVideoWriter.php', 'Model/ResourceModel/BatchVideoReader.php', 'Domain/VideoAggregator.php'] as $path) {
        $report['source_sha256'][$path] = hash_file('sha256', $moduleRoot . $path);
    }

    $stage = 'isolation';
    $diConfig = $om->get(Magento\Framework\ObjectManager\ConfigInterface::class);
    $firstLogger = $om->create(ScopedExportLogger::class);
    $secondLogger = $om->create(ScopedExportLogger::class);
    $report['isolation'] = [
        'config_class' => get_class($diConfig),
        'context_is_shared_config' => $diConfig->isShared(ExportExecutionContext::class),
        'logger_is_shared_config' => $diConfig->isShared(ScopedExportLogger::class),
        'logger_distinct' => $firstLogger !== $secondLogger,
        'context_distinct' => $firstLogger->getContext() !== $secondLogger->getContext(),
    ];
    lcvxAssert($firstLogger !== $secondLogger && $firstLogger->getContext() !== $secondLogger->getContext(), 'Execution contexts are shared across instances.');

    $stage = 'sample';
    $gallery = $om->get(Gallery::class);
    $attributeId = (int)$om->get(Config::class)->getAttribute(Product::ENTITY, 'media_gallery')->getAttributeId();
    $link = $om->get(MetadataPool::class)->getMetadata(ProductInterface::class)->getLinkField();
    $videoIds = array_map('intval', $connection->fetchCol(
        $gallery->createBatchBaseSelect(0, $attributeId)
            ->reset(Magento\Framework\DB\Select::COLUMNS)
            ->columns([$link], 'entity')
            ->where('main.media_type = ?', 'external-video')
            ->limit(20)
    ));
    lcvxAssert($videoIds !== [], 'No existing external-video sample is available.');
    $reader = $om->create(BatchVideoReader::class);
    $videoResults = $reader->read($videoIds, 0);
    $productTable = $resource->getTableName('catalog_product_entity');
    $videoCandidates = $connection->fetchAll(
        $connection->select()->from($productTable, [$link, 'sku'])->where($link . ' IN (?)', $videoIds)
    );
    $plainCandidates = $connection->fetchAll(
        $connection->select()->from($productTable, [$link, 'sku'])->order($link . ' ASC')->limit(30)
    );
    $plainResults = $reader->read(array_map(static fn (array $row): int => (int)$row[$link], $plainCandidates), 0);
    $samples = [];
    foreach (['with' => [$videoCandidates, $videoResults], 'without' => [$plainCandidates, $plainResults]] as $label => [$candidates, $results]) {
        foreach ($candidates as $candidate) {
            $sku = (string)$candidate['sku'];
            $count = $results[(int)$candidate[$link]]->getCount();
            if (($label === 'with') !== ($count > 0) || !preg_match('/^[A-Za-z0-9-]{3,}$/D', $sku)) {
                continue;
            }
            $matches = (int)$connection->fetchOne(
                $connection->select()->from($productTable, ['COUNT(*)'])->where('sku LIKE ?', '%' . $sku . '%')
            );
            if ($matches === 1) {
                $samples[$label] = $sku;
                break;
            }
        }
    }
    lcvxAssert(count($samples) === 2, 'Could not select two bounded, unique native SKU filters.');
    $report['sample_products'] = count($samples);
    $filesystem = $om->get(Filesystem::class);
    $serial = 0;
    $run = static function ($model, string $sku, ?string $mode) use ($om, $filesystem, &$serial): string {
        $filters = ['sku' => $sku];
        if ($mode !== null) {
            $filters[PresenceParameters::KEY] = $mode;
        }
        $writer = $om->create(Csv::class, [
            'filesystem' => $filesystem,
            'destination' => 'LCVX-export-' . ++$serial . '.csv',
            'destinationDirectoryCode' => DirectoryList::VAR_IMPORT_EXPORT,
        ]);
        try {
            $model->setParameters(['entity' => 'catalog_product', 'export_filter' => $filters, 'skip_attr' => []]);
            $model->setWriter($writer);
            $contents = $model->export();
            lcvxAssert($model->getWriter() === $writer, 'Original writer was not restored.');
            return $contents;
        } finally {
            $writer->destruct();
        }
    };

    $stage = 'direct-exports';
    $saved = [];
    foreach ($samples as $label => $sku) {
        $native = $run($om->create(BssProductExport::class), $sku, null);
        $legacy = $run($om->create(ProductExport::class), $sku, null);
        lcvxAssert($native !== '' && $native === $legacy, 'Legacy bytes differ from native Bss control.');
        [$nativeHeader, $nativeRows] = lcvxParseCsv($native);
        $all = $run($om->create(ProductExport::class), $sku, 'all');
        [$header, $rows] = lcvxParseCsv($all);
        lcvxAssert($header === array_merge($nativeHeader, VideoResult::COLUMNS), 'Enriched header does not preserve native columns plus four.');
        $projection = array_map(static fn (array $row): array => array_slice($row, 0, count($nativeHeader)), $rows);
        lcvxAssert($projection === $nativeRows, 'All native projection differs for the existing sample.');
        $modes = [];
        foreach (['with', 'without'] as $mode) {
            $filtered = $run($om->create(ProductExport::class), $sku, $mode);
            [$filterHeader, $filterRows] = lcvxParseCsv($filtered);
            if ($filtered !== '') {
                lcvxAssert($filterHeader === $header, 'Filtered export header changed.');
                $countIndex = array_search('video_count', $filterHeader, true);
                $hasIndex = array_search('has_video', $filterHeader, true);
                foreach ($filterRows as $row) {
                    lcvxAssert(ctype_digit($row[$countIndex]), 'Filtered video count is not explicit.');
                    $count = (int)$row[$countIndex];
                    lcvxAssert(($mode === 'with') === ($count > 0), 'Presence mode retained a nonmatching context.');
                    lcvxAssert($row[$hasIndex] === ($count > 0 ? '1' : '0'), 'has_video and count differ.');
                }
            }
            $modes[$mode] = ['rows' => count($filterRows), 'empty_contents' => $filtered === ''];
        }
        $report['results'][] = [
            'sample' => $label,
            'native_columns' => count($nativeHeader),
            'enriched_columns' => count($header),
            'native_rows' => count($nativeRows),
            'all_rows' => count($rows),
            'legacy_bytes_identical' => true,
            'all_native_projection_identical' => true,
            'single_header' => true,
            'modes' => $modes,
        ];
        $saved[$label] = ['native' => $native, 'all' => $all];
    }

    $stage = 'failure-sequence';
    $failingReader = new class($reader) implements VideoReaderInterface {
        private bool $failNext = true;
        public function __construct(private readonly VideoReaderInterface $inner)
        {
        }
        public function read(array $productIds, int $storeId): array
        {
            if ($this->failNext) {
                $this->failNext = false;
                throw new RuntimeException('LCVX: injected reader failure.');
            }
            return $this->inner->read($productIds, $storeId);
        }
    };
    $model = $om->create(ProductExport::class, ['logger' => $firstLogger, 'videoReader' => $failingReader]);
    $caught = false;
    try {
        $run($model, $samples['with'], 'all');
    } catch (LocalizedException $expected) {
        $caught = true;
    }
    lcvxAssert($caught, 'Injected reader failure returned success.');
    lcvxAssert(!$firstLogger->getContext()->isActive() && !$firstLogger->getContext()->hasFailed(), 'Failure context leaked after export.');
    $followingLegacy = $run($model, $samples['with'], null);
    lcvxAssert($followingLegacy === $saved['with']['native'], 'Legacy export after failure differs from control.');
    $followingAll = $run($model, $samples['with'], 'all');
    lcvxAssert($followingAll === $saved['with']['all'], 'Enriched export after failure differs from clean control.');
    $report['failure_sequence'] = [
        'reader_exception_propagated_as_localized' => true,
        'context_reset' => true,
        'following_legacy_bytes_identical' => true,
        'following_enriched_bytes_identical' => true,
        'distinct_logger_contexts' => true,
    ];
    $report['result'] = 'PASS';
    $exitCode = 0;
} catch (Throwable $failure) {
    $report['result'] = 'FAIL';
    $report['stage'] = $stage;
    $report['exception'] = get_class($failure);
    $report['message'] = str_starts_with($failure->getMessage(), 'LCVX: ')
        ? $failure->getMessage()
        : 'Runtime failure; native messages and catalog data intentionally excluded.';
    $report['location'] = basename($failure->getFile()) . ':' . $failure->getLine();
    $report['trace_without_arguments'] = array_map(static fn (array $frame): array =>
        array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])),
        array_slice($failure->getTrace(), 0, 20));
} finally {
    if ($connection !== null && $connection->getTransactionLevel() > 0) {
        $connection->rollBack();
    }
    lcvxRemoveOwnedTree($temporary);
    $report['owned_temporary_removed'] = !file_exists($temporary);
    $report['captured_utc'] = gmdate('c');
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($exitCode);
