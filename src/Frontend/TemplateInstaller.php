<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Frontend;

use Closure;
use RuntimeException;
use Throwable;

class TemplateInstaller
{
    private const MANIFEST = '.lrg/frontend-manifest.json';
    private const JOURNAL = '.lrg/frontend-install-journal.json';
    private const STAGE = '.lrg/frontend-install-stage.tmp';
    private const COMPATIBILITY = [
        'php' => '>=8.1 <9',
        'laravel' => '>=10 <14',
        'react' => '>=18 <20',
        'vite' => '>=5 <8',
    ];

    public function __construct(private string $backendRoot, private ?Closure $beforeWrite = null)
    {
    }

    /**
     * @return array{root: string, entry: string, changes: array<string, string>, original: string, installed: bool, recovered: bool}
     */
    public function prepare(string $path): array
    {
        $root = $this->resolveRoot($path);
        $recovered = $this->recover($root);
        if (is_file($this->safePath($root, '.lrg/frontend-connection.json'))) {
            $connected = (new FrontendConnector($this->backendRoot))->inspectFrontend($path);
            return ['root' => $root, 'entry' => $connected['entry'], 'changes' => [], 'original' => '', 'installed' => true, 'recovered' => $recovered];
        }
        $entry = $this->entryPath($root);
        $this->checkProject($root, $entry);
        $changes = $this->templateFiles($entry);
        $manifest = $this->manifest($changes);
        $changes[self::MANIFEST] = $manifest;
        $manifestPath = $this->safePath($root, self::MANIFEST);

        if (is_file($manifestPath)) {
            if ($this->read($manifestPath) !== $manifest) {
                throw new RuntimeException('The frontend manifest is not the expected unchanged LRG installation.');
            }

            foreach ($changes as $relative => $contents) {
                $target = $this->safePath($root, $relative);
                if (!is_file($target) || $this->read($target) !== $contents) {
                    throw new RuntimeException("Managed frontend file was modified or removed: {$relative}");
                }
            }

            return ['root' => $root, 'entry' => $entry, 'changes' => [], 'original' => '', 'installed' => true, 'recovered' => $recovered];
        }

        $entryFile = $this->safePath($root, $entry);
        if (!is_file($entryFile)) {
            throw new RuntimeException("Frontend entry point does not exist: {$entry}");
        }
        $original = $this->read($entryFile);
        if (!$this->isStarterEntry($original, pathinfo($entry, PATHINFO_EXTENSION))) {
            throw new RuntimeException("Frontend entry point is not a recognized unmodified Vite starter: {$entry}");
        }
        foreach ($changes as $relative => $_contents) {
            $target = $this->safePath($root, $relative);
            if ($relative !== $entry && $this->exists($target)) {
                throw new RuntimeException("Frontend file conflict: {$relative}");
            }
        }

        return ['root' => $root, 'entry' => $entry, 'changes' => $changes, 'original' => $original, 'installed' => false, 'recovered' => $recovered];
    }

    public function inspectProject(string $path, ?string $originalVite = null): array
    {
        $root = $this->resolveRoot($path);
        $entry = $this->entryPath($root);
        $this->checkProject($root, $entry, $originalVite);
        return ['root' => $root, 'entry' => $entry];
    }

    public function expectedInstallation(string $entry): array
    {
        $files = $this->templateFiles($entry);
        return ['files' => $files, 'manifest' => $this->manifest($files)];
    }

    public function frontendPath(string $root, string $relative): string
    {
        return $this->safePath($root, $relative);
    }

    public function resolveFrontend(string $path): string
    {
        return $this->resolveRoot($path);
    }

