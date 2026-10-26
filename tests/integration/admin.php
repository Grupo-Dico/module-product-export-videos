<?php
/** Native Admin block rendering and ACL contracts, without browser login or persistent roles. */
declare(strict_types=1);

use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\ImportExport\Block\Adminhtml\Export\Filter;
use Magento\ImportExport\Model\Export;

define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once __DIR__ . '/runner-support.php';
$root = lcvxIntegrationRoot();
$run = 'LCVX-admin-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
$temporary = '/tmp/' . $run;
$stage = 'bootstrap';
$connection = null;
$exitCode = 1;
$failedCases = false;
$report = [
    'channel' => 'Native Admin form and filter HTML plus native ACL resources/policy with in-memory roles',
    'run' => $run,
    'php' => PHP_VERSION,
    'module_installed_persistently' => false,
    'limits' => [
        'Server-side block rendering; no browser, JavaScript, AJAX navigation or authenticated HTTP session acceptance.',
        'Native ACL resources, builder and policy; roles/rules are ephemeral loaders, not persisted Admin users or roles.',
        'Controller authorization method is exercised without dispatch; HTTP status, redirects and actual downloads are not accepted by this probe.',
        'Read-only database transaction; no catalog values, credentials, full form HTML or form keys are recorded.',
        'Developer DI is isolated in this process; installed compiled DI and production configuration are not changed.',
    ],
    'cases' => [],
];

function lcvxAdminAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('LCVX: ' . $message);
    }
}

function lcvxAdminRemoveOwnedTree(string $path): void
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

function lcvxAdminCopyModule(string $source, string $destination): void
{
    lcvxAdminAssert(is_dir($source) && is_file($source . '/registration.php'), 'Module source is unavailable.');
    lcvxAdminAssert(mkdir($destination, 0700), 'Could not create owned module copy.');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        lcvxAdminAssert(!$item->isLink(), 'Module probe does not follow source symlinks.');
        $target = $destination . '/' . $iterator->getSubPathName();
        if ($item->isDir()) {
            lcvxAdminAssert(mkdir($target, 0700), 'Could not copy module directory.');
        } else {
            lcvxAdminAssert(copy($item->getPathname(), $target), 'Could not copy module source.');
        }
    }
}

