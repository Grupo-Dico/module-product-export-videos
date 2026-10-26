<?php
/** Native Admin DTO / DB queue / consumer integration, isolated in one rollback-only process. */
declare(strict_types=1);

use LeanCommerce\ProductVideoExport\Api\VideoReaderInterface;
use LeanCommerce\ProductVideoExport\Domain\VideoResult;
use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use LeanCommerce\ProductVideoExport\Model\ProductExport;
use LeanCommerce\ProductVideoExport\Model\ResourceModel\BatchVideoReader;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\Consumer;
use Magento\Framework\MessageQueue\ConsumerConfiguration;
use Magento\Framework\MessageQueue\ExchangeRepository;
use Magento\Framework\MessageQueue\Publisher;
use Magento\Framework\MessageQueue\Topology\Config\ExchangeConfigItem;
use Magento\Framework\MessageQueue\Topology\Config\QueueConfigItem;
use Magento\Framework\MessageQueue\Topology\ConfigInterface as TopologyConfigInterface;
use Magento\ImportExport\Model\Export\Consumer as ExportConsumer;
use Magento\ImportExport\Model\Export\Entity\ExportInfoFactory;
use Magento\MysqlMq\Model\Driver\Exchange;
use Magento\MysqlMq\Model\Queue;
use Magento\MysqlMq\Model\QueueManagement;

define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once __DIR__ . '/runner-support.php';
$root = lcvxIntegrationRoot();
$run = 'LCVX-queue-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
$temporary = '/tmp/' . $run;
$stage = 'bootstrap';
$connection = null;
$queueId = null;
$messageIds = [];
$notificationIds = [];
$exitCode = 1;
$report = [
    'channel' => 'Native Admin DTO factory -> native Publisher/Encoder -> MySQL queue -> framework Consumer -> native export consumer/manager -> file and DB notification',
    'run' => $run,
    'php' => PHP_VERSION,
    'module_installed_persistently' => false,
    'limits' => [
        'Same process, native MySQL transport in an uncommitted transaction; no cross-process/crash/concurrency acceptance.',
        'Factory is the Admin transport boundary; no browser session, HTTP controller, ACL, UI or download acceptance.',
        'Existing product sample; catalog data and customer identifiers are not recorded.',
        'Module enabled only in this process with native Developer DI; installed compiled DI remains separate.',
    ],
    'cases' => [],
];

function lcvxQueueAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('LCVX: ' . $message);
    }
}

function lcvxQueueRemoveOwnedTree(string $path): void
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

function lcvxQueueCsv(string $path): array
{
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle, 0, ',', '"', "\0");
    lcvxQueueAssert(is_array($header), 'Published CSV has no header.');
    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', "\0")) !== false) {
        lcvxQueueAssert(count($row) === count($header), 'CSV row width differs from its header.');
        lcvxQueueAssert($row !== $header, 'CSV contains a repeated header.');
        $rows[] = $row;
    }
    fclose($handle);
    return [$header, $rows];
}