    /** @param array{root: string, entry: string, changes: array<string, string>, original: string, installed: bool, recovered: bool} $plan */
    public function install(array $plan): void
    {
        if ($plan['installed']) {
            return;
        }
        $root = $plan['root'];
        $this->assertRoot($root);
        $this->ensureDirectory($root, '.lrg');
        $journal = json_encode([
            'version' => 1,
            'entry' => $plan['entry'],
            'original' => base64_encode($plan['original']),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $journalPath = $this->safePath($root, self::JOURNAL);
        $handle = @fopen($journalPath, 'x+');
        if ($handle === false) {
            throw new RuntimeException('Cannot create the frontend installation journal; another installation may be in progress.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another frontend installation is using the journal.');
        }
        try {
            foreach ($plan['changes'] as $relative => $_contents) {
                $target = $this->safePath($root, $relative);
                if ($relative === $plan['entry']) {
                    if (!is_file($target) || $this->read($target) !== $plan['original']) {
                        throw new RuntimeException("Frontend file changed after planning: {$relative}");
                    }
                } elseif ($this->exists($target)) {
                    throw new RuntimeException("Frontend file appeared after planning: {$relative}");
                }
            }
            if ($this->exists($this->safePath($root, self::STAGE))) {
                throw new RuntimeException('A frontend staging file already exists.');
            }
        } catch (Throwable $exception) {
            if (!@unlink($journalPath)) {
                fclose($handle);
                throw new RuntimeException('Could not remove the unused frontend journal.', 0, $exception);
            }
            fclose($handle);
            throw $exception;
        }
        if (fwrite($handle, $journal) !== strlen($journal) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            fclose($handle);
            if (!@unlink($journalPath)) {
                throw new RuntimeException('Could not persist or remove the frontend installation journal.');
            }
            throw new RuntimeException('Could not persist the frontend installation journal.');
        }

        try {
            foreach ($plan['changes'] as $relative => $contents) {
                $this->ensureDirectory($root, dirname($relative));
                if ($this->beforeWrite !== null) {
                    ($this->beforeWrite)($relative);
                }
                $target = $this->safePath($root, $relative);
                if ($relative === $plan['entry']) {
                    if (!is_file($target) || $this->read($target) !== $plan['original']) {
                        throw new RuntimeException("Frontend file changed during installation: {$relative}");
                    }
                } elseif ($this->exists($target)) {
                    throw new RuntimeException("Frontend file appeared during installation: {$relative}");
                }
                $this->writePrepared($root, $target, $contents, $relative === $plan['entry'] ? $plan['original'] : null);
            }
            foreach ($plan['changes'] as $relative => $contents) {
                $target = $this->safePath($root, $relative);
                if (!is_file($target) || $this->read($target) !== $contents) {
                    throw new RuntimeException("Could not verify installed frontend file: {$relative}");
                }
            }
            if (!@unlink($this->safePath($root, self::JOURNAL))) {
                throw new RuntimeException('Could not remove the frontend installation journal.');
            }
            fclose($handle);
            $handle = null;
        } catch (Throwable $exception) {
            try {
                $this->recover($root, $handle);
            } catch (Throwable $rollback) {
                throw new RuntimeException("Installation failed; recovery also failed: {$rollback->getMessage()}", 0, $exception);
            } finally {
                if ($handle !== null) {
                    fclose($handle);
                }
            }
            throw $exception;
        }
    }

    private function resolveRoot(string $path): string
    {
        if (trim($path) === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Provide a valid frontend --path.');
        }
        $backend = realpath($this->backendRoot);
        if ($backend === false || !is_dir($backend)) {
            throw new RuntimeException('Cannot resolve the Laravel project root.');
        }
        $candidate = $this->absolutePath($backend, $path);
        $root = realpath($candidate);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException("Frontend directory does not exist: {$path}");
        }
        if (!$this->samePath($candidate, $root)) {
            throw new RuntimeException('Frontend path contains a symbolic link or junction.');
        }
        if (!$this->samePath(dirname($root), dirname($backend)) || $this->samePath($root, $backend)) {
            throw new RuntimeException('Frontend must be a sibling directory of the Laravel project.');
        }
        $this->assertRoot($root);
        return $root;
    }

    private function absolutePath(string $base, string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $absolute = preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{1,2})/', $path) === 1;
        $full = $absolute ? $path : $base . DIRECTORY_SEPARATOR . $path;
        $prefix = '';
        if (preg_match('/^[A-Za-z]:/', $full, $match)) {
            $prefix = $match[0];
            $full = substr($full, 2);
        }
        if (str_starts_with($full, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Network paths are not supported for frontend installation.');
        }
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $full) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new RuntimeException('Frontend path escapes its filesystem root.');
                }
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return $prefix . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function assertRoot(string $root): void
    {
        clearstatcache(true, $root);
        if (!is_dir($root) || is_link($root) || !$this->samePath((string) realpath($root), $root)) {
            throw new RuntimeException('Frontend root changed or is a link.');
        }
    }

