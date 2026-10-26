<?php
/** Seven native export failure routes, with Bss/legacy parity and rollback-only queue transport. */
declare(strict_types=1);

use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use LeanCommerce\ProductVideoExport\Model\ProductExport;
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
$run = 'LCVX-failures-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
$temporary = '/tmp/' . $run;
$stage = 'bootstrap';
$connection = null;
$queueIds = [];
$messageIds = [];
$notificationIds = [];
$exitCode = 1;
$report = [
    'channel' => 'Native Admin DTO factory -> native Publisher/Encoder -> MySQL queue -> framework Consumer -> native export consumer/manager -> file and DB notification',
    'run' => $run,
    'php' => PHP_VERSION,
    'module_installed_persistently' => false,
    'limits' => [
        'Same process, one owned queue per trial, native MySQL transport in an uncommitted transaction; no cross-process/crash/concurrency acceptance.',
        'Stock/customizer failures occur on a nonempty second product batch after the first product was serialized.',
        'Log comparisons normalize only the exact owned temporary path, which differs between trials; no other message content is changed.',
        'Test subclasses inject errors or replay a real batch; native pagination, CSV serialization, publisher and consumer algorithms are inherited unchanged.',
        'Native Bss may publish partial results or retain a temporary; parity checks preserve and report that behavior before the harness cleans its own runtime.',
        'Factory is the Admin transport boundary; no browser session, HTTP controller, ACL, UI or download acceptance.',
        'Existing product sample; catalog data and customer identifiers are not recorded.',
        'Module enabled only in this process with native Developer DI; installed compiled DI remains separate.',
    ],
    'cases' => [],
    'variants' => ['before_raw', 'stock', 'customizer', 'header', 'cursor', 'write', 'contents'],
];

function lcvxFailuresAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('LCVX: ' . $message);
    }
}

function lcvxFailuresRemoveOwnedTree(string $path): void
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

function lcvxFailuresSourceInventory(string $moduleRoot, string $composer): array
{
    lcvxFailuresAssert(is_dir($moduleRoot) && !is_link(rtrim($moduleRoot, '/')), 'Module source root must be a real directory.');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        lcvxFailuresAssert(!$item->isLink(), 'Module source inventory cannot contain symlinks.');
        if ($item->isDir()) {
            continue;
        }
        lcvxFailuresAssert($item->isFile(), 'Module source inventory contains a nonregular file.');
        $relative = substr($item->getPathname(), strlen($moduleRoot));
        $files['src/LeanCommerce/ProductVideoExport/' . $relative] = hash_file('sha256', $item->getPathname());
    }
    foreach ([
        'composer.json' => $composer,
        'tests/integration/failures.php' => __FILE__,
        'tests/integration/FailureHarness.php' => __DIR__ . '/FailureHarness.php',
        'tests/integration/runner-support.php' => __DIR__ . '/runner-support.php',
    ] as $source => $path) {
        lcvxFailuresAssert($path !== '' && is_file($path) && !is_link($path), 'Complete M04 inventory requires candidate Composer and harness files.');
        $files[$source] = hash_file('sha256', $path);
    }
    foreach ($files as $sha256) {
        lcvxFailuresAssert(is_string($sha256) && preg_match('/^[a-f0-9]{64}$/D', $sha256) === 1, 'Could not hash a source file.');
    }
    ksort($files);
    return $files;
}

