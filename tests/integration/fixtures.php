<?php
/** Incremental fixture/reader integration through Magento contracts, with exact cleanup. */
declare(strict_types=1);

use LeanCommerce\ProductVideoExport\Domain\VideoListCodec;
use LeanCommerce\ProductVideoExport\Model\ResourceModel\BatchVideoReader;
use LeanCommerce\ProductVideoExport\Test\Integration\FixtureFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ObjectManagerFactory;
use LeanCommerce\ProductVideoExport\Test\Integration\FixtureExports;

define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once __DIR__ . '/runner-support.php';
$root = lcvxIntegrationRoot();
require $root . '/app/bootstrap.php';
$loader = require $root . '/vendor/autoload.php';
$moduleRoot = rtrim(getenv('LCVX_MODULE_ROOT') ?: $root . '/var/lcvx-product-video-export/module', '/') . '/';
$loader->addPsr4('LeanCommerce\\ProductVideoExport\\', $moduleRoot);
require __DIR__ . '/FixtureFactory.php';
$temporary = '/tmp/LCVX-fixtures-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
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
    $om->configure([
        Magento\Framework\Logger\Handler\Base::class => ['arguments' => ['filePath' => $temporary]],
    ]);
    $om->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    $om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('adminhtml'));
    $diDocument = new DOMDocument();
    $diDocument->load($moduleRoot . 'etc/di.xml');
    $moduleDi = $om->get(Magento\Framework\ObjectManager\Config\Mapper\Dom::class)->convert($diDocument);
    $om->configure($moduleDi);
require __DIR__ . '/FixtureExports.php';
$runId = 'IT-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
$directory = $root . '/var/lcvx-product-video-export/runs/' . $runId;
if (!mkdir($directory, 0770, true)) {
    throw new RuntimeException('Could not create owned fixture evidence directory.');
}
$factory = new FixtureFactory($om, $runId, static function (array $ledger) use ($directory): void {
    $json = json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($directory . '/ledger.json', $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not persist fixture ownership ledger.');
    }
});
$report = ['run_id' => $runId, 'stage' => 'fixtures-reader', 'php' => PHP_VERSION, 'cases' => []];
$code = 1;
try {
    $manifest = $factory->create([1, 5]);
    $report['fixture_manifest'] = $manifest;
    $ids = array_column($manifest['products'], 'id');
    $reader = $om->create(BatchVideoReader::class);
    $codec = new VideoListCodec();
    $counts = [
        0 => ['empty-first' => 0, 'empty-second' => 0, 'base' => 1, 'multiple' => 3, 'scoped' => 1],
        1 => ['empty-first' => 0, 'empty-second' => 0, 'base' => 2, 'multiple' => 3, 'scoped' => 1],
        5 => ['empty-first' => 0, 'empty-second' => 0, 'base' => 1, 'multiple' => 3, 'scoped' => 0],
    ];
    foreach ($counts as $storeId => $expected) {
        $actual = $reader->read($ids, $storeId);
        foreach ($expected as $name => $count) {
            $result = $actual[$manifest['products'][$name]['id']];
            if ($result->getCount() !== $count) {
                throw new RuntimeException('Unexpected fixture count: ' . $name . '/' . $storeId
                    . ': ' . $result->getCount() . ' instead of ' . $count);
            }
            $columns = $result->toColumns();
            if ($columns['has_video'] !== ($count > 0 ? 1 : 0)) {
                throw new RuntimeException('Fixture indicator differs from effective count.');
            }
            if ($name === 'multiple') {
                $titles = $codec->decode($columns['video_titles'], $count);
                if ($titles !== $manifest['expected']['multiple']['titles']) {
                    throw new RuntimeException('Fixture title normalization/order differs.');
                }
                $urls = $codec->decode($columns['video_urls'], $count);
                if ($urls[0] !== $urls[1] || !str_contains($urls[0], '|') || !str_contains($urls[0], '\\')) {
                    throw new RuntimeException('Fixture duplicate URL and escapes were not preserved.');
                }
            }
            if ($name === 'scoped' && $storeId === 1 && $columns['video_titles'] !== '') {
                throw new RuntimeException('The explicit empty local title must not inherit.');
            }
            $report['cases'][] = ['fixture' => $name, 'store_id' => $storeId, 'count' => $count, 'result' => 'PASS'];
        }
    }
    $report['fixture_exports'] = (new FixtureExports($om))->run($manifest, $factory);
    $report['result'] = 'PASS';
    $code = 0;
} catch (Throwable $failure) {
    $report['result'] = 'FAIL';
    $report['exception'] = $failure::class;
    $report['message'] = $failure->getMessage();
} finally {
    $report['cleanup'] = $factory->cleanup();
    foreach ($report['cleanup']['products'] as $cleanup) {
        if (!in_array($cleanup['status'], ['deleted', 'already_absent'], true)) {
            $report['result'] = 'FAIL';
            $code = 1;
        }
    }
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($tree as $item) {
        if ($item->isDir() && !$item->isLink()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); }
    }
    rmdir($temporary);
    $report['owned_runtime_removed'] = !file_exists($temporary);
    if (!$report['owned_runtime_removed']) {
        $report['result'] = 'FAIL';
        $code = 1;
    }
    $report['completed_utc'] = gmdate('c');
    file_put_contents($directory . '/result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($code);
