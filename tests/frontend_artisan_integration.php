<?php

use Symfony\Component\Process\Process;

if (!isset($argv[1], $argv[2]) || !is_file($argv[1]) || !is_dir($argv[2])) {
    throw new RuntimeException('Pass a Laravel autoloader and an installed React/Vite node_modules directory.');
}
require $argv[1];
$packageRoot = dirname(__DIR__);
$workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-frontend-artisan-' . bin2hex(random_bytes(6));
$backend = $workspace . DIRECTORY_SEPARATOR . 'backend';
$frontend = $workspace . DIRECTORY_SEPARATOR . 'frontend';
foreach ([$backend . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache', $backend . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs', $frontend . DIRECTORY_SEPARATOR . 'src'] as $directory) {
    if (!mkdir($directory, 0777, true)) {
        throw new RuntimeException("Cannot create fixture: {$directory}");
    }
}
$write = static function (string $path, string $contents): void {
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        throw new RuntimeException("Cannot write fixture: {$path}");
    }
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (array $command, string $cwd, string $input = ''): Process {
    $process = new Process($command, $cwd, null, $input, 120);
    $process->run();
    return $process;
};

try {
    $write($backend . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"test/frontend-backend"}');
    $write($backend . DIRECTORY_SEPARATOR . '.env', "APP_DEBUG=true\nAPP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n");
    $write($backend . DIRECTORY_SEPARATOR . 'artisan',
        "<?php\n\$loader = require " . var_export(realpath($argv[1]), true) . ";\n"
        . "\$loader->addPsr4('Franciscovillaquiran\\\\LaravelRelationGenerator\\\\', " . var_export($packageRoot . DIRECTORY_SEPARATOR . 'src', true) . ");\n"
        . "\$app = require __DIR__ . '/bootstrap/app.php';\n"
        . "exit(\$app->handleCommand(new \\Symfony\\Component\\Console\\Input\\ArgvInput()));\n"
    );
    $write($backend . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
        "<?php\nreturn \\Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))"
        . "->withProviders([\\Franciscovillaquiran\\LaravelRelationGenerator\\RelationGeneratorServiceProvider::class])"
        . "->withCommands()->withMiddleware()->withExceptions()->create();\n"
    );
    $write($frontend . DIRECTORY_SEPARATOR . 'package.json',
        '{"name":"frontend-fixture","private":true,"type":"module","scripts":{"build":"vite build"},'
        . '"dependencies":{"react":"^19.1.0","react-dom":"^19.1.0"},'
        . '"devDependencies":{"vite":"^7.0.0","@vitejs/plugin-react":"^5.0.0"}}'
    );
    $write($frontend . DIRECTORY_SEPARATOR . 'vite.config.js', "import { defineConfig } from 'vite';\nimport react from '@vitejs/plugin-react';\nexport default defineConfig({ plugins: [react()] });\n");
    $write($frontend . DIRECTORY_SEPARATOR . 'index.html', '<div id="root"></div><script type="module" src="/src/main.jsx"></script>');
    $write($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'main.jsx', <<<'JS'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
createRoot(document.getElementById('root')).render(<StrictMode><App /></StrictMode>,)
JS);
    $write($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.jsx', 'export default function App() { return null }');
    $write($frontend . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'index.css', '');

    $sourceModules = realpath($argv[2]);
    $modules = $frontend . DIRECTORY_SEPARATOR . 'node_modules';
    mkdir($modules);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceModules, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException('Integration dependencies must not contain links.');
        }
        $destination = $modules . substr($item->getPathname(), strlen($sourceModules));
        if ($item->isDir()) {
            mkdir($destination);
        } elseif (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException("Cannot copy dependency fixture: {$destination}");
        }
    }

    $artisan = $backend . DIRECTORY_SEPARATOR . 'artisan';
    $list = $run([PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', $artisan, 'list', '--raw'], $workspace);
    $log = $backend . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'laravel.log';
    $assert($list->isSuccessful() && str_contains($list->getOutput(), 'lrg:frontend:template') && str_contains($list->getOutput(), 'lrg:frontend:connect'), 'Provider did not register both frontend commands (exit ' . $list->getExitCode() . '): ' . $list->getOutput() . $list->getErrorOutput() . (is_file($log) ? file_get_contents($log) : ''));
    $cancel = $run([PHP_BINARY, $artisan, 'lrg:frontend:template', '--path=../frontend', '--no-interaction'], $workspace);
    $assert(!$cancel->isSuccessful() && !is_file($frontend . DIRECTORY_SEPARATOR . '.lrg' . DIRECTORY_SEPARATOR . 'frontend-manifest.json'), 'Noninteractive installation should cancel.');
    $install = $run([PHP_BINARY, $artisan, 'lrg:frontend:template', '--path=../frontend'], $workspace, "yes\n");
    $assert($install->isSuccessful(), "Artisan installation failed:\n" . $install->getOutput() . $install->getErrorOutput());
    $repeat = $run([PHP_BINARY, $artisan, 'lrg:frontend:template', '--path=../frontend', '--no-interaction'], $workspace);
    $assert($repeat->isSuccessful() && str_contains($repeat->getOutput(), 'already installed'), 'Repeat was not idempotent.');
    $npm = DIRECTORY_SEPARATOR === '\\' ? 'npm.cmd' : 'npm';
    $build = $run([$npm, 'run', 'build'], $frontend);
    $assert($build->isSuccessful(), "Installed frontend build failed:\n" . $build->getOutput() . $build->getErrorOutput());
    $assert(is_file($frontend . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'index.html'), 'Build output missing.');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $socketError);
    if ($socket === false) {
        throw new RuntimeException("Cannot allocate a test port: {$socketError}");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);
    $server = new Process(['node', $modules . DIRECTORY_SEPARATOR . 'vite' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'vite.js', '--host', '127.0.0.1', '--port', (string) $port, '--strictPort'], $frontend);
    $server->start();
    try {
        $response = false;
        $context = stream_context_create(['http' => ['timeout' => 1]]);
        for ($attempt = 0; $attempt < 50 && $server->isRunning(); $attempt++) {
            $response = @file_get_contents("http://127.0.0.1:{$port}/src/lrg/App.jsx", false, $context);
            if ($response !== false) {
                break;
            }
            usleep(100000);
        }
        $assert(is_string($response) && str_contains($response, 'Dashboard'), "Installed frontend did not start:\n" . $server->getErrorOutput());
    } finally {
        $server->stop();
    }
    echo "PASS Artisan registration, safe confirmation, idempotency, Vite build and startup\n";

    if (($argv[3] ?? '') === '--typescript') {
        $typescript = $workspace . DIRECTORY_SEPARATOR . 'frontend-ts';
        mkdir($typescript . DIRECTORY_SEPARATOR . 'src', 0777, true);
        $write($typescript . DIRECTORY_SEPARATOR . 'package.json',
            '{"name":"frontend-ts-fixture","private":true,"type":"module","scripts":{"build":"tsc -b && vite build"},'
            . '"dependencies":{"react":"^18.3.1","react-dom":"^18.3.1"},'
            . '"devDependencies":{"vite":"^5.4.0","@vitejs/plugin-react":"^4.3.0","typescript":"^5.9.0",'
            . '"@types/react":"^18.3.0","@types/react-dom":"^18.3.0"}}'
        );
        $write($typescript . DIRECTORY_SEPARATOR . 'vite.config.ts', "import { defineConfig } from 'vite';\nimport react from '@vitejs/plugin-react';\nexport default defineConfig({ plugins: [react()] });\n");
        $write($typescript . DIRECTORY_SEPARATOR . 'index.html', '<div id="root"></div><script type="module" src="/src/main.tsx"></script>');
        $write($typescript . DIRECTORY_SEPARATOR . 'tsconfig.json', '{"compilerOptions":{"target":"ES2022","module":"ESNext","moduleResolution":"bundler","jsx":"react-jsx","strict":true,"noEmit":true,"skipLibCheck":true,"lib":["ES2022","DOM"]},"include":["src"]}');
        $write($typescript . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'main.tsx', <<<'TS'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
createRoot(document.getElementById('root')!).render(<StrictMode><App /></StrictMode>,)
TS);
        $write($typescript . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.tsx', 'export default function App() { return null }');
        $write($typescript . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'index.css', '');
        echo "Installing React/Vite/TypeScript dependencies in the isolated test fixture.\n";
        $dependencies = $run([$npm, 'install', '--no-audit', '--no-fund'], $typescript);
        $assert($dependencies->isSuccessful(), "Fixture dependency installation failed:\n" . $dependencies->getOutput() . $dependencies->getErrorOutput());
        $installTs = $run([PHP_BINARY, $artisan, 'lrg:frontend:template', '--path=../frontend-ts'], $workspace, "yes\n");
        $assert($installTs->isSuccessful(), "TypeScript installation failed:\n" . $installTs->getOutput() . $installTs->getErrorOutput());
        $buildTs = $run([$npm, 'run', 'build'], $typescript);
        $assert($buildTs->isSuccessful(), "TypeScript build failed:\n" . $buildTs->getOutput() . $buildTs->getErrorOutput());
        echo "PASS TypeScript template with React 18 and Vite 5\n";
        $tsPackage = json_decode(file_get_contents($typescript . DIRECTORY_SEPARATOR . 'package.json'), true, 512, JSON_THROW_ON_ERROR);
        $tsPackage['devDependencies']['vite'] = '^6.0.0';
        $write($typescript . DIRECTORY_SEPARATOR . 'package.json', json_encode($tsPackage, JSON_THROW_ON_ERROR));
        echo "Updating Vite in the isolated test fixture for a second compatibility check.\n";
        $dependencies = $run([$npm, 'install', '--no-audit', '--no-fund'], $typescript);
        $assert($dependencies->isSuccessful(), "Vite 6 fixture installation failed:\n" . $dependencies->getOutput() . $dependencies->getErrorOutput());
        $repeatTs = $run([PHP_BINARY, $artisan, 'lrg:frontend:template', '--path=../frontend-ts', '--no-interaction'], $workspace);
        $assert($repeatTs->isSuccessful(), "Vite 6 compatibility check failed:\n" . $repeatTs->getOutput() . $repeatTs->getErrorOutput());
        $buildTs = $run([$npm, 'run', 'build'], $typescript);
        $assert($buildTs->isSuccessful(), "Vite 6 TypeScript build failed:\n" . $buildTs->getOutput() . $buildTs->getErrorOutput());
        echo "PASS TypeScript template with React 18 and Vite 6\n";
        unset($tsPackage['devDependencies']['@vitejs/plugin-react']);
        $tsPackage['devDependencies']['@vitejs/plugin-react-swc'] = '^3.10.0';
        $write($typescript . DIRECTORY_SEPARATOR . 'package.json', json_encode($tsPackage, JSON_THROW_ON_ERROR));
        $write($typescript . DIRECTORY_SEPARATOR . 'vite.config.ts', "import { defineConfig } from 'vite';\nimport react from '@vitejs/plugin-react-swc';\nexport default defineConfig({ plugins: [react()] });\n");
        echo "Installing the SWC React plugin in the isolated test fixture.\n";
        $dependencies = $run([$npm, 'install', '--no-audit', '--no-fund'], $typescript);
        $assert($dependencies->isSuccessful(), "SWC fixture installation failed:\n" . $dependencies->getOutput() . $dependencies->getErrorOutput());
        $repeatSwc = $run([PHP_BINARY, $artisan, 'lrg:frontend:template', '--path=../frontend-ts', '--no-interaction'], $workspace);
        $assert($repeatSwc->isSuccessful(), "SWC compatibility check failed:\n" . $repeatSwc->getOutput() . $repeatSwc->getErrorOutput());
        $buildSwc = $run([$npm, 'run', 'build'], $typescript);
        $assert($buildSwc->isSuccessful(), "SWC TypeScript build failed:\n" . $buildSwc->getOutput() . $buildSwc->getErrorOutput());
        echo "PASS TypeScript template with the SWC React plugin\n";
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
