<?php
/** Focused read-only comparison against the installed native gallery query. */
declare(strict_types=1);

use LeanCommerce\ProductVideoExport\Domain\VideoListCodec;
use LeanCommerce\ProductVideoExport\Model\ResourceModel\BatchVideoReader;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once (getenv('LCVX_RUNNER_SUPPORT') ?: __DIR__ . '/../../tests/integration/runner-support.php');
$root = lcvxIntegrationRoot();
require $root . '/app/bootstrap.php';
$loader = require $root . '/vendor/autoload.php';
$moduleRoot = rtrim(getenv('LCVX_MODULE_ROOT') ?: $root . '/var/lcvx-product-video-export/module', '/') . '/';
$loader->addPsr4('LeanCommerce\\ProductVideoExport\\', $moduleRoot);
$isolatedBootstrap = getenv('LCVX_ISOLATED_BOOTSTRAP') ?: __DIR__ . '/../../tests/integration/isolated-bootstrap.php';
require $isolatedBootstrap;
$bootstrap = lcvxRunnerBootstrap(BP);
$om = $bootstrap->getObjectManager();
$om->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('adminhtml'));
$connection = $om->get(ResourceConnection::class)->getConnection();
$connection->query('SET TRANSACTION READ ONLY');
$connection->beginTransaction();
$report = ['case' => 'M01-installed-native-query', 'php' => PHP_VERSION, 'results' => []];
try {
    $gallery = $om->get(Gallery::class);
    $attributeId = (int)$om->get(Config::class)->getAttribute(Product::ENTITY, 'media_gallery')->getAttributeId();
    $link = $om->get(MetadataPool::class)->getMetadata(ProductInterface::class)->getLinkField();
    $ids = array_map('intval', $connection->fetchCol(
        $gallery->createBatchBaseSelect(0, $attributeId)
            ->reset(Magento\Framework\DB\Select::COLUMNS)
            ->columns([$link], 'entity')
            ->where('main.media_type = ?', 'external-video')
            ->limit(5)
    ));
    if ($ids === []) {
        throw new RuntimeException('The native sample has no external-video associations.');
    }
    $reader = $om->create(BatchVideoReader::class);
    $codec = new VideoListCodec();
    foreach ([0, 1, 5] as $storeId) {
        $nativeRows = $connection->fetchAll(
            $gallery->createBatchBaseSelect($storeId, $attributeId)
                ->where('entity.' . $link . ' IN (?)', $ids)
                ->where('main.media_type = ?', 'external-video')
        );
        $expected = array_fill_keys($ids, []);
        foreach ($nativeRows as $row) {
            if ((int)$row['disabled'] === 0) {
                $expected[(int)$row[$link]][(int)$row['value_id']] = $row;
            }
        }
        $profiler = $connection->getProfiler();
        $profiler->clear();
        $profiler->setEnabled(true);
        $actual = $reader->read($ids, $storeId);
        $queries = $profiler->getTotalNumQueries();
        $profiler->setEnabled(false);
        if ($queries !== 1) {
            throw new RuntimeException('Expected exactly one query per nonempty store/batch: ' . $queries);
        }
        foreach ($expected as $productId => $rows) {
            usort($rows, static fn (array $a, array $b): int =>
                ($a['position'] === null) <=> ($b['position'] === null)
                ?: ((int)$a['position'] <=> (int)$b['position'])
                ?: ((int)$a['value_id'] <=> (int)$b['value_id'])
            );
            $urls = array_map(static fn (array $row): string => (string)$row['video_url'], $rows);
            $titles = array_map(static fn (array $row): string =>
                str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$row['video_title']), $rows);
            $columns = $actual[$productId]->toColumns();
            if ($columns['video_count'] !== count($rows)
                || $codec->decode($columns['video_urls'], count($rows)) !== $urls
                || $codec->decode($columns['video_titles'], count($rows)) !== $titles
            ) {
                throw new RuntimeException('Reader and native effective metadata differ.');
            }
        }
        $report['results'][] = ['store_id' => $storeId, 'products' => count($ids), 'queries' => $queries, 'parity' => true];
    }
    $profiler->clear();
    $profiler->setEnabled(true);
    $empty = $reader->read([], 0);
    $emptyQueries = $profiler->getTotalNumQueries();
    $profiler->setEnabled(false);
    if ($empty !== [] || $emptyQueries !== 0) {
        throw new RuntimeException('Empty batch must not query.');
    }
    $report['empty_batch_queries'] = $emptyQueries;
    $report['result'] = 'PASS';
    $exitCode = 0;
} catch (Throwable $failure) {
    $report['result'] = 'FAIL';
    $report['exception'] = get_class($failure);
    $report['message'] = $failure->getMessage();
    $exitCode = 1;
} finally {
    $connection->rollBack();
    $report['captured_utc'] = gmdate('c');
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($exitCode);
