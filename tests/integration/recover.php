<?php
/** Retry only resources named in a previously persisted, validated fixture ledger. */
declare(strict_types=1);

use LeanCommerce\ProductVideoExport\Test\Integration\FixtureFactory;
use Magento\Framework\App\Bootstrap;

$runId = $argv[1] ?? '';
if (!preg_match('/\AIT-[a-zA-Z0-9-]{4,36}\z/', $runId)) {
    throw new InvalidArgumentException('A recorded fixture run ID is required.');
}
define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once __DIR__ . '/runner-support.php';
$root = lcvxIntegrationRoot();
$directory = $root . '/var/lcvx-product-video-export/runs/' . $runId;
$ledgerPath = $directory . '/ledger.json';
$ledger = json_decode((string)file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
require $root . '/app/bootstrap.php';
require __DIR__ . '/FixtureFactory.php';
require __DIR__ . '/isolated-bootstrap.php';
$om = lcvxRunnerBootstrap(BP)->getObjectManager();
$om->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('adminhtml'));
$factory = new FixtureFactory($om, $runId, static function (array $current) use ($ledgerPath): void {
    if (file_put_contents($ledgerPath, json_encode($current, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Could not persist cleanup ledger.');
    }
});
$factory->restoreLedger($ledger);
$result = $factory->cleanup();
$exit = 0;
foreach ($result['products'] as $item) {
    if (!in_array($item['status'], ['deleted', 'already_absent'], true)) {
        $exit = 1;
    }
}
$report = ['stage' => 'fixture-recovery', 'result' => $exit === 0 ? 'PASS' : 'FAIL', 'cleanup' => $result];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($exit);