function lcvxAdminDocument(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        lcvxAdminAssert($document->loadHTML('<?xml encoding="UTF-8">' . $html), 'HTML could not be parsed.');
        return [$document, new DOMXPath($document)];
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

try {
    lcvxAdminAssert(mkdir($temporary, 0700), 'Could not create owned runtime.');
    $moduleRoot = $temporary . '/module';
    lcvxAdminCopyModule(getenv('LCVX_MODULE_ROOT') ?: ($argv[1] ?? $root . '/var/lcvx-product-video-export/module'), $moduleRoot);
    require $root . '/app/bootstrap.php';
    $loader = require $root . '/vendor/autoload.php';
    $loader->addPsr4('LeanCommerce\\ProductVideoExport\\', $moduleRoot . '/', true);
    $registered = (new ComponentRegistrar())->getPath(ComponentRegistrar::MODULE, 'LeanCommerce_ProductVideoExport');
    lcvxAdminAssert($registered === null, 'Module is already registered outside the owned runtime.');
    require $moduleRoot . '/registration.php';
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
    ];
    class LcvxAdminEnvironmentFactory extends Magento\Framework\App\EnvironmentFactory
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
        protected $envFactoryClassName = LcvxAdminEnvironmentFactory::class;
    };
    $om = Bootstrap::create(BP, $params, $factory)->getObjectManager();
    $om->get(Magento\Framework\App\State::class)->setAreaCode('adminhtml');
    $om->configure($om->get(Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('adminhtml'));
    $om->get(Magento\Framework\Config\ScopeInterface::class)->setCurrentScope('adminhtml');
    foreach (array_keys($paths) as $code) {
        lcvxAdminAssert(str_starts_with($om->get(DirectoryList::class)->getPath($code), $temporary . '/'), 'Runtime path is not isolated.');
    }
    lcvxAdminAssert(
        $om->get(Magento\Framework\App\DeploymentConfig::class)->get('cache/frontend/default/backend') === 'Cm_Cache_Backend_File',
        'Cache backend is not isolated.'
    );
    lcvxAdminAssert(
        $om->get(Magento\Framework\App\ObjectManager\ConfigWriterInterface::class)
            instanceof Magento\Framework\App\ObjectManager\ConfigWriter\Filesystem,
        'Unexpected metadata writer.'
    );
    $interception = $om->get(Magento\Framework\Interception\Config\Config::class);
    $interception->initialize();
    $om->get(Magento\Framework\Interception\ObjectManager\ConfigInterface::class)->setInterceptionConfig($interception);
    lcvxAdminAssert($interception->hasPlugins(Filter::class), 'Native interception does not include the Admin filter.');
    $plugins = $om->get(Magento\Framework\Interception\PluginListInterface::class);
    $chain = $plugins->getNext(Filter::class, 'decorateFilter');
    lcvxAdminAssert(
        in_array('lc_product_video_export_filter', $chain[Magento\Framework\Interception\DefinitionInterface::LISTENER_AFTER] ?? [], true),
        'The real Admin filter plugin is not in the active chain.'
    );
    $report['preflight'] = [
        'disk_free_bytes' => disk_free_space($temporary),
        'memory_limit' => ini_get('memory_limit'),
        'runtime_directories_isolated' => true,
        'native_admin_plugin_active' => true,
        'interception_rebuilt_in_owned_metadata' => is_file($temporary . '/metadata/interception.php'),
    ];
    $report['source_sha256'] = [];
    foreach (['etc/di.xml', 'etc/adminhtml/di.xml', 'Plugin/ExportFilterPlugin.php', 'Model/PresenceParameters.php', 'Model/Source/VideoPresenceOptions.php', 'i18n/es_MX.csv'] as $file) {
        $report['source_sha256'][$file] = hash_file('sha256', $moduleRoot . '/' . $file);
    }

    $stage = 'read-only-transaction';
    $connection = $om->get(ResourceConnection::class)->getConnection();
    lcvxAdminAssert($connection->getTransactionLevel() === 0, 'A transaction was already open.');
    $connection->query('SET TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $report['preflight']['database_read_only_transaction'] = true;
    $request = $om->get(Magento\Framework\App\RequestInterface::class);
    $request->setControllerModule('Magento_ImportExport');
    $request->setRouteName('adminhtml')->setControllerName('export')->setActionName('index');
    $om->get(Magento\Framework\Locale\ResolverInterface::class)->setLocale('es_MX');
    $om->get(Magento\Framework\App\AreaList::class)->getArea('adminhtml')->load(Magento\Framework\App\Area::PART_DESIGN);
    $translator = $om->get(Magento\Framework\TranslateInterface::class);
    $translator->setLocale('es_MX')->loadData('adminhtml', true);
    Magento\Framework\Phrase::setRenderer($om->get(Magento\Framework\Phrase\RendererInterface::class));

    $stage = 'native-form';
    $layout = $om->create(Magento\Framework\View\LayoutInterface::class);
    $form = $layout->createBlock(Magento\ImportExport\Block\Adminhtml\Export\Edit\Form::class, 'lcvx.export.form');
    [$formDocument, $formPath] = lcvxAdminDocument($form->toHtml());
    lcvxAdminAssert($formPath->query('//form[@id="edit_form" and @method="post"]')->length === 1, 'Native export form missing.');
    foreach (['entity', 'file_format'] as $field) {
        lcvxAdminAssert($formPath->query('//select[@name="' . $field . '"]')->length === 1, 'Native form select missing.');
    }
    lcvxAdminAssert($formPath->query('//input[@name="form_key"]')->length === 1, 'Native form key missing.');
    lcvxAdminAssert($formPath->query('//select[@name="export_filter[lc_product_video_export_presence]"]')->length === 0, 'Presence must belong to the entity filter grid.');
    $report['cases'][] = ['name' => 'native_export_form_controls_and_form_key', 'status' => 'PASS'];

    $stage = 'product-filter-grid';
    $provider = $om->get(Magento\ImportExport\Model\Export\EntityFiltersProviderInterface::class);
    $nativeMethod = new ReflectionMethod(Filter::class, 'decorateFilter');
    foreach (['default' => null, 'all' => 'all', 'with' => 'with', 'without' => 'without'] as $label => $mode) {
        $filters = ['sku' => 'LCVX-ADMIN-SYNTHETIC', 'price' => ['0', '10']];
        if ($mode !== null) {
            $filters[PresenceParameters::KEY] = $mode;
        }
        $export = $om->create(Export::class)->setData([
            'entity' => 'catalog_product', 'file_format' => 'csv', 'export_filter' => $filters,
        ]);
        $attributes = $provider->getFilters($export);
        $own = $attributes->getItemByColumnValue('attribute_code', PresenceParameters::KEY);
        lcvxAdminAssert($own !== null, 'Native provider did not include the video filter.');
        lcvxAdminAssert((string)__($own->getDefaultFrontendLabel()) === 'Presencia de video', 'Spanish presence label mismatch.');
        $grid = $layout->createBlock(Filter::class, 'lcvx.product.' . $label);
        lcvxAdminAssert($grid instanceof Magento\Framework\Interception\InterceptorInterface, 'Filter was not intercepted.');
        $grid->setOperation($export)->prepareCollection($attributes);
        [$document, $path] = lcvxAdminDocument($grid->toHtml());
        $nodes = $path->query('//select[@name="export_filter[lc_product_video_export_presence]"]');
        lcvxAdminAssert($nodes->length === 1, 'Rendered grid has no unique presence select.');
        $select = $nodes->item(0);
        lcvxAdminAssert($select->getAttribute('id') === 'export_filter_lc_product_video_export_presence', 'Presence element id changed.');
        $values = $labels = $selected = [];
        foreach ($select->getElementsByTagName('option') as $option) {
            $values[] = $option->getAttribute('value');
            $labels[] = trim($option->textContent);
            if ($option->hasAttribute('selected')) {
                $selected[] = $option->getAttribute('value');
            }
        }
        lcvxAdminAssert($values === ['all', 'with', 'without'], 'Presence values or empty placeholder differ.');
        lcvxAdminAssert($labels === ['Todos', 'Con video', 'Sin video'], 'Spanish option labels differ.');
        lcvxAdminAssert($selected === [$mode ?? 'all'], 'Presence selection/default differs.');
        $labelNodes = $path->query('ancestor::tr[1]/td[contains(@class, "col-frontend_label")]', $select);
        lcvxAdminAssert($labelNodes->length === 1, 'Own label cell is missing.');
        $gridLabel = trim($labelNodes->item(0)->textContent);
        $labelMatches = $gridLabel === 'Presencia de video';
        $failedCases = $failedCases || !$labelMatches;
        foreach (['sku', 'price', 'status'] as $code) {
            $attribute = $attributes->getItemByColumnValue('attribute_code', $code);
            lcvxAdminAssert($attribute !== null, 'Required native filter is absent.');
            $column = new Magento\Framework\DataObject(['values' => $filters]);
            $actual = (string)$grid->decorateFilter('', $attribute, $column, false);
            $native = (string)$nativeMethod->invoke($grid, '', $attribute, $column, false);
            lcvxAdminAssert($actual === $native, 'Plugin changed an unrelated native filter.');
        }
        $report['cases'][] = [
            'name' => 'product_grid_' . $label, 'status' => $labelMatches ? 'PASS' : 'FAIL',
            'values' => $values, 'labels' => $labels, 'selected' => $selected,
            'spanish_grid_label' => $labelMatches,
            'grid_label' => $gridLabel,
            'native_filters_byte_equal' => ['sku', 'price', 'status'],
            'presence_select_html' => $document->saveHTML($select),
        ];
    }

    $stage = 'english-filter-after-locale-change';
    $om->get(Magento\Framework\Locale\ResolverInterface::class)->setLocale('en_US');
    $translator->setLocale('en_US')->loadData('adminhtml', true);
    $export = $om->create(Export::class)->setData([
        'entity' => 'catalog_product', 'file_format' => 'csv',
        'export_filter' => [PresenceParameters::KEY => 'all'],
    ]);
    $grid = $layout->createBlock(Filter::class, 'lcvx.product.english');
    $grid->setOperation($export)->prepareCollection($provider->getFilters($export));
    [$document, $path] = lcvxAdminDocument($grid->toHtml());
    $select = $path->query('//select[@name="export_filter[lc_product_video_export_presence]"]')->item(0);
    lcvxAdminAssert($select instanceof DOMElement, 'English presence select missing.');
    $englishLabels = [];
    foreach ($select->getElementsByTagName('option') as $option) {
        $englishLabels[] = trim($option->textContent);
    }
    $englishLabel = trim($path->query('ancestor::tr[1]/td[contains(@class, "col-frontend_label")]', $select)->item(0)->textContent);
    lcvxAdminAssert($englishLabel === 'Video presence', 'Presence label was frozen in a previous locale.');
    lcvxAdminAssert($englishLabels === ['All', 'With video', 'Without video'], 'English option labels differ.');
    $report['cases'][] = [
        'name' => 'english_filter_after_locale_change', 'status' => 'PASS',
        'grid_label' => $englishLabel, 'labels' => $englishLabels,
    ];

    $stage = 'other-entities';
    $entities = $om->get(Magento\ImportExport\Model\Export\ConfigInterface::class)->getEntities();
    foreach (['customer', 'customer_address'] as $entity) {
        lcvxAdminAssert(isset($entities[$entity]), 'Expected native comparison entity is unavailable.');
        $export = $om->create(Export::class)->setData(['entity' => $entity, 'file_format' => 'csv']);
        $attributes = $provider->getFilters($export);
        lcvxAdminAssert($attributes->getItemByColumnValue('attribute_code', PresenceParameters::KEY) === null, 'Presence leaked to another entity provider.');
        $grid = $layout->createBlock(Filter::class, 'lcvx.' . $entity);
        $grid->setOperation($export)->prepareCollection($attributes);
        [, $path] = lcvxAdminDocument($grid->toHtml());
        lcvxAdminAssert($path->query('//*[@name="export_filter[lc_product_video_export_presence]"]')->length === 0, 'Presence leaked to another entity HTML.');
        $report['cases'][] = ['name' => $entity . '_has_no_video_filter', 'status' => 'PASS'];
    }

    $stage = 'native-form-key-validation';
    $validator = $om->get(Magento\Framework\Data\Form\FormKey\Validator::class);
    $formKey = $om->get(Magento\Framework\Data\Form\FormKey::class)->getFormKey();
    foreach (['valid' => [$formKey, true], 'missing' => [null, false], 'invalid' => ['LCVX-invalid-form-key', false]] as $label => [$key, $expected]) {
        $request->setParams(['form_key' => $key]);
        lcvxAdminAssert((bool)$validator->validate($request) === $expected, 'Native form key validator result differs.');
        $report['cases'][] = ['name' => 'form_key_' . $label, 'status' => 'PASS'];
    }

    $stage = 'native-acl-resources-and-policy';
    $roleLoader = new class implements Magento\Framework\Acl\LoaderInterface {
        public function populateAcl(Magento\Framework\Acl $acl)
        {
            foreach (['910000001', '910000002', '910000003'] as $role) {
                $acl->addRole(new Laminas\Permissions\Acl\Role\GenericRole($role));
            }
        }
    };
    $ruleLoader = new class implements Magento\Framework\Acl\LoaderInterface {
        public function populateAcl(Magento\Framework\Acl $acl)
        {
            $acl->allow('910000001', 'Magento_ImportExport::export');
            $acl->allow('910000002', 'Magento_ImportExport::import');
            $acl->allow('910000003', 'Magento_Backend::admin');
            $acl->deny('910000003', 'Magento_ImportExport::export');
        }
    };
    $builder = new Magento\Framework\Acl\Builder(
        $om->get(Magento\Framework\AclFactory::class),
        $roleLoader,
        $om->get(Magento\Framework\Acl\Loader\ResourceLoader::class),
        $ruleLoader
    );
    $acl = $builder->getAcl();
    lcvxAdminAssert($acl->hasResource('Magento_ImportExport::export'), 'Export ACL resource is missing from native XML.');
    foreach ($acl->getResources() as $resourceId) {
        lcvxAdminAssert(!str_starts_with($resourceId, 'LeanCommerce_ProductVideoExport::'), 'Module added an unexpected ACL resource.');
    }
    $roleLocator = new class implements Magento\Framework\Authorization\RoleLocatorInterface {
        public ?string $roleId = null;
        public function getAclRoleId()
        {
            return $this->roleId;
        }
    };
    $roleContext = new Magento\Framework\Acl\Role\CurrentRoleContext();
    $authorization = new Magento\Framework\Authorization(
        new Magento\Framework\Authorization\Policy\Acl($builder, $roleContext),
        $roleLocator
    );
    $controllers = [
        Magento\ImportExport\Controller\Adminhtml\Export\Index::class,
        Magento\ImportExport\Controller\Adminhtml\Export\GetFilter::class,
        Magento\ImportExport\Controller\Adminhtml\Export\Export::class,
        Magento\ImportExport\Controller\Adminhtml\Export\File\Download::class,
    ];
    foreach ($controllers as $controllerClass) {
        lcvxAdminAssert($controllerClass::ADMIN_RESOURCE === 'Magento_ImportExport::export', 'Controller changed its native resource.');
        $reflection = new ReflectionClass($controllerClass);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('_authorization')->setValue($controller, $authorization);
        foreach (['export_allowed' => ['910000001', true], 'import_only' => ['910000002', false], 'explicit_denied' => ['910000003', false], 'anonymous' => [null, false]] as $label => [$role, $expected]) {
            $roleLocator->roleId = $role;
            $actual = $reflection->getMethod('_isAllowed')->invoke($controller);
            lcvxAdminAssert($actual === $expected, 'Native controller ACL decision differs.');
            lcvxAdminAssert($roleContext->getRoleId() === null, 'Native policy leaked its role context.');
        }
        $report['cases'][] = [
            'name' => 'controller_acl_' . str_replace('Magento\\ImportExport\\Controller\\Adminhtml\\', '', $controllerClass),
            'status' => 'PASS', 'resource' => $controllerClass::ADMIN_RESOURCE,
            'decisions' => ['export_allowed' => true, 'import_only' => false, 'explicit_denied' => false, 'anonymous' => false],
        ];
    }
    $report['acl_roles_persisted'] = false;
    $report['acl_resource_count'] = count($acl->getResources());
    $exitCode = $failedCases ? 1 : 0;
} catch (Throwable $exception) {
    $report['failure'] = [
        'stage' => $stage,
        'class' => get_class($exception),
        'message' => str_starts_with($exception->getMessage(), 'LCVX:') ? $exception->getMessage() : 'Native component raised an exception; see source location.',
        'source' => basename($exception->getFile()) . ':' . $exception->getLine(),
    ];
} finally {
    if ($connection !== null && $connection->getTransactionLevel() > 0) {
        $connection->rollBack();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    lcvxAdminRemoveOwnedTree($temporary);
    $report['cleanup'] = [
        'database_transaction_closed' => $connection === null || $connection->getTransactionLevel() === 0,
        'owned_runtime_removed' => !file_exists($temporary),
        'persistent_users_roles_or_catalog_writes' => false,
    ];
    if (!$report['cleanup']['database_transaction_closed'] || !$report['cleanup']['owned_runtime_removed']) {
        $exitCode = 1;
    }
    $report['status'] = $exitCode === 0 ? 'PASS' : 'FAIL';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
exit($exitCode);
