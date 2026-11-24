<?php

use Franciscovillaquiran\LaravelRelationGenerator\Frontend\TemplateInstaller;

require_once dirname(__DIR__) . '/src/Frontend/TemplateInstaller.php';

$workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-frontend-test-' . bin2hex(random_bytes(6));
$backend = $workspace . DIRECTORY_SEPARATOR . 'backend';
$frontend = $workspace . DIRECTORY_SEPARATOR . 'frontend';
mkdir($backend, 0777, true);
mkdir($frontend . DIRECTORY_SEPARATOR . 'src', 0777, true);

$write = static function (string $relative, string $contents) use ($frontend): void {
    $path = $frontend . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        throw new RuntimeException("Cannot create fixture: {$relative}");
    }
};
$starter = <<<'JS'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
JS;
$write('index.html', '<div id="root"></div><script type="module" src="/src/main.jsx"></script>');
$write('vite.config.js', "import { defineConfig } from 'vite';\nimport react from '@vitejs/plugin-react';\nexport default defineConfig({ plugins: [react()] });\n");
$package = json_encode(['dependencies' => ['react' => '^19', 'react-dom' => '^19'], 'devDependencies' => ['vite' => '^7', '@vitejs/plugin-react' => '^5']], JSON_THROW_ON_ERROR);
$write('package.json', $package);
$write('src/main.jsx', $starter);
$write('src/App.jsx', 'developer-owned starter file');
$write('src/custom.js', 'developer-owned custom file');
$write('.env', 'VITE_CUSTOM=preserved');
foreach (['react' => '19.3.1', 'react-dom' => '19.3.1', 'vite' => '7.3.8', '@vitejs/plugin-react' => '5.2.0'] as $name => $version) {
    $data = compact('name', 'version');
    if ($name === '@vitejs/plugin-react') {
        $data['peerDependencies'] = ['vite' => '^5.0.0 || ^6.0.0 || ^7.0.0'];
    }
    if ($name === '@vitejs/plugin-react' || $name === 'vite') {
        $data['engines'] = ['node' => '>=18.0.0'];
    }
    $write('node_modules/' . $name . '/package.json', json_encode($data, JSON_THROW_ON_ERROR));
}
$write('node_modules/react-dom/client.js', '');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$reject = static function (callable $action, string $message) use ($assert): void {
    try {
        $action();
    } catch (Throwable $error) {
        $assert(str_contains($error->getMessage(), $message), "Unexpected error: {$error->getMessage()}");
        return;
    }
    throw new RuntimeException("Expected error containing {$message}.");
};
$tests = [];
$test = static function (string $name, callable $action) use (&$tests): void {
    $tests[$name] = $action;
};

$test('rejects missing project, non-sibling paths and absent package', static function () use ($backend, $frontend, $reject): void {
    $installer = new TemplateInstaller($backend);
    $reject(fn () => $installer->prepare('../missing'), 'does not exist');
    $reject(fn () => $installer->prepare('..'), 'sibling');
    rename($frontend . DIRECTORY_SEPARATOR . 'package.json', $frontend . DIRECTORY_SEPARATOR . 'package.bak');
    try { $reject(fn () => $installer->prepare('../frontend'), 'Could not read'); }
    finally { rename($frontend . DIRECTORY_SEPARATOR . 'package.bak', $frontend . DIRECTORY_SEPARATOR . 'package.json'); }
});

$test('rejects missing React and customized entries before writing', static function () use ($backend, $frontend, $package, $starter, $write, $reject): void {
    $write('package.json', json_encode(['devDependencies' => ['vite' => '^7']], JSON_THROW_ON_ERROR));
    try { $reject(fn () => (new TemplateInstaller($backend))->prepare('../frontend'), 'declare react'); }
    finally { $write('package.json', $package); }
    $write('src/main.jsx', $starter . "\n// custom\n");
    try { $reject(fn () => (new TemplateInstaller($backend))->prepare('../frontend'), 'unmodified'); }
    finally { $write('src/main.jsx', $starter); }
});

$test('rejects incompatible versions and unsupported Vite configuration', static function () use ($backend, $write, $reject): void {
    $write('node_modules/react/package.json', '{"name":"react","version":"17.0.2"}');
    try { $reject(fn () => (new TemplateInstaller($backend))->prepare('../frontend'), 'outside the supported'); }
    finally { $write('node_modules/react/package.json', '{"name":"react","version":"19.3.1"}'); }
    $write('vite.config.js', "export default { plugins: [] };\n");
    try { $reject(fn () => (new TemplateInstaller($backend))->prepare('../frontend'), 'Cannot verify'); }
    finally { $write('vite.config.js', "import { defineConfig } from 'vite';\nimport react from '@vitejs/plugin-react';\nexport default defineConfig({ plugins: [react()] });\n"); }
});