try {
    lcvxFailuresAssert(mkdir($temporary, 0700), 'Could not create owned runtime.');
    require $root . '/app/bootstrap.php';
    $loader = require $root . '/vendor/autoload.php';
    $moduleRoot = rtrim(getenv('LCVX_MODULE_ROOT') ?: $root . '/var/lcvx-product-video-export/module', '/') . '/';
    $composerCandidate = (string)getenv('LCVX_CANDIDATE_COMPOSER');
    // Capture before registering/loading candidate code; never infer this from a later checkout.
    $report['source_inventory'] = [
        'schema_version' => 1,
        'scope' => 'm04-production-and-harness-v1',
        'sha256' => lcvxFailuresSourceInventory($moduleRoot, $composerCandidate),
        'unchanged_after_execution' => false,
    ];
    $loader->addPsr4('LeanCommerce\\ProductVideoExport\\', $moduleRoot);
    $registrar = new ComponentRegistrar();
    $registered = $registrar->getPath(ComponentRegistrar::MODULE, 'LeanCommerce_ProductVideoExport');
    if ($registered === null) {
        require $moduleRoot . 'registration.php';
    } else {
        lcvxFailuresAssert(realpath($registered) === realpath($moduleRoot), 'Module registration is outside the probe copy.');
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
    class LcvxFailuresEnvironmentFactory extends Magento\Framework\App\EnvironmentFactory
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
        protected $envFactoryClassName = LcvxFailuresEnvironmentFactory::class;
    };
    $om = Bootstrap::create(BP, $params, $factory)->getObjectManager();
    $om->configure([
        Magento\Framework\Logger\Handler\Base::class => ['arguments' => ['filePath' => $temporary]],
    ]);
    $om->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    $om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('adminhtml'));
    foreach (['cache', 'log', 'code', 'metadata', 'import_export'] as $code) {
        lcvxFailuresAssert(str_starts_with($om->get(DirectoryList::class)->getPath($code), $temporary . '/'), 'Writable runtime path is not isolated.');
    }
    lcvxFailuresAssert(
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
    lcvxFailuresAssert(
        $om->get(Magento\Framework\App\ObjectManager\ConfigWriterInterface::class)
            instanceof Magento\Framework\App\ObjectManager\ConfigWriter\Filesystem,
        'Unexpected interception metadata writer.'
    );
    $interception->initialize();
    // Developer bootstrap resets shared services after retaining its first interception config.
    // Bind the refreshed native instance explicitly to this process's DI configuration.
    $om->get(Magento\Framework\Interception\ObjectManager\ConfigInterface::class)->setInterceptionConfig($interception);
    lcvxFailuresAssert($interception->hasPlugins(Magento\ImportExport\Model\Export::class) && $interception->hasPlugins(ExportConsumer::class), 'Native XML did not register export cleanup plugins.');
    $report['interception_rebuilt_in_owned_metadata'] = is_file($temporary . '/metadata/interception.php');
    $entities = $om->get(Magento\ImportExport\Model\Export\ConfigInterface::class)->getEntities();
    lcvxFailuresAssert($entities['catalog_product']['model'] === ProductExport::class, 'Native export.xml merge did not select ProductExport.');
    $report['native_xml_merge'] = true;
    $report['di_config'] = get_class($om->get(Magento\Framework\ObjectManager\ConfigInterface::class));
    $report['source_sha256'] = [];
    foreach (['etc/di.xml', 'etc/export.xml', 'Model/ProductExport.php', 'Model/ScopedAdapterFactory.php', 'Model/OwnedCsv.php', 'Model/CsvFileClaim.php', 'Model/ScopedExportLogger.php', 'Model/ScopedVideoWriter.php', 'Plugin/ExportCleanupPlugin.php', 'Plugin/ConsumerCleanupPlugin.php'] as $file) {
        $report['source_sha256'][$file] = hash_file('sha256', $moduleRoot . $file);
    }

    require __DIR__ . '/FailureHarness.php';

    $report['native_source_sha256'] = [];
    foreach ([
        'vendor/magento/module-catalog-import-export/Model/Export/Product.php',
        'app/code/Bss/CustomProductAttributeExport/Model/Rewrite/CatalogImportExport/Export/Product.php',
        'vendor/magento/module-import-export/Model/Export/Consumer.php',
        'vendor/magento/module-import-export/Model/Export/Adapter/Csv.php',
        'vendor/magento/framework/Filesystem/Driver/File.php',
        'vendor/magento/framework/Filesystem/File/Write.php',
    ] as $file) {
        $report['native_source_sha256'][$file] = hash_file('sha256', $root . '/' . $file);
    }

    $stage = 'preflight';
    $resource = $om->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $tables = [];
    foreach (['queue', 'queue_message', 'queue_message_status', 'queue_lock', 'adminnotification_inbox'] as $name) {
        $tables[$name] = $resource->getTableName($name);
        lcvxFailuresAssert($connection->isTableExists($tables[$name]), 'A required integration table is missing.');
    }
    $queueResource = $om->get(Magento\MysqlMq\Model\ResourceModel\Queue::class);
    $notifier = $om->get(Magento\Framework\Notification\NotifierInterface::class);
    lcvxFailuresAssert($notifier instanceof Magento\Framework\Notification\NotifierPool, 'Unexpected native notifier pool.');
    $notifiers = array_values($om->get(Magento\Framework\Notification\NotifierList::class)->asArray());
    lcvxFailuresAssert(count($notifiers) === 1 && $notifiers[0] instanceof Magento\AdminNotification\Model\Inbox, 'Unexpected native notifier; cannot guarantee DB-only effects.');
    lcvxFailuresAssert($queueResource->getConnection() === $connection, 'Queue uses a different DB connection.');
    lcvxFailuresAssert($notifiers[0]->getResource()->getConnection() === $connection, 'Notifier uses a different DB connection.');
    lcvxFailuresAssert($connection->getTransactionLevel() === 0, 'A transaction was already open.');
    lcvxFailuresAssert(!$connection->fetchOne($connection->select()->from($tables['queue'], 'id')->where('name = ?', $run)), 'Run queue already exists.');
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
    $productTable = $resource->getTableName('catalog_product_entity');
    $sku = null;
    $sampleCount = 0;
    $seenPrefixes = [];
    foreach ($connection->fetchCol($connection->select()->from($productTable, 'sku')->order('entity_id ASC')->limit(50)) as $candidate) {
        if (!preg_match('/^[A-Za-z0-9-]{4,}$/D', $candidate)) {
            continue;
        }
        for ($length = strlen($candidate) - 1; $length >= 3; --$length) {
            $prefix = substr($candidate, 0, $length);
            if (isset($seenPrefixes[$prefix])) {
                continue;
            }
            $seenPrefixes[$prefix] = true;
            $count = (int)$connection->fetchOne($connection->select()->from($productTable, ['COUNT(*)'])->where('sku LIKE ?', '%' . $prefix . '%'));
            if ($count >= 2 && $count <= 6) {
                $sku = $prefix;
                $sampleCount = $count;
                break 2;
            }
        }
    }
    lcvxFailuresAssert($sku !== null, 'Could not select a bounded existing native SKU filter with 2–6 products.');
    $report['sample'] = ['products' => $sampleCount, 'page_size' => 1, 'catalog_writes' => false];
    $report['test_sha256'] = [
        'tests/integration/failures.php' => hash_file('sha256', __FILE__),
        'tests/integration/FailureHarness.php' => hash_file('sha256', __DIR__ . '/FailureHarness.php'),
    ];

    $stage = 'configure-scoped-seams';
    $faultState = $om->get(LeanCommerce\ProductVideoExport\Test\Integration\Failures\FailureState::class);
    $recorder = $om->get(LeanCommerce\ProductVideoExport\Test\Integration\Failures\RecordingLogger::class);
    $entityFactory = $om->get(LeanCommerce\ProductVideoExport\Test\Integration\Failures\FailureEntityFactory::class);
    $faultFilesystem = $om->get(LeanCommerce\ProductVideoExport\Test\Integration\Failures\FailureFilesystem::class);
    $om->configure([
        Magento\ImportExport\Model\Export::class => ['arguments' => [
            'entityFactory' => ['instance' => get_class($entityFactory)],
            'logger' => ['instance' => get_class($recorder)],
        ]],
        Magento\ImportExport\Model\Export\Adapter\Csv::class => ['arguments' => [
            'filesystem' => ['instance' => get_class($faultFilesystem)],
        ]],
        LeanCommerce\ProductVideoExport\Model\OwnedCsv::class => ['arguments' => [
            'filesystem' => ['instance' => get_class($faultFilesystem)],
        ]],
    ]);
    $manager = new LeanCommerce\ProductVideoExport\Test\Integration\Failures\RecordingExportManager(
        $om->create(Magento\ImportExport\Model\Export\ExportManagement::class), $faultState
    );
    $nativeConsumer = $om->create(ExportConsumer::class, ['exportManager' => $manager, 'logger' => $recorder]);
    $report['native_consumer_class'] = get_class($nativeConsumer);
    lcvxFailuresAssert($nativeConsumer instanceof Magento\Framework\Interception\InterceptorInterface, 'Native consumer is not intercepted.');
    lcvxFailuresAssert($om->get(Magento\Framework\MessageQueue\Consumer\ConfigInterface::class)->getConsumer('exportProcessor')->getConnection() === 'db', 'Native export consumer is not using DB.');
    $dtoFactory = $om->create(ExportInfoFactory::class, ['entityFactory' => $entityFactory, 'logger' => $recorder]);
    $connection->beginTransaction();
    $serial = 0;

    foreach ($report['variants'] as $variant) {
        $results = [];
        foreach (['all', 'bss', 'legacy'] as $flavor) {
            $stage = $variant . '-' . $flavor;
            foreach (get_object_vars(new LeanCommerce\ProductVideoExport\Test\Integration\Failures\FailureState()) as $property => $value) {
                $faultState->$property = $value;
            }
            $faultState->variant = $variant;
            $faultState->flavor = $flavor;
            $name = $run . '-' . ++$serial;
            $filename = $name . '.csv';
            $final = $temporary . '/import_export/export/' . $filename;
            $faultState->absoluteTmp = $final . '.tmp';
            $queue = $om->create(Queue::class)->setName($name)->save();
            $queueId = (int)$queue->getId();
            $queueIds[] = $queueId;
            lcvxFailuresAssert($queueId > 0 && $connection->getTransactionLevel() === 1, 'Queue creation escaped the outer transaction.');
            $exchangeItem = $om->create(ExchangeConfigItem::class);
            $exchangeItem->setData([
                'name' => $name, 'type' => 'topic', 'connection' => 'db', 'internal' => false,
                'durable' => false, 'autoDelete' => true, 'arguments' => [],
                'bindings' => [[
                    'id' => $name, 'destinationType' => 'queue', 'destination' => $name,
                    'topic' => 'import_export.export', 'disabled' => false, 'arguments' => [],
                ]],
            ]);
            $queueItem = new QueueConfigItem();
            $queueItem->setData(['name' => $name, 'connection' => 'db', 'durable' => false, 'autoDelete' => true, 'arguments' => []]);
            $topology = new class($exchangeItem, $queueItem) implements TopologyConfigInterface {
                public function __construct(private readonly ExchangeConfigItem $exchange, private readonly QueueConfigItem $queue)
                {
                }
                public function getExchange($name, $connection)
                {
                    lcvxFailuresAssert($name === $this->exchange->getName() && $connection === 'db', 'Unexpected exchange request.');
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
                    lcvxFailuresAssert($connectionName === 'db', 'Publisher selected a non-DB connection.');
                    return $this->ownedExchange;
                }
            };
            $publisher = $om->create(Publisher::class, ['exchangeRepository' => $exchanges]);
            $configuration = $om->create(ConsumerConfiguration::class, ['data' => [
                'consumer_name' => 'exportProcessor', 'queue_name' => $name, 'max_messages' => 1,
                'max_idle_time' => 1, 'sleep' => 1, 'only_spawn_when_message_available' => true,
                'topics' => ['import_export.export' => ['consumer_type' => 'async', 'handlers' => [[$nativeConsumer, 'process']]]],
            ]]);
            $worker = $om->create(Consumer::class, ['configuration' => $configuration]);
            $filters = ['sku' => $sku];
            if ($flavor === 'all') {
                $filters[PresenceParameters::KEY] = 'all';
            }
            $dto = $dtoFactory->create('csv', 'catalog_product', $filters, [], 'en_US', false);
            $dto->setFileName($filename);
            $noticeBefore = (int)$connection->fetchOne($connection->select()->from($tables['adminnotification_inbox'], ['MAX(notification_id)']));
            $publisher->publish('import_export.export', $dto);
            $messages = $connection->fetchAll($connection->select()->from($tables['queue_message_status'], ['message_id', 'status'])->where('queue_id = ?', $queueId));
            lcvxFailuresAssert(count($messages) === 1 && (int)$messages[0]['status'] === QueueManagement::MESSAGE_STATUS_NEW, 'Expected one own NEW message.');
            $messageId = (int)$messages[0]['message_id'];
            $messageIds[] = $messageId;
            $workerFailure = null;
            try {
                $worker->process(1);
            } catch (Throwable $failure) {
                $workerFailure = ['exception' => get_class($failure), 'message_sha256' => hash('sha256', $faultState->comparableMessage($failure->getMessage()))];
            }
            clearstatcache();
            $status = (int)$connection->fetchOne($connection->select()->from($tables['queue_message_status'], 'status')->where('queue_id = ?', $queueId)->where('message_id = ?', $messageId));
            $notifications = $connection->fetchAll($connection->select()->from($tables['adminnotification_inbox'], ['notification_id', 'severity'])->where('notification_id > ?', $noticeBefore));
            foreach ($notifications as $notice) {
                $notificationIds[] = (int)$notice['notification_id'];
            }
            $result = [
                'faults_injected' => $faultState->faults,
                'entity_classes' => $faultState->entities,
                'manager' => $faultState->manager,
                'worker_exception' => $workerFailure,
                'message_status' => $status,
                'notification_severities' => array_map(static fn (array $row): int => (int)$row['severity'], $notifications),
                'final_file' => is_file($final),
                'temporary_file' => is_file($final . '.tmp'),
                'csv_writes' => $faultState->csvWrites,
                'writes_before_fault' => $faultState->writesBeforeFault,
                'fault_batch_products' => $faultState->faultBatchProducts,
                'loads' => $faultState->loads,
                'stock_calls' => $faultState->stockCalls,
                'customizer_calls' => $faultState->customizerCalls,
                'logs' => $faultState->logs,
                'outer_transaction_intact' => $connection->getTransactionLevel() === 1,
            ];
            foreach (['final' => $final, 'temporary' => $final . '.tmp'] as $label => $path) {
                $result[$label . '_bytes'] = is_file($path) ? filesize($path) : null;
                $result[$label . '_sha256'] = is_file($path) ? hash_file('sha256', $path) : null;
            }
            $results[$flavor] = $result;
            $report['cases'][$variant] = $results;
            lcvxFailuresAssert($faultState->faults > 0, 'Fault seam was not reached: ' . $stage);
            lcvxFailuresAssert($result['outer_transaction_intact'], 'Consumer escaped the outer transaction.');
            if ($flavor === 'all') {
                lcvxFailuresAssert(is_a($result['manager']['exception'] ?? '', LocalizedException::class, true), 'Enriched failure was not localized: ' . $variant);
                lcvxFailuresAssert($workerFailure === null && $status === QueueManagement::MESSAGE_STATUS_COMPLETE, 'Enriched failure was not handled and acknowledged.');
                lcvxFailuresAssert($result['notification_severities'] === [1], 'Enriched failure did not create one critical notification.');
                lcvxFailuresAssert(!$result['final_file'] && !$result['temporary_file'], 'Failed enriched export left an artifact: ' . $variant);
                if (in_array($variant, ['stock', 'customizer', 'cursor', 'write', 'contents'], true)) {
                    lcvxFailuresAssert($faultState->writesBeforeFault >= 2, 'Fault did not occur after a native data row.');
                }
                if (in_array($variant, ['stock', 'customizer'], true)) {
                    lcvxFailuresAssert($faultState->faultBatchProducts === 1, 'Fault occurred on an empty terminal batch.');
                }
                if ($variant === 'cursor') {
                    lcvxFailuresAssert(count(array_filter($faultState->logs, static fn (array $row): bool => $row['native_cursor_critical'])) === 1, 'Native cursor guard did not log critical.');
                    lcvxFailuresAssert($faultState->csvWrites > $faultState->writesBeforeFault, 'Repeated batch did not reach native serialization.');
                }
            }
        }
        $parityKeys = ['manager', 'worker_exception', 'message_status', 'notification_severities', 'final_file', 'temporary_file', 'final_bytes', 'final_sha256', 'temporary_bytes', 'temporary_sha256', 'logs'];
        $parity = [];
        foreach ($parityKeys as $key) {
            $parity[$key] = $results['bss'][$key] === $results['legacy'][$key];
        }
        $report['cases'][$variant]['bss_legacy_parity'] = $parity;
        lcvxFailuresAssert(!in_array(false, $parity, true), 'Legacy differed from Bss for ' . $variant);
        $report['cases'][$variant]['result'] = 'PASS';
    }
    $report['owned_messages_processed'] = count($messageIds);
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
        if ($queueIds !== []) {
            $report['owned_db_cleanup'] = [
                'queues_removed' => (int)$connection->fetchOne($connection->select()->from($tables['queue'], ['COUNT(*)'])->where('id IN (?)', $queueIds)) === 0,
                'message_statuses_removed' => (int)$connection->fetchOne($connection->select()->from($tables['queue_message_status'], ['COUNT(*)'])->where('queue_id IN (?)', $queueIds)) === 0,
                'messages_removed' => $messageIds === [] || (int)$connection->fetchOne($connection->select()->from($tables['queue_message'], ['COUNT(*)'])->where('id IN (?)', $messageIds)) === 0,
                'notifications_removed' => $notificationIds === [] || (int)$connection->fetchOne($connection->select()->from($tables['adminnotification_inbox'], ['COUNT(*)'])->where('notification_id IN (?)', $notificationIds)) === 0,
                'locks_removed' => $messageIds === [] || (int)$connection->fetchOne($connection->select()->from($tables['queue_lock'], ['COUNT(*)'])->where('message_code IN (?)', array_map(static fn (int $id): string => md5('exportProcessor-' . $id), $messageIds))) === 0,
            ];
            lcvxFailuresAssert(!in_array(false, $report['owned_db_cleanup'], true), 'Owned DB records survived rollback.');
        }
        lcvxFailuresRemoveOwnedTree($temporary);
        $report['owned_runtime_removed'] = !file_exists($temporary);
        lcvxFailuresAssert($report['owned_runtime_removed'], 'Owned runtime survived cleanup.');
        if (isset($report['source_inventory'])) {
            $after = lcvxFailuresSourceInventory($moduleRoot, $composerCandidate);
            lcvxFailuresAssert($report['source_inventory']['sha256'] === $after, 'M04 source inventory changed during execution.');
            $report['source_inventory']['unchanged_after_execution'] = true;
        }
    } catch (Throwable $cleanupFailure) {
        $report['result'] = 'FAIL';
        $report['cleanup_failure'] = get_class($cleanupFailure);
        $exitCode = 1;
    }
    $report['captured_utc'] = gmdate('c');
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($exitCode);
