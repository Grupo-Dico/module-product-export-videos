<?php
/** Read only environment inventory. No credentials or customer rows leave the process. */
declare(strict_types=1);
define('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY', true);
require_once (getenv('LCVX_RUNNER_SUPPORT') ?: __DIR__ . '/../../tests/integration/runner-support.php');
$root = lcvxIntegrationRoot();
$config = require $root . '/app/etc/env.php';
$db = $config['db']['connection']['default'];
$pdo = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'], $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET TRANSACTION READ ONLY');
$pdo->beginTransaction();
$prefix = $config['db']['table_prefix'] ?? '';
$prefix = str_replace('`', '``', $prefix);
$inventory = ['php' => PHP_VERSION, 'database' => $pdo->query('SELECT VERSION()')->fetchColumn()];
$modules = require $root . '/app/etc/config.php';
foreach (['Magento_CatalogImportExport', 'Magento_ProductVideo', 'Bss_CustomProductAttributeExport', 'LeanCommerce_ProductVideoExport'] as $module) {
    $inventory['modules'][$module] = $modules['modules'][$module] ?? null;
}
$inventory['catalog_products'] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'catalog_product_entity`')->fetchColumn();
$inventory['fixture_prefix_existing'] = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}catalog_product_entity` WHERE sku LIKE 'LCVX-%'")->fetchColumn();
$inventory['queue_status_counts'] = $pdo->query("SELECT q.name, s.status, COUNT(*) AS messages FROM `{$prefix}queue_message_status` s JOIN `{$prefix}queue` q ON q.id=s.queue_id GROUP BY q.name,s.status")->fetchAll(PDO::FETCH_ASSOC);
$inventory['queue_transport'] = $config['queue']['default_connection'] ?? (isset($config['queue']['amqp']) ? 'amqp' : 'db');
$inventory['stores'] = $pdo->query("SELECT store_id,code,is_active FROM `{$prefix}store` ORDER BY store_id")->fetchAll(PDO::FETCH_ASSOC);
$inventory['export_source_sha256'] = hash_file('sha256',$root.'/vendor/magento/module-catalog-import-export/Model/Export/Product.php');
$inventory['bss_source_sha256'] = hash_file('sha256',$root.'/app/code/Bss/CustomProductAttributeExport/Model/Rewrite/CatalogImportExport/Export/Product.php');
$inventory['csv_driver_sha256'] = hash_file('sha256',$root.'/vendor/magento/framework/Filesystem/Driver/File.php');
$inventory['captured_utc'] = gmdate('c');
$pdo->rollBack();
echo json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
