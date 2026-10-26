<?php
/** Exact candidate ownership, inventory and hash verification for the integration runner. */
declare(strict_types=1);

/** Resolve the destination without bootstrapping Magento or creating resources. */
function lcvxIntegrationRoot(): string
{
    $root = getenv('LCVX_MAGENTO_ROOT');
    if (!is_string($root) || preg_match('#\A/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z#', $root) !== 1
        || array_intersect(['.', '..'], explode('/', $root)) !== []
        || realpath($root) !== $root || !is_dir($root)
    ) {
        throw new RuntimeException('LCVX_MAGENTO_ROOT must identify a physical normalized Magento directory.');
    }
    foreach (['app/bootstrap.php', 'app/etc/env.php', 'app/etc/config.php'] as $file) {
        if (!is_file($root . '/' . $file) || !is_readable($root . '/' . $file)) {
            throw new RuntimeException('The configured Magento root lacks a required readable installation file.');
        }
    }
    if (realpath($root . '/var') !== $root . '/var' || !is_dir($root . '/var')) {
        throw new RuntimeException('The configured Magento var directory must exist without redirection.');
    }
    return $root;
}

if (defined('LCVX_RUNNER_SUPPORT_LIBRARY_ONLY')) {
    return;
}

$action = $argv[1] ?? '';
$root = lcvxIntegrationRoot();
$base = $root . '/var/lcvx-product-video-export/candidates';
$path = $argv[2] ?? '';
$token = $argv[3] ?? '';
$result = ['action' => $action, 'result' => 'FAIL'];
$exitCode = 1;
try {
    if ($action === 'capacity') {
        $memory = [];
        foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^(MemTotal|MemAvailable|SwapFree):\s+(\d+) kB$/', $line, $matches)) {
                $memory[$matches[1] . '_bytes'] = (int)$matches[2] * 1024;
            }
        }
        $result += [
            'php' => PHP_VERSION,
            'disk_available_bytes' => disk_free_space($root),
            'disk_total_bytes' => disk_total_space($root),
            'memory' => $memory,
            'php_memory_limit' => ini_get('memory_limit'),
            'php_max_execution_time' => ini_get('max_execution_time'),
            'process_limits' => function_exists('posix_getrlimit') ? posix_getrlimit() : null,
        ];
    } else {
        foreach ([$root . '/var/lcvx-product-video-export', $base] as $parent) {
            if (is_link($parent) || (file_exists($parent) && (!is_dir($parent) || realpath($parent) !== $parent))) {
                throw new RuntimeException('Candidate parent must be a physical directory; preserved.');
            }
        }
        if (!preg_match('#\A' . preg_quote($base, '#') . '/INT-\d{8}T\d{6}Z-[a-f0-9]{12}\z#', $path)
            || !preg_match('/\A[a-f0-9]{64}\z/', $token)
        ) {
            throw new RuntimeException('Invalid candidate identity.');
        }
        if ($action === 'create') {
            if (!is_dir($base) && !mkdir($base, 0770, true)) {
                throw new RuntimeException('Could not create candidate parent.');
            }
            if (is_link($base) || file_exists($path) || is_link($path) || !mkdir($path, 0700)) {
                throw new RuntimeException('Candidate must be a new owned directory.');
            }
            if (file_put_contents($path . '/.owner', $token, LOCK_EX) !== strlen($token)) {
                throw new RuntimeException('Could not persist candidate ownership.');
            }
        } else {
            if (!file_exists($path) && !is_link($path) && $action === 'cleanup') {
                $result['candidate_removed'] = true;
                $result['already_absent'] = true;
            } else {
                if (is_link($path) || !is_dir($path) || is_link($path . '/.owner')
                    || !hash_equals($token, (string)file_get_contents($path . '/.owner'))
                ) {
                    throw new RuntimeException('Candidate ownership mismatch; preserved.');
                }
                if ($action === 'verify') {
                    if (is_link($path . '/manifest.json')) {
                        throw new RuntimeException('Manifest must not be a symlink.');
                    }
                    $manifest = json_decode((string)file_get_contents($path . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                    $expected = ['.owner' => true, 'manifest.json' => true];
                    foreach ($manifest['files'] as $file) {
                        $relative = $file['snapshot'];
                        if (!preg_match('#\A(?:(?:module|tests|probes)/[A-Za-z0-9_./-]+|project/composer\.json)\z#', $relative)
                            || str_contains($relative, '..') || str_contains($relative, '/._')
                        ) {
                            throw new RuntimeException('Invalid manifest path.');
                        }
                        $expected[$relative] = true;
                        $item = $path . '/' . $relative;
                        if (is_link($item) || !is_file($item)
                            || hash_file('sha256', $item) !== $file['sha256']
                            || filesize($item) !== $file['bytes']
                        ) {
                            throw new RuntimeException('Snapshot hash mismatch.');
                        }
                    }
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($iterator as $item) {
                        $relative = substr($item->getPathname(), strlen($path) + 1);
                        if ($item->isLink() || !$item->isFile() || !isset($expected[$relative])) {
                            throw new RuntimeException('Unexpected snapshot entry.');
                        }
                    }
                    $result['files_verified'] = count($manifest['files']);
                    $result['manifest_sha256'] = hash_file('sha256', $path . '/manifest.json');
                } elseif ($action === 'cleanup') {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($iterator as $item) {
                        if ($item->getPathname() === $path . '/.owner') {
                            continue;
                        }
                        $ok = $item->isDir() && !$item->isLink()
                            ? rmdir($item->getPathname()) : unlink($item->getPathname());
                        if (!$ok) {
                            throw new RuntimeException('Could not remove owned candidate entry.');
                        }
                    }
                    if (!unlink($path . '/.owner') || !rmdir($path)) {
                        throw new RuntimeException('Could not remove owned candidate.');
                    }
                    $result['candidate_removed'] = !file_exists($path);
                } else {
                    throw new RuntimeException('Unknown runner action.');
                }
            }
        }
    }
    $result['result'] = 'PASS';
    $exitCode = 0;
} catch (Throwable $failure) {
    $result['error_class'] = get_class($failure);
    $result['message'] = $failure->getMessage();
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($exitCode);