$test('rejects unknown conflicts and hard-linked targets', static function () use ($backend, $frontend, $write, $reject): void {
    $write('src/lrg/App.jsx', 'user content');
    try { $reject(fn () => (new TemplateInstaller($backend))->prepare('../frontend'), 'conflict'); }
    finally { unlink($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'lrg' . DIRECTORY_SEPARATOR . 'App.jsx'); }
    $source = $frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'custom.js';
    $linked = $frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'lrg' . DIRECTORY_SEPARATOR . 'App.jsx';
    if (!link($source, $linked)) {
        throw new RuntimeException('Cannot create a hard-link fixture.');
    }
    try { $reject(fn () => (new TemplateInstaller($backend))->prepare('../frontend'), 'hard links'); }
    finally { unlink($linked); }
});

$test('recovers an interrupted installation using the journal', static function () use ($backend, $frontend, $starter, $write, $assert, $reject): void {
    $installer = new TemplateInstaller($backend);
    $plan = $installer->prepare('../frontend');
    $write('src/lrg/App.jsx', $plan['changes']['src/lrg/App.jsx']);
    $write('src/main.jsx', $plan['changes']['src/main.jsx']);
    $journal = json_encode(['version' => 1, 'entry' => 'src/main.jsx', 'original' => base64_encode($starter)], JSON_THROW_ON_ERROR);
    $write('.lrg/frontend-install-journal.json', $journal);
    $handle = fopen($frontend . DIRECTORY_SEPARATOR . '.lrg' . DIRECTORY_SEPARATOR . 'frontend-install-journal.json', 'r+');
    flock($handle, LOCK_EX | LOCK_NB);
    try { $reject(fn () => $installer->prepare('../frontend'), 'in progress'); }
    finally { fclose($handle); }
    $recovered = $installer->prepare('../frontend');
    $assert(!$recovered['installed'], 'Interrupted installation was not reset.');
    $assert(file_get_contents($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'main.jsx') === $starter, 'Interrupted entry was not restored.');
    $assert(!file_exists($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'lrg' . DIRECTORY_SEPARATOR . 'App.jsx'), 'Interrupted output was not removed.');
});

$test('rolls back after a partial installation', static function () use ($backend, $frontend, $starter, $assert, $reject): void {
    $writes = 0;
    $installer = new TemplateInstaller($backend, static function () use (&$writes): void {
        if (++$writes === 9) {
            throw new RuntimeException('injected failure');
        }
    });
    $plan = $installer->prepare('../frontend');
    $reject(fn () => $installer->install($plan), 'injected failure');
    $assert(file_get_contents($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'main.jsx') === $starter, 'Starter entry was not restored.');
    $assert(!file_exists($frontend . DIRECTORY_SEPARATOR . '.lrg' . DIRECTORY_SEPARATOR . 'frontend-manifest.json'), 'Manifest survived rollback.');
    $assert(!file_exists($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'lrg' . DIRECTORY_SEPARATOR . 'App.jsx'), 'Generated file survived rollback.');
});

$test('installs, preserves unrelated files and repeats without writes', static function () use ($backend, $frontend, $package, $assert, $write, $reject): void {
    $installer = new TemplateInstaller($backend);
    $plan = $installer->prepare('../frontend');
    $installer->install($plan);
    $manifestPath = $frontend . DIRECTORY_SEPARATOR . '.lrg' . DIRECTORY_SEPARATOR . 'frontend-manifest.json';
    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $assert($manifest['contract'] === 'lrg.frontend.installation', 'Incorrect manifest.');
    $assert(file_get_contents($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'custom.js') === 'developer-owned custom file', 'Custom file was modified.');
    $assert(file_get_contents($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.jsx') === 'developer-owned starter file', 'Existing App was modified.');
    $assert(file_get_contents($frontend . DIRECTORY_SEPARATOR . 'package.json') === $package, 'Package manifest was modified.');
    $assert(file_get_contents($frontend . DIRECTORY_SEPARATOR . '.env') === 'VITE_CUSTOM=preserved', 'Environment file was modified.');
    $write('src/lrg/custom.js', 'custom');
    $again = $installer->prepare('../frontend');
    $assert($again['installed'] && $again['changes'] === [], 'Second run was not idempotent.');
    $reject(fn () => $installer->install($plan), 'appeared after planning');
    $assert(is_file($manifestPath), 'A stale plan removed an existing installation.');
    $write('src/lrg/App.jsx', 'local edit');
    $reject(fn () => $installer->prepare('../frontend'), 'modified');
});

$failures = 0;
try {
    foreach ($tests as $name => $action) {
        try {
            $action();
            echo "PASS {$name}\n";
        } catch (Throwable $exception) {
            $failures++;
            fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
        }
    }
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($workspace);
}
echo count($tests) . " tests, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
