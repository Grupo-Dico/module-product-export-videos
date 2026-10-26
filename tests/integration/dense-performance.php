<?php
/** Dense synthetic performance with durable exact ownership and fresh-process measurements. */
declare(strict_types=1);

use LeanCommerce\ProductVideoExport\Test\Integration\DenseFixtureFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ObjectManagerFactory;

define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once __DIR__ . '/runner-support.php';
$root = lcvxIntegrationRoot();
$temporary = '/tmp/LCVX-dense-' . bin2hex(random_bytes(8));
$factory = null;
$directory = null;
$code = 1;
$stage = 'preflight';
$report = ['case' => 'Dense synthetic product video export', 'php' => PHP_VERSION,
    'acceptance_threshold' => null, 'samples' => [], 'modes' => [],
    'limits' => [
        'Three synthetic products with 0/20/100 videos are a diagnostic scenario, not a product limit.',
        'One warmup plus three measured fresh PHP processes per mode; only export is timed.',
        'Page size 2 is identical across modes; the 100-video product occupies the final batch.',
        'Every process verifies data against original fixture strings before timing; no CSV is retained.',
        'No queue wait, Admin, publication, download or spreadsheet application timing.',
        'RSS is the whole child process peak; PHP peak is reset immediately before export.',
        'On a native save failure before a media identity is returned, unknown paths are preserved rather than guessed.',
    ]];

function lcvxDenseAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('LCVX: ' . $message); }
}

