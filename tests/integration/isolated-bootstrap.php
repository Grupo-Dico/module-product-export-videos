<?php
/** Developer bootstrap for the two legacy probes, with owned runtime and verified shutdown cleanup. */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ObjectManagerFactory;

function lcvxRunnerBootstrap(string $root): Bootstrap
{
    $temporary = '/tmp/LCVX-runner-' . bin2hex(random_bytes(12));
    if (!mkdir($temporary, 0700)) {
        throw new RuntimeException('Could not create owned runner runtime.');
    }
    ob_start();
    register_shutdown_function(static function () use ($temporary): void {
        $output = (string)ob_get_clean();
        $removed = false;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $ok = $item->isDir() && !$item->isLink()
                    ? rmdir($item->getPathname()) : unlink($item->getPathname());
                if (!$ok) {
                    throw new RuntimeException('Could not remove owned runtime entry.');
                }
            }
            $removed = rmdir($temporary) && !file_exists($temporary);
        } catch (Throwable $failure) {
            $removed = false;
        }
        $report = json_decode($output, true);
        if (!is_array($report)) {
            $report = [
                'result' => 'FAIL',
                'message' => 'Probe did not return valid JSON; raw output withheld to protect catalog data.',
                'output_sha256' => hash('sha256', $output),
                'output_bytes' => strlen($output),
            ];
        }
        $report['runner_runtime_removed'] = $removed;
        if (!$removed) {
            $report['result'] = 'FAIL';
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
        if (!$removed || ($report['result'] ?? '') !== 'PASS') {
            exit(1);
        }
    });
    $params = $_SERVER;
    $paths = [];
    foreach (['var', 'var_export', 'import_export', 'log', 'cache', 'session', 'tmp', 'generated', 'code', 'metadata'] as $code) {
        if (!mkdir($temporary . '/' . $code, 0700)) {
            throw new RuntimeException('Could not initialize owned runtime.');
        }
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
    $factory = new class(
        Bootstrap::createFilesystemDirectoryList($root, $params),
        Bootstrap::createFilesystemDriverPool($params),
        Bootstrap::createConfigFilePool()
    ) extends ObjectManagerFactory {
        protected $envFactoryClassName = LcvxRunnerEnvironmentFactory::class;
    };
    $bootstrap = Bootstrap::create($root, $params, $factory);
    $bootstrap->getObjectManager()->configure([
        Magento\Framework\Logger\Handler\Base::class => ['arguments' => ['filePath' => $temporary]],
    ]);
    return $bootstrap;
}

class LcvxRunnerEnvironmentFactory extends Magento\Framework\App\EnvironmentFactory
{
    public function createEnvironment()
    {
        return new Magento\Framework\App\ObjectManager\Environment\Developer($this);
    }
}