try {
    lcvxQueueAssert(mkdir($temporary, 0700), 'Could not create owned runtime.');
    require $root . '/app/bootstrap.php';
    $loader = require $root . '/vendor/autoload.php';
    $moduleRoot = rtrim(getenv('LCVX_MODULE_ROOT') ?: $root . '/var/lcvx-product-video-export/module', '/') . '/';
    $loader->addPsr4('LeanCommerce\\ProductVideoExport\\', $moduleRoot);
    $registrar = new ComponentRegistrar();
    $registered = $registrar->getPath(ComponentRegistrar::MODULE, 'LeanCommerce_ProductVideoExport');
    if ($registered === null) {
        require $moduleRoot . 'registration.php';
    } else {
        lcvxQueueAssert(realpath($registered) === realpath($moduleRoot), 'Module registration is outside the probe copy.');
    }
    $config = require $root . '/app/etc/config.php';
    $modules = $config['modules'];
    $modules['LeanCommerce_ProductVideoExport'] = 1;
    unset($config);
    $params = $_SERVER;
    $paths = [];
    foreach (['var', 'var_export', 'import_export', 'log', 'cache', 'session', 'tmp', 'generated', 'code', 'metadata'] as $code) {
        mkdir($temporary . '/' . $code, 0700);
        $paths[$code] = ['path' => $temporary . '/' . $code];
    }
    $params[Bootstrap::INIT_PARAM_FILESYSTEM_DIR_PATHS] = $paths;
    $params[ObjectManagerFactory::INIT_PARAM_DEPLOYMENT_CONFIG] = [
        'modules' => $modules,
        'cache' => ['frontend' => [
            'default' => ['backend' => 'Cm_Cache_Backend_File', 'backend_options' => ['cache_dir' => $temporary . '/cache']],
            'page_cache' => ['backend' => 'Cm_Cache_Backend_File', 'backend_options' => ['cache_dir' => $temporary . '/cache']],
        ]],
        'session' => ['save' => 'files', 'save_path' => $temporary . '/session'],
        'lock' => ['provider' => 'file', 'config' => ['path' => $temporary . '/tmp']],
        'queue' => ['default_connection' => 'db', 'consumers_wait_for_messages' => 0],
    ];
    class LcvxQueueEnvironmentFactory extends Magento\Framework\App\EnvironmentFactory
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
        protected $envFactoryClassName = LcvxQueueEnvironmentFactory::class;
    };
    $om = Bootstrap::create(BP, $params, $factory)->getObjectManager();
    $om->configure([
        Magento\Framework\Logger\Handler\Base::class => ['arguments' => ['filePath' => $temporary]],
    ]);
    $om->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    $om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('adminhtml'));
    foreach (['cache', 'log', 'code', 'metadata', 'import_export'] as $code) {
        lcvxQueueAssert(str_starts_with($om->get(DirectoryList::class)->getPath($code), $temporary . '/'), 'Writable runtime path is not isolated.');
    }
    lcvxQueueAssert(
        $om->get(Magento\Framework\App\DeploymentConfig::class)->get('cache/frontend/default/backend') === 'Cm_Cache_Backend_File',
        'Cache backend is not isolated.'
    );
    // CacheManager can read BP/generated/metadata/interception.php even in Developer mode.
    // Rebuild with Magento's public initializer; its native writer targets the isolated metadata path.
    $interception = $om->get(Magento\Framework\Interception\Config\Config::class);
    $report['interception_before_refresh'] = [
        'export' => $interception->hasPlugins(Magento\ImportExport\Model\Export::class),
        'consumer' => $interception->hasPlugins(ExportConsumer::class),
    ];
    lcvxQueueAssert(
        $om->get(Magento\Framework\App\ObjectManager\ConfigWriterInterface::class)
            instanceof Magento\Framework\App\ObjectManager\ConfigWriter\Filesystem,
        'Unexpected interception metadata writer.'
    );
    $interception->initialize();
    // Developer bootstrap resets shared services after retaining its first interception config.
    // Bind the refreshed native instance explicitly to this process's DI configuration.
    $om->get(Magento\Framework\Interception\ObjectManager\ConfigInterface::class)->setInterceptionConfig($interception);
    lcvxQueueAssert($interception->hasPlugins(Magento\ImportExport\Model\Export::class) && $interception->hasPlugins(ExportConsumer::class), 'Native XML did not register export cleanup plugins.');
    $report['interception_rebuilt_in_owned_metadata'] = is_file($temporary . '/metadata/interception.php');
    $entities = $om->get(Magento\ImportExport\Model\Export\ConfigInterface::class)->getEntities();
    lcvxQueueAssert($entities['catalog_product']['model'] === ProductExport::class, 'Native export.xml merge did not select ProductExport.');
    $report['native_xml_merge'] = true;
    $report['di_config'] = get_class($om->get(Magento\Framework\ObjectManager\ConfigInterface::class));
    $report['source_sha256'] = [];
    foreach (['etc/di.xml', 'etc/export.xml', 'Model/ProductExport.php', 'Model/ScopedAdapterFactory.php', 'Model/OwnedCsv.php', 'Plugin/ExportCleanupPlugin.php', 'Plugin/ConsumerCleanupPlugin.php'] as $file) {
        $report['source_sha256'][$file] = hash_file('sha256', $moduleRoot . $file);
    }

    $stage = 'preflight';
    $resource = $om->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $tables = [];
    foreach (['queue', 'queue_message', 'queue_message_status', 'queue_lock', 'adminnotification_inbox'] as $name) {
        $tables[$name] = $resource->getTableName($name);
        lcvxQueueAssert($connection->isTableExists($tables[$name]), 'A required integration table is missing.');
    }
    $queueResource = $om->get(Magento\MysqlMq\Model\ResourceModel\Queue::class);
    $notifier = $om->get(Magento\Framework\Notification\NotifierInterface::class);
    lcvxQueueAssert($notifier instanceof Magento\Framework\Notification\NotifierPool, 'Unexpected native notifier pool.');
    $notifiers = array_values($om->get(Magento\Framework\Notification\NotifierList::class)->asArray());
    lcvxQueueAssert(count($notifiers) === 1 && $notifiers[0] instanceof Magento\AdminNotification\Model\Inbox, 'Unexpected native notifier; cannot guarantee DB-only effects.');
    lcvxQueueAssert($queueResource->getConnection() === $connection, 'Queue uses a different DB connection.');
    lcvxQueueAssert($notifiers[0]->getResource()->getConnection() === $connection, 'Notifier uses a different DB connection.');
    lcvxQueueAssert($connection->getTransactionLevel() === 0, 'A transaction was already open.');
    lcvxQueueAssert(!$connection->fetchOne($connection->select()->from($tables['queue'], 'id')->where('name = ?', $run)), 'Run queue already exists.');
    $report['preflight'] = [
        'disk_free_bytes' => disk_free_space($temporary),
        'memory_limit' => ini_get('memory_limit'),
        'native_notifier' => get_class($notifier),
        'native_notifier_members' => array_map('get_class', $notifiers),
        'queue_and_notifier_same_connection' => true,
        'runtime_directories_isolated' => true,
        'mysql_available' => true,
        'transport' => 'db',
        'shared_cache_search_not_required' => true,
    ];

    $stage = 'sample';
    $link = $om->get(Magento\Framework\EntityManager\MetadataPool::class)
        ->getMetadata(Magento\Catalog\Api\Data\ProductInterface::class)->getLinkField();
    $productTable = $resource->getTableName('catalog_product_entity');
    $reader = $om->create(BatchVideoReader::class);
    $attributeId = (int)$om->get(Magento\Eav\Model\Config::class)
        ->getAttribute(Magento\Catalog\Model\Product::ENTITY, 'media_gallery')->getAttributeId();
    $videoIds = $connection->fetchCol(
        $om->get(Magento\Catalog\Model\ResourceModel\Product\Gallery::class)->createBatchBaseSelect(0, $attributeId)
            ->reset(Magento\Framework\DB\Select::COLUMNS)->columns([$link], 'entity')
            ->where('main.media_type = ?', 'external-video')->limit(30)
    );
    lcvxQueueAssert($videoIds !== [], 'No bounded existing video sample.');
    $candidates = [
        'with' => $connection->fetchAll($connection->select()->from($productTable, [$link, 'sku'])->where($link . ' IN (?)', $videoIds)),
        'without' => $connection->fetchAll($connection->select()->from($productTable, [$link, 'sku'])->order($link . ' ASC')->limit(30)),
    ];
    $samples = [];
    foreach ($candidates as $label => $products) {
        $results = $reader->read(array_map(static fn (array $row): int => (int)$row[$link], $products), 0);
        foreach ($products as $product) {
            $sku = (string)$product['sku'];
            if (($results[(int)$product[$link]]->getCount() > 0) !== ($label === 'with') || !preg_match('/^[A-Za-z0-9-]{3,}$/D', $sku)) {
                continue;
            }
            if ((int)$connection->fetchOne($connection->select()->from($productTable, ['COUNT(*)'])->where('sku LIKE ?', '%' . $sku . '%')) === 1) {
                $samples[$label] = $sku;
                break;
            }
        }
    }
    lcvxQueueAssert(count($samples) === 2, 'Could not select two unique existing SKU filters.');

    $stage = 'create-owned-queue';
    $connection->beginTransaction();
    $queue = $om->create(Queue::class)->setName($run)->save();
    $queueId = (int)$queue->getId();
    lcvxQueueAssert($queueId > 0 && $connection->getTransactionLevel() === 1, 'Native queue creation escaped the outer transaction.');
    $exchangeItem = $om->create(ExchangeConfigItem::class);
    $exchangeItem->setData([
        'name' => $run, 'type' => 'topic', 'connection' => 'db', 'internal' => false,
        'durable' => false, 'autoDelete' => true, 'arguments' => [],
        'bindings' => [[
            'id' => $run, 'destinationType' => 'queue', 'destination' => $run,
            'topic' => 'import_export.export', 'disabled' => false, 'arguments' => [],
        ]],
    ]);
    $queueItem = new QueueConfigItem();
    $queueItem->setData(['name' => $run, 'connection' => 'db', 'durable' => false, 'autoDelete' => true, 'arguments' => []]);
    $topology = new class($exchangeItem, $queueItem) implements TopologyConfigInterface {
        public function __construct(private readonly ExchangeConfigItem $exchange, private readonly QueueConfigItem $queue)
        {
        }
        public function getExchange($name, $connection)
        {
            lcvxQueueAssert($name === $this->exchange->getName() && $connection === 'db', 'Unexpected exchange request.');
            return $this->exchange;
        }
        public function getExchanges()
        {
            return [$this->exchange];
        }
        public function getQueues()
        {
            return [$this->queue];
        }
    };
    $exchange = $om->create(Exchange::class, ['messageQueueConfig' => $topology]);
    $exchanges = new class($om, $exchange) extends ExchangeRepository {
        public function __construct($om, private readonly Exchange $ownedExchange)
        {
            parent::__construct($om);
        }
        public function getByConnectionName($connectionName)
        {
            lcvxQueueAssert($connectionName === 'db', 'Publisher selected a non-DB connection.');
            return $this->ownedExchange;
        }
    };
    $publisher = $om->create(Publisher::class, ['exchangeRepository' => $exchanges]);
    $nativeConsumer = $om->create(ExportConsumer::class);
    $report['native_consumer_class'] = get_class($nativeConsumer);
    lcvxQueueAssert($nativeConsumer instanceof Magento\Framework\Interception\InterceptorInterface, 'Native consumer is not intercepted.');
    $consumerConfig = $om->get(Magento\Framework\MessageQueue\Consumer\ConfigInterface::class)->getConsumer('exportProcessor');
    lcvxQueueAssert($consumerConfig->getConnection() === 'db', 'Native export consumer is not using DB.');
    $configuration = $om->create(ConsumerConfiguration::class, ['data' => [
        'consumer_name' => 'exportProcessor', 'queue_name' => $run, 'max_messages' => 1,
        'max_idle_time' => 1, 'sleep' => 1, 'only_spawn_when_message_available' => true,
        'topics' => ['import_export.export' => ['consumer_type' => 'async', 'handlers' => [[$nativeConsumer, 'process']]]],
    ]]);
    $worker = $om->create(Consumer::class, ['configuration' => $configuration]);
    $dtoFactory = $om->create(ExportInfoFactory::class);
    $serializer = $om->get(Magento\Framework\Serialize\SerializerInterface::class);
    $countOwnMessages = static fn (): int => (int)$connection->fetchOne(
        $connection->select()->from($tables['queue_message_status'], ['COUNT(*)'])->where('queue_id = ?', $queueId)
    );

    $stage = 'invalid-admin-factory';
    $rejected = false;
    try {
        $dtoFactory->create('csv', 'catalog_product', ['sku' => $samples['with'], PresenceParameters::KEY => 'invalid']);
    } catch (LocalizedException) {
        $rejected = true;
    }
    lcvxQueueAssert($rejected && $countOwnMessages() === 0, 'Invalid Admin parameters reached publication.');
    $report['cases'][] = ['case' => 'invalid-admin-factory', 'localized_rejection_before_publish' => true, 'queue_messages' => 0];

    $exportDirectory = $temporary . '/import_export/export';
    $serial = 0;
    $process = static function (string $case, string $sku, ?string $mode, bool $expectFile, ?string $mutatedMode = null) use (
        $dtoFactory, $serializer, $publisher, $worker, $connection, $tables, $queueId,
        $run, $exportDirectory, &$serial, &$messageIds, &$notificationIds, &$report
    ): ?array {
        $filters = ['sku' => $sku];
        if ($mode !== null) {
            $filters[PresenceParameters::KEY] = $mode;
        }
        $dto = $dtoFactory->create('csv', 'catalog_product', $filters, [], 'en_US', false);
        $filename = $run . '-' . ++$serial . '.csv';
        $dto->setFileName($filename);
        if ($mutatedMode !== null) {
            $filters[PresenceParameters::KEY] = $mutatedMode;
            $dto->setExportFilter($serializer->serialize($filters));
        }
        lcvxQueueAssert(!file_exists($exportDirectory . '/' . $filename), 'Owned final destination already exists.');
        $noticeBefore = (int)$connection->fetchOne($connection->select()->from($tables['adminnotification_inbox'], ['MAX(notification_id)']));
        $messageBefore = (int)$connection->fetchOne($connection->select()->from($tables['queue_message_status'], ['MAX(message_id)'])->where('queue_id = ?', $queueId));
        $publisher->publish('import_export.export', $dto);
        $messages = $connection->fetchAll($connection->select()->from($tables['queue_message_status'], ['message_id', 'status'])
            ->where('queue_id = ?', $queueId)->where('message_id > ?', $messageBefore));
        lcvxQueueAssert(count($messages) === 1 && (int)$messages[0]['status'] === QueueManagement::MESSAGE_STATUS_NEW, 'Publisher did not enqueue exactly one owned NEW message.');
        $messageId = (int)$messages[0]['message_id'];
        $messageIds[] = $messageId;
        $body = $connection->fetchOne($connection->select()->from($tables['queue_message'], 'body')->where('id = ?', $messageId));
        lcvxQueueAssert(is_string($body) && str_contains($body, $filename), 'Native encoded DTO did not preserve the test filename.');
        $worker->process(1);
        $status = (int)$connection->fetchOne($connection->select()->from($tables['queue_message_status'], 'status')
            ->where('queue_id = ?', $queueId)->where('message_id = ?', $messageId));
        lcvxQueueAssert($status === QueueManagement::MESSAGE_STATUS_COMPLETE, 'Native consumer did not acknowledge the owned message.');
        $notifications = $connection->fetchAll($connection->select()->from($tables['adminnotification_inbox'], ['notification_id', 'severity'])
            ->where('notification_id > ?', $noticeBefore));
        lcvxQueueAssert(count($notifications) === 1, 'Native consumer did not create one DB notification.');
        $notificationIds[] = (int)$notifications[0]['notification_id'];
        $severity = (int)$notifications[0]['severity'];
        lcvxQueueAssert($severity === ($expectFile ? 2 : 1), 'Notification severity does not match export outcome.');
        $final = $exportDirectory . '/' . $filename;
        lcvxQueueAssert(is_file($final) === $expectFile, 'Final file presence does not match expected outcome.');
        lcvxQueueAssert(!file_exists($final . '.tmp'), 'Owned queue temporary survived consumption.');
        lcvxQueueAssert($connection->getTransactionLevel() === 1, 'Consumer escaped the outer transaction.');
        $result = ['case' => $case, 'native_message_acknowledged' => true, 'notification_severity' => $severity,
            'final_file' => $expectFile, 'temporary_removed' => true, 'outer_transaction_intact' => true];
        $csv = null;
        if ($expectFile) {
            $csv = lcvxQueueCsv($final);
            [$header, $rows] = $csv;
            lcvxQueueAssert(count($rows) > 0, 'Successful file has no data.');
            $result['columns'] = count($header);
            $result['rows'] = count($rows);
            if ($mode !== null) {
                lcvxQueueAssert(array_slice($header, -4) === VideoResult::COLUMNS, 'Derived header is missing or reordered.');
                if ($mode === 'with' || $mode === 'without') {
                    foreach ($rows as $row) {
                        [$has, $count] = array_slice($row, -4, 2);
                        lcvxQueueAssert(ctype_digit($count) && ($mode === 'with') === ((int)$count > 0), 'Presence data/filter mismatch.');
                        lcvxQueueAssert($has === ((int)$count > 0 ? '1' : '0'), 'Presence columns disagree.');
                    }
                }
            } else {
                lcvxQueueAssert(!in_array('has_video', $header, true), 'Legacy queue job was enriched.');
            }
        }
        $report['cases'][] = $result;
        return $csv;
    };

    $stage = 'valid-queue-jobs';
    $allControl = $process('all', $samples['with'], 'all', true);
    $process('with', $samples['with'], 'with', true);
    $process('without', $samples['without'], 'without', true);
    $process('zero-matches', $samples['without'], 'with', false);
    $stage = 'invalid-worker-job';
    $process('invalid-worker', $samples['with'], 'all', false, 'invalid');

    $stage = 'reader-failure-recovery';
    class LcvxQueueFaultReader implements VideoReaderInterface
    {
        public bool $armed = true;
        public function __construct(private readonly BatchVideoReader $inner)
        {
        }
        public function read(array $productIds, int $storeId): array
        {
            if ($this->armed) {
                $this->armed = false;
                throw new RuntimeException('LCVX: injected queue reader failure.');
            }
            return $this->inner->read($productIds, $storeId);
        }
    }
    $faultReader = $om->get(LcvxQueueFaultReader::class);
    $om->configure([ProductExport::class => ['arguments' => ['videoReader' => ['instance' => LcvxQueueFaultReader::class]]]]);
    $process('reader-failure', $samples['with'], 'all', false);
    lcvxQueueAssert(!$faultReader->armed, 'Fault reader was not invoked through native export factory.');
    $process('legacy-after-failure', $samples['with'], null, true);
    $recovered = $process('enriched-after-failure', $samples['with'], 'all', true);
    lcvxQueueAssert($recovered === $allControl, 'Recovered queue export differs from clean control.');
    $report['same_worker_recovery'] = true;
    $report['owned_messages_consumed'] = count($messageIds);
    $report['owned_notifications_created'] = count($notificationIds);
    $report['result'] = 'PASS';
    $exitCode = 0;
} catch (Throwable $failure) {
    $report['result'] = 'FAIL';
    $report['stage'] = $stage;
    $report['exception'] = get_class($failure);
    $report['message'] = str_starts_with($failure->getMessage(), 'LCVX: ')
        ? $failure->getMessage() : 'Runtime failure; catalog data and native exception text intentionally excluded.';
    $report['location'] = basename($failure->getFile()) . ':' . $failure->getLine();
} finally {
    try {
        if ($connection !== null && $connection->getTransactionLevel() > 0) {
            while ($connection->getTransactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        if ($queueId !== null) {
            $report['owned_db_cleanup'] = [
                'queue_removed' => !(bool)$connection->fetchOne($connection->select()->from($tables['queue'], 'id')->where('id = ?', $queueId)),
                'message_statuses_removed' => (int)$connection->fetchOne($connection->select()->from($tables['queue_message_status'], ['COUNT(*)'])->where('queue_id = ?', $queueId)) === 0,
                'messages_removed' => $messageIds === [] || (int)$connection->fetchOne($connection->select()->from($tables['queue_message'], ['COUNT(*)'])->where('id IN (?)', $messageIds)) === 0,
                'notifications_removed' => $notificationIds === [] || (int)$connection->fetchOne($connection->select()->from($tables['adminnotification_inbox'], ['COUNT(*)'])->where('notification_id IN (?)', $notificationIds)) === 0,
                'locks_removed' => $messageIds === [] || (int)$connection->fetchOne($connection->select()->from($tables['queue_lock'], ['COUNT(*)'])
                    ->where('message_code IN (?)', array_map(static fn (int $id): string => md5('exportProcessor-' . $id), $messageIds))) === 0,
            ];
            lcvxQueueAssert(!in_array(false, $report['owned_db_cleanup'], true), 'Owned DB records survived rollback.');
        }
        lcvxQueueRemoveOwnedTree($temporary);
        $report['owned_runtime_removed'] = !file_exists($temporary);
        lcvxQueueAssert($report['owned_runtime_removed'], 'Owned runtime survived cleanup.');
    } catch (Throwable $cleanupFailure) {
        $report['result'] = 'FAIL';
        $report['cleanup_failure'] = get_class($cleanupFailure);
        $exitCode = 1;
    }
    $report['captured_utc'] = gmdate('c');
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($exitCode);