    private function samePath(string $left, string $right): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private function safePath(string $root, string $relative): string
    {
        $this->assertRoot($root);
        if ($relative === '' || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
            throw new RuntimeException("Invalid relative frontend path: {$relative}");
        }
        $current = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, ':')) {
                throw new RuntimeException("Invalid relative frontend path: {$relative}");
            }
            $current .= DIRECTORY_SEPARATOR . $part;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if ($stat === false) {
                continue;
            }
            $real = realpath($current);
            if (is_link($current) || $real === false || !$this->samePath($current, $real)) {
                throw new RuntimeException("Frontend path contains a link or junction: {$relative}");
            }
            if (is_file($current) && ($stat['nlink'] ?? 0) !== 1) {
                throw new RuntimeException("Frontend file has unsafe hard links: {$relative}");
            }
        }
        return $current;
    }

    private function exists(string $path): bool
    {
        clearstatcache(true, $path);
        return @lstat($path) !== false;
    }

    private function read(string $path): string
    {
        clearstatcache(true, $path);
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read frontend file: {$path}");
        }
        return $contents;
    }

    private function json(string $path): array
    {
        $data = json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("Expected a JSON object in {$path}.");
        }
        return $data;
    }

    private function checkProject(string $root, string $entry, ?string $originalVite = null): void
    {
        $package = $this->json($this->safePath($root, 'package.json'));
        $declaredVersions = [];
        $required = ['react', 'react-dom', 'vite'];
        if (str_ends_with($entry, '.tsx')) {
            array_push($required, 'typescript', '@types/react', '@types/react-dom');
        }
        foreach ($required as $name) {
            $declared = $package['dependencies'][$name] ?? $package['devDependencies'][$name] ?? null;
            if (!is_string($declared) || trim($declared) === '') {
                throw new RuntimeException("Frontend must declare {$name} in package.json.");
            }
            $declaredVersions[$name] = $declared;
        }
        if (!is_dir($this->safePath($root, 'src'))) {
            throw new RuntimeException('Frontend must contain a src directory.');
        }
        $react = $this->installedVersion($root, 'react');
        $reactDom = $this->installedVersion($root, 'react-dom');
        $vite = $this->installedVersion($root, 'vite');
        if (!in_array($react[0], [18, 19], true) || $reactDom !== $react || !in_array($vite[0], [5, 6, 7], true)) {
            throw new RuntimeException('Installed React/React DOM or Vite versions are outside the supported template range.');
        }
        foreach (['react' => $react, 'react-dom' => $reactDom, 'vite' => $vite] as $name => $version) {
            if (!$this->matchesRange($version, $declaredVersions[$name])) {
                throw new RuntimeException("Installed {$name} does not match its declared version range.");
            }
            foreach (array_diff($required, ['react', 'react-dom', 'vite']) as $name) {
                if (!$this->matchesRange($this->installedVersion($root, $name), $declaredVersions[$name])) {
                    throw new RuntimeException("Installed {$name} does not match its declared version range.");
                }
            }
        }
        if (!is_file($this->safePath($root, 'node_modules/react-dom/client.js'))) {
            throw new RuntimeException('The installed React DOM does not provide react-dom/client.');
        }

        $configs = [];
        foreach (['vite.config.js', 'vite.config.mjs', 'vite.config.ts', 'vite.config.mts'] as $name) {
            if (is_file($this->safePath($root, $name))) {
                $configs[] = $name;
            }
        }
        if (count($configs) !== 1) {
            throw new RuntimeException('Expected exactly one supported Vite configuration file.');
        }
        $config = $originalVite ?? $this->read($this->safePath($root, $configs[0]));
        $compact = $this->compactJavascript($config);
        $plugin = null;
        foreach (['@vitejs/plugin-react', '@vitejs/plugin-react-swc'] as $candidate) {
            $importReact = "importreactfrom'{$candidate}'";
            $importVite = "import{defineConfig}from'vite'";
            $body = 'exportdefaultdefineConfig({plugins:[react()],})';
            $bodyWithoutComma = 'exportdefaultdefineConfig({plugins:[react()]})';
            if (in_array($compact, [
                $importReact . $importVite . $body,
                $importReact . $importVite . $bodyWithoutComma,
                $importVite . $importReact . $body,
                $importVite . $importReact . $bodyWithoutComma,
            ], true)) {
                $plugin = $candidate;
                break;
            }
        }
        if ($plugin === null) {
            throw new RuntimeException('Cannot verify the Vite React plugin in the existing configuration; custom configurations are not modified.');
        }
        $declaredPlugin = $package['dependencies'][$plugin] ?? $package['devDependencies'][$plugin] ?? null;
        if (!is_string($declaredPlugin) || $declaredPlugin === '') {
            throw new RuntimeException("Frontend must declare {$plugin}.");
        }
        $pluginVersion = $this->installedVersion($root, $plugin);
        if (!$this->matchesRange($pluginVersion, $declaredPlugin)) {
            throw new RuntimeException("Installed {$plugin} does not match its declared version range.");
        }
        $pluginPackage = $this->json($this->safePath($root, 'node_modules/' . $plugin . '/package.json'));
        $peerVite = $pluginPackage['peerDependencies']['vite'] ?? null;
        if (!is_string($peerVite) || !$this->matchesRange($vite, $peerVite)) {
            throw new RuntimeException("Installed {$plugin} does not declare compatibility with the installed Vite.");
        }
        $node = $this->nodeVersion();
        $enginePackages = ['vite', $plugin];
        if ($plugin === '@vitejs/plugin-react-swc') {
            $this->installedVersion($root, '@swc/core');
            $enginePackages[] = '@swc/core';
        }
        foreach ($enginePackages as $name) {
            $installed = $this->json($this->safePath($root, 'node_modules/' . $name . '/package.json'));
            $engines = $installed['engines']['node'] ?? null;
            if ($engines === null && $name === $plugin) {
                continue;
            }
            if (!is_string($engines) || !$this->matchesRange($node, $engines)) {
                throw new RuntimeException("The installed Node version is not compatible with {$name}.");
            }
        }
    }

    /** @return array{int, int, int} */
    private function nodeVersion(): array
    {
        $process = @proc_open(['node', '--version'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run Node to verify the frontend toolchain.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || !is_string($output) || preg_match('/^v(\d+)\.(\d+)\.(\d+)\s*$/', $output, $match) !== 1) {
            throw new RuntimeException('Cannot verify the installed Node version: ' . trim((string) $error));
        }
        return [(int) $match[1], (int) $match[2], (int) $match[3]];
    }

    /** @param array{int, int, int} $version */
    private function matchesRange(array $version, string $range): bool
    {
        $actual = implode('.', $version);
        foreach (explode('||', $range) as $alternative) {
            $tokens = preg_split('/\s+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens === false || $tokens === []) {
                throw new RuntimeException("Cannot verify npm version range: {$range}");
            }
            $matches = true;
            foreach ($tokens as $token) {
                if ($token === '*') {
                    continue;
                }
                if (preg_match('/^(\^|~|>=|<=|>|<|=)?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $token, $parts) !== 1) {
                    throw new RuntimeException("Cannot verify npm version range: {$range}");
                }
                $operator = $parts[1] ?: '';
                $major = (int) $parts[2];
                $minor = isset($parts[3]) ? (int) $parts[3] : 0;
                $patch = isset($parts[4]) ? (int) $parts[4] : 0;
                $minimum = "{$major}.{$minor}.{$patch}";
                if ($operator === '^' || $operator === '~' || ($operator === '' && !isset($parts[4]))) {
                    if ($operator === '^' && $major === 0) {
                        $upper = !isset($parts[3]) ? '1.0.0'
                            : ($minor !== 0 || !isset($parts[4]) ? '0.' . ($minor + 1) . '.0' : '0.0.' . ($patch + 1));
                    } elseif ($operator === '~' && isset($parts[3])) {
                        $upper = $major . '.' . ($minor + 1) . '.0';
                    } elseif ($operator === '' && isset($parts[3])) {
                        $upper = $major . '.' . ($minor + 1) . '.0';
                    } else {
                        $upper = ($major + 1) . '.0.0';
                    }
                    $matches = $matches && version_compare($actual, $minimum, '>=') && version_compare($actual, $upper, '<');
                } else {
                    $matches = $matches && version_compare($actual, $minimum, $operator === '' ? '==' : $operator);
                }
            }
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    /** @return array{int, int, int} */
    private function installedVersion(string $root, string $package): array
    {
        $data = $this->json($this->safePath($root, 'node_modules/' . $package . '/package.json'));
        $version = $data['version'] ?? null;
        if (($data['name'] ?? null) !== $package || !is_string($version)
            || preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $match) !== 1) {
            throw new RuntimeException("Cannot verify the installed version of {$package}.");
        }
        return [(int) $match[1], (int) $match[2], (int) $match[3]];
    }

    private function entryPath(string $root): string
    {
        $html = $this->read($this->safePath($root, 'index.html'));
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        if ($html === null || preg_match_all('/<script\b/i', $html) !== 1
            || preg_match_all('/<script\b([^>]*)><\/script\s*>/i', $html, $scripts) !== 1
            || preg_match_all('/\bid\s*=\s*["\']root["\']/i', $html) !== 1
            || preg_match('/\btype\s*=\s*["\']module["\']/i', $scripts[1][0]) !== 1
            || preg_match('/\bsrc\s*=\s*["\']\/(src\/[A-Za-z0-9_-]+\.(?:jsx|tsx))["\']/i', $scripts[1][0], $match) !== 1) {
            throw new RuntimeException('Cannot safely identify one React JSX/TSX entry point and root element in index.html.');
        }
        return $match[1];
    }

    private function compactJavascript(string $source, bool $removeComments = true): string
    {
        $result = preg_replace_callback(
            '~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|//[^\r\n]*|/\*.*?\*/|\s+|;~s',
            static function (array $match) use ($removeComments): string {
                $token = $match[0];
                if ($token[0] === '"' && !str_contains($token, "'")) {
                    return "'" . substr($token, 1, -1) . "'";
                }
                if ($token[0] === "'" || $token[0] === '"') {
                    return $token;
                }
                if (str_starts_with($token, '//') || str_starts_with($token, '/*')) {
                    return $removeComments ? '' : $token;
                }
                return '';
            },
            $source
        );
        if ($result === null) {
            throw new RuntimeException('Cannot inspect frontend JavaScript.');
        }
        return $result;
    }

    private function isStarterEntry(string $source, string $extension): bool
    {
        $app = $extension === 'tsx' ? './App.tsx' : './App.jsx';
        $nonNull = $extension === 'tsx' ? '!' : '';
        $compact = $this->compactJavascript($source, false);
        $current = "import{StrictMode}from'react'import{createRoot}from'react-dom/client'"
            . "import'./index.css'importAppfrom'{$app}'"
            . "createRoot(document.getElementById('root'){$nonNull}).render(<StrictMode><App/></StrictMode>,)";
        $legacy = "importReactfrom'react'importReactDOMfrom'react-dom/client'"
            . "importAppfrom'{$app}'import'./index.css'"
            . "ReactDOM.createRoot(document.getElementById('root'){$nonNull}).render(<React.StrictMode><App/></React.StrictMode>,)";
        return in_array($compact, [$current, $legacy], true)
            || $compact === str_replace(',)', ')', $current)
            || $compact === str_replace(',)', ')', $legacy);
    }

    /** @return array<string, string> */
    private function templateFiles(string $entry): array
    {
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'react';
        $files = [
            'src/lrg/App.jsx' => 'src/App.jsx',
            'src/lrg/contract.js' => 'src/contract.js',
            'src/lrg/demo.js' => 'src/demo.js',
            'src/lrg/form.js' => 'src/form.js',
            'src/lrg/data/errors.js' => 'src/data/errors.js',
            'src/lrg/data/memory.js' => 'src/data/memory.js',
            'src/lrg/styles.css' => 'src/styles.css',
            $entry => 'install/main.' . pathinfo($entry, PATHINFO_EXTENSION),
        ];
        if (str_ends_with($entry, '.tsx')) {
            $files['src/lrg/App.d.ts'] = 'install/App.d.ts';
        }
        $result = [];
        foreach ($files as $destination => $source) {
            $path = $template . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source);
            if (!is_file($path) || is_link($path)) {
                throw new RuntimeException("Packaged frontend template file is missing or unsafe: {$source}");
            }
            $result[$destination] = $this->read($path);
        }
        return $result;
    }

    /** @param array<string, string> $files */
    private function manifest(array $files): string
    {
        $managed = [];
        foreach ($files as $path => $contents) {
            $managed[] = ['path' => $path, 'sha256' => hash('sha256', $contents), 'role' => 'template'];
        }
        return json_encode([
            'contract' => 'lrg.frontend.installation',
            'version' => '1.0',
            'template' => [
                'id' => 'lrg-react',
                'version' => '1.0.0',
                'metadata_contract' => ['id' => 'lrg.frontend.metadata', 'version' => '1.0'],
                'compatibility' => self::COMPATIBILITY,
            ],
            'managed_files' => $managed,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function ensureDirectory(string $root, string $relative): void
    {
        if ($relative === '.') {
            return;
        }
        $parts = explode('/', str_replace('\\', '/', $relative));
        $path = '';
        foreach ($parts as $part) {
            $path = $path === '' ? $part : $path . '/' . $part;
            $absolute = $this->safePath($root, $path);
            if (!is_dir($absolute) && !@mkdir($absolute)) {
                throw new RuntimeException("Cannot create frontend directory: {$path}");
            }
            $this->safePath($root, $path);
        }
    }

    private function writePrepared(string $root, string $target, string $contents, ?string $expected): void
    {
        $stage = $this->safePath($root, self::STAGE);
        $handle = @fopen($stage, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot create frontend staging file.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Could not write frontend staging file.');
            }
        } finally {
            fclose($handle);
        }
        $this->safePath($root, self::STAGE);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($target, strlen($root) + 1));
        $this->safePath($root, $relative);
        if ($expected !== null) {
            if (!is_file($target) || $this->read($target) !== $expected) {
                throw new RuntimeException("Frontend entry point changed during installation: {$relative}");
            }
            if (!@rename($stage, $target)) {
                throw new RuntimeException("Could not replace frontend entry point: {$relative}");
            }
        } else {
            if (!@link($stage, $target)) {
                throw new RuntimeException("Could not exclusively create frontend file: {$relative}");
            }
            if (!@unlink($stage)) {
                throw new RuntimeException('Could not remove frontend staging file.');
            }
        }
    }

    /** @param resource|null $lockedJournal */
    private function recover(string $root, $lockedJournal = null): bool
    {
        $journalPath = $this->safePath($root, self::JOURNAL);
        if (!$this->exists($journalPath)) {
            if ($this->exists($this->safePath($root, self::STAGE))) {
                throw new RuntimeException('An unexplained frontend staging file exists; inspect it before proceeding.');
            }
            return false;
        }
        $lock = null;
        if ($lockedJournal === null) {
            $lock = @fopen($journalPath, 'r+');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                throw new RuntimeException('Another frontend installation is in progress.');
            }
        }
        try {
            $this->recoverLocked($root, $journalPath, $lockedJournal ?? $lock);
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
        return true;
    }

    /** @param resource $handle */
    private function recoverLocked(string $root, string $journalPath, $handle): void
    {
        rewind($handle);
        $journal = json_decode(stream_get_contents($handle), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($journal)) {
            throw new RuntimeException('Invalid frontend installation journal; manual inspection is required.');
        }
        if (array_keys($journal) !== ['version', 'entry', 'original'] || $journal['version'] !== 1
            || !is_string($journal['entry']) || !is_string($journal['original'])
            || $journal['entry'] !== $this->entryPath($root)) {
            throw new RuntimeException('Invalid frontend installation journal; manual inspection is required.');
        }
        $original = base64_decode($journal['original'], true);
        if ($original === false || !$this->isStarterEntry($original, pathinfo($journal['entry'], PATHINFO_EXTENSION))) {
            throw new RuntimeException('Frontend journal does not contain a recognized starter entry point.');
        }
        $changes = $this->templateFiles($journal['entry']);
        $changes[self::MANIFEST] = $this->manifest($changes);
        $stage = $root . DIRECTORY_SEPARATOR . '.lrg' . DIRECTORY_SEPARATOR . 'frontend-install-stage.tmp';
        $this->safePath($root, '.lrg');
        if ($this->exists($stage)) {
            clearstatcache(true, $stage);
            $stat = lstat($stage);
            if (is_link($stage) || !is_file($stage) || !$this->samePath((string) realpath($stage), $stage) || $stat === false) {
                throw new RuntimeException('Unsafe frontend staging file; manual inspection is required.');
            }
            if (($stat['nlink'] ?? 0) > 1) {
                $matches = 0;
                foreach (array_keys($changes) as $relative) {
                    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    $candidateStat = @lstat($candidate);
                    if ($candidateStat !== false && $candidateStat['ino'] === $stat['ino'] && $candidateStat['dev'] === $stat['dev']) {
                        $matches++;
                    }
                }
                if ($stat['nlink'] !== 2 || $matches !== 1) {
                    throw new RuntimeException('Unexpected hard links on frontend staging file.');
                }
            }
            if (!@unlink($stage)) {
                throw new RuntimeException('Could not remove interrupted frontend staging file.');
            }
        }
        foreach ($changes as $relative => $contents) {
            $target = $this->safePath($root, $relative);
            if (!$this->exists($target)) {
                if ($relative === $journal['entry']) {
                    throw new RuntimeException('The original frontend entry point is missing during recovery.');
                }
                continue;
            }
            $current = $this->read($target);
            if ($current !== $contents && !($relative === $journal['entry'] && $current === $original)) {
                throw new RuntimeException("Frontend file changed during interrupted installation: {$relative}");
            }
        }
        foreach (array_reverse($changes, true) as $relative => $contents) {
            $target = $this->safePath($root, $relative);
            if (!is_file($target) || $this->read($target) !== $contents) {
                continue;
            }
            if ($relative === $journal['entry']) {
                $this->writePrepared($root, $target, $original, $contents);
            } elseif (!@unlink($target)) {
                throw new RuntimeException("Could not remove incomplete frontend file: {$relative}");
            }
        }
        if (!@unlink($this->safePath($root, self::JOURNAL))) {
            throw new RuntimeException('Could not remove recovered frontend journal.');
        }
    }
}