try {
    $availableMemory = [];
    preg_match('/MemAvailable:\s+(\d+)/', (string)file_get_contents('/proc/meminfo'), $availableMemory);
    $report['preflight'] = ['disk_available_bytes' => disk_free_space($root),
        'memory_available_bytes' => (int)($availableMemory[1] ?? 0) * 1024,
        'php_memory_limit' => ini_get('memory_limit'),
        'process_limits' => function_exists('posix_getrlimit') ? posix_getrlimit() : null,
        'resource_guard_bytes' => 512 * 1024 * 1024,
        'resource_guard_purpose' => 'Protect shared laboratory capacity; not a product performance threshold.'];
    lcvxDenseAssert($report['preflight']['disk_available_bytes'] > $report['preflight']['resource_guard_bytes']
        && $report['preflight']['memory_available_bytes'] > $report['preflight']['resource_guard_bytes'],
        'Insufficient spare resources for the bounded dense probe.');
    lcvxDenseAssert(function_exists('proc_open'), 'Fresh-process execution is unavailable.');
    lcvxDenseAssert(mkdir($temporary, 0700), 'Could not create owned runtime.');
    require $root . '/app/bootstrap.php';
    $loader = require $root . '/vendor/autoload.php';
    $moduleRoot = rtrim(getenv('LCVX_MODULE_ROOT') ?: $root . '/var/lcvx-product-video-export/module', '/') . '/';
    $loader->addPsr4('LeanCommerce\\ProductVideoExport\\', $moduleRoot);
    require __DIR__ . '/FixtureFactory.php';
    require __DIR__ . '/DenseFixtureFactory.php';
    $params = $_SERVER;
    $paths = [];
    foreach (['var', 'var_export', 'import_export', 'log', 'cache', 'session', 'tmp', 'generated', 'code', 'metadata'] as $runtimeCode) {
        $runtimeDirectory = $temporary . '/' . $runtimeCode;
        lcvxDenseAssert(mkdir($runtimeDirectory, 0700), 'Could not initialize owned runtime.');
        $paths[$runtimeCode] = ['path' => $runtimeDirectory];
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
    // Existing compiled metadata predates this uninstalled module. Use Magento's developer DI
    // environment in this process only, keeping every writable runtime directory above isolated.
    class LcvxDenseEnvironmentFactory extends Magento\Framework\App\EnvironmentFactory
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
        protected $envFactoryClassName = LcvxDenseEnvironmentFactory::class;
    };
    $bootstrap = Bootstrap::create(BP, $params, $objectManagerFactory);
    $om = $bootstrap->getObjectManager();
    $om->configure([
        Magento\Framework\Logger\Handler\Base::class => ['arguments' => ['filePath' => $temporary]],
    ]);
    $om->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    $om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('adminhtml'));
    $diDocument = new DOMDocument();
    lcvxDenseAssert($diDocument->load($moduleRoot . 'etc/di.xml'), 'Could not read candidate DI.');
    $moduleDi = $om->get(Magento\Framework\ObjectManager\Config\Mapper\Dom::class)->convert($diDocument);
    $om->configure($moduleDi);
    $stage = 'create-owned-fixtures';
    $runId = 'IT-DENSE-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $report['run_id'] = $runId;
    $directory = $root . '/var/lcvx-product-video-export/runs/' . $runId;
    lcvxDenseAssert(!file_exists($directory) && mkdir($directory, 0770, true), 'Could not create durable ownership directory.');
    $factory = new DenseFixtureFactory($om, $runId, static function (array $ledger) use ($directory): void {
        $json = json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        lcvxDenseAssert(file_put_contents($directory . '/ledger.json', $json, LOCK_EX) === strlen($json), 'Could not persist ownership ledger.');
    });
    $manifest = $factory->create();
    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    lcvxDenseAssert(file_put_contents($directory . '/dense-manifest.json', $manifestJson, LOCK_EX) === strlen($manifestJson), 'Could not persist dense fixture oracle.');
    $report['fixture_manifest_sha256'] = hash('sha256', $manifestJson);
    $report['fixture_counts'] = array_column($manifest['products'], 'count');
    $report['synthetic_products_created'] = count($manifest['products']);
    $report['synthetic_previews_created'] = array_sum($report['fixture_counts']);
    $reference = null;
    foreach (['bss', 'all', 'with', 'without'] as $mode) {
        $metrics = [];
        $stableOutput = null;
        foreach (['warmup', '1', '2', '3'] as $iteration) {
            $stage = 'measure-' . $mode . '-' . $iteration;
            $environment = getenv();
            $environment['LCVX_MODULE_ROOT'] = $moduleRoot;
            $environment['LCVX_DENSE_MANIFEST'] = $directory . '/dense-manifest.json';
            $environment['LCVX_PERF_MODE'] = $mode;
            $environment['LCVX_PERF_RUN'] = $iteration;
            $outputPath = $temporary . '/child-output.json';
            $errorPath = $temporary . '/child-errors.txt';
            $process = proc_open([PHP_BINARY, __DIR__ . '/dense-performance-sample.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputPath, 'w'], 2 => ['file', $errorPath, 'w']],
                $pipes, $root, $environment);
            lcvxDenseAssert(is_resource($process), 'Could not start a fresh measurement process.');
            $exit = proc_close($process);
            $raw = (string)file_get_contents($outputPath);
            $child = json_decode($raw, true);
            $stderr = (string)file_get_contents($errorPath);
            lcvxDenseAssert(is_array($child), 'Child output is not JSON; raw output withheld.');
            $child['process_exit_code'] = $exit;
            $child['stderr_bytes'] = strlen($stderr);
            $child['stderr_sha256'] = hash('sha256', $stderr);
            $report['samples'][$mode][$iteration] = $child;
            file_put_contents($directory . '/' . $mode . '-' . $iteration . '.json', json_encode($child, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
            lcvxDenseAssert($exit === 0 && ($child['result'] ?? '') === 'PASS'
                && ($child['owned_runtime_removed'] ?? false) === true, 'A dense measurement failed or left its runtime.');
            lcvxDenseAssert($child['mode'] === $mode && $child['iteration'] === $iteration, 'Measurement identity differs.');
            $identity = ['php' => $child['php'], 'source_sha256' => $child['source_sha256'], 'dataset' => $child['dataset']];
            if ($reference === null) { $reference = $identity; }
            lcvxDenseAssert($identity === $reference, 'Source, runtime or fixture data changed between measurements.');
            $outputIdentity = [$child['metrics']['csv_sha256'], $child['metrics']['bytes'], $child['metrics']['rows_written']];
            if ($stableOutput === null) { $stableOutput = $outputIdentity; }
            lcvxDenseAssert($outputIdentity === $stableOutput, 'Output changed between repetitions.');
            if ($iteration !== 'warmup') { $metrics[] = $child['metrics']; }
        }
        $medians = [];
        foreach ($metrics[0] as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $values = array_column($metrics, $key);
                sort($values, SORT_NUMERIC);
                $medians[$key] = $values[1];
            }
        }
        $report['modes'][$mode] = ['medians' => $medians, 'output_stable' => true,
            'integrity' => $child['integrity'], 'reader_batches' => $child['reader_batches']];
    }
    $report['comparison_identity'] = $reference;
    foreach (['all', 'with', 'without'] as $mode) {
        $report['modes'][$mode]['informational_comparison_to_bss'] = [
            'time_ratio' => $report['modes'][$mode]['medians']['export_seconds'] / $report['modes']['bss']['medians']['export_seconds'],
            'php_peak_delta_bytes' => $report['modes'][$mode]['medians']['php_peak_bytes'] - $report['modes']['bss']['medians']['php_peak_bytes'],
        ];
    }
    $report['result'] = 'PASS';
    $code = 0;
} catch (Throwable $failure) {
    $report['result'] = 'FAIL';
    $report['stage'] = $stage;
    $report['exception'] = $failure::class;
    $report['location'] = basename($failure->getFile()) . ':' . $failure->getLine();
    $report['message'] = str_starts_with($failure->getMessage(), 'LCVX: ')
        ? $failure->getMessage() : 'Native failure; exception text withheld to avoid source data disclosure.';
} finally {
    if ($factory !== null) {
        try {
            $report['cleanup'] = $factory->cleanup();
            foreach ($report['cleanup']['products'] as $product) {
                if (!in_array($product['status'], ['deleted', 'already_absent'], true)) {
                    $report['result'] = 'FAIL';
                    $code = 1;
                }
            }
            $cleanedEntries = 0;
            foreach ($report['cleanup']['ledger']['products'] as $product) {
                foreach ($product['entries'] as $entry) {
                    if (($entry['status'] ?? '') === 'removed') { $cleanedEntries++; }
                }
            }
            $report['owned_previews_removed'] = $cleanedEntries;
            if (isset($report['synthetic_previews_created'])
                && $cleanedEntries !== $report['synthetic_previews_created']
            ) {
                $report['result'] = 'FAIL';
                $code = 1;
            }
        } catch (Throwable $cleanupFailure) {
            $report['cleanup'] = ['error_class' => $cleanupFailure::class];
            $report['result'] = 'FAIL';
            $code = 1;
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (is_dir($temporary)) {
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($tree as $item) {
            if ($item->isDir() && !$item->isLink()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); }
        }
        rmdir($temporary);
    }
    $report['owned_runtime_removed'] = !file_exists($temporary);
    if (!$report['owned_runtime_removed']) { $report['result'] = 'FAIL'; $code = 1; }
    $report['captured_utc'] = gmdate('c');
    if ($directory !== null && is_dir($directory)) {
        file_put_contents($directory . '/result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($code);
