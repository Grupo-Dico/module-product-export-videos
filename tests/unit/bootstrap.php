<?php
declare(strict_types=1);

/*
 * Bootstrap de pruebas unitarias SIN Magento.
 * - Carga las herramientas aisladas de tests/tooling.
 * - Registra los namespaces PSR-4 declarados en el composer.json raíz del proyecto.
 * - Si el proyecto necesita dobles mínimos de Magento (Phrase, excepciones, __()),
 *   se declaran en tests/unit/stubs.php, que se carga si existe.
 */

$root = dirname(__DIR__, 2);

require $root . '/tests/tooling/vendor/autoload.php';

if (is_file(__DIR__ . '/stubs.php')) {
    require __DIR__ . '/stubs.php';
}

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$psr4 = $composer['autoload']['psr-4'] ?? [];

spl_autoload_register(static function (string $class) use ($root, $psr4): void {
    foreach ($psr4 as $prefix => $directories) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        foreach ((array) $directories as $directory) {
            $path = $root . '/' . rtrim($directory, '/') . '/' . $relative;
            if (is_file($path)) {
                require $path;
                return;
            }
        }
    }
});
