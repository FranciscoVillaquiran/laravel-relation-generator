<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Frontend;

use Closure;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\FileChangeTransaction;
use RuntimeException;
use Symfony\Component\Process\Process;

class FrontendConnector
{
    private const STATE = '.lrg/frontend-connection.json';
    private const IMPORT = "import __lrgProxy from './.lrg/vite-proxy.mjs';\n";
    private const INSERT = "\n...__lrgProxy, /* LRG_PROXY */";
    private const GENERATED = [
        'src/generated/lrg-schema.json',
        'src/generated/lrg-http.js',
        'src/lrg-connected.jsx',
        'src/lrg-connected.d.ts',
        '.lrg/vite-proxy.mjs',
    ];

    public function __construct(private string $backendRoot, private ?Closure $beforeWrite = null)
    {
    }

    /** Inspect ownership and compatibility without modifying the frontend. */
    public function inspectFrontend(string $path): array
    {
        $installer = new TemplateInstaller($this->backendRoot);
        $root = $installer->resolveFrontend($path);
        if (file_exists($installer->frontendPath($root, '.lrg/frontend-install-journal.json'))) {
            throw new RuntimeException('An unfinished template installation must be recovered before connecting.');
        }
        $configs = [];
        foreach (['vite.config.js', 'vite.config.mjs', 'vite.config.ts', 'vite.config.mts'] as $name) {
            if (is_file($installer->frontendPath($root, $name))) {
                $configs[] = $name;
            }
        }
        if (count($configs) !== 1) {
            throw new RuntimeException('Expected exactly one supported Vite configuration file.');
        }
        $vite = $configs[0];
        $viteContent = $this->read($installer->frontendPath($root, $vite));
        $statePath = $installer->frontendPath($root, self::STATE);
        $state = null;
        if (file_exists($statePath)) {
            $state = json_decode($this->read($statePath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state) || array_keys($state) !== ['contract', 'version', 'entry', 'vite', 'files']
                || $state['contract'] !== 'lrg.frontend.connection' || $state['version'] !== '1.0'
                || !is_string($state['entry']) || $state['vite'] !== $vite || !is_array($state['files'])) {
                throw new RuntimeException('The frontend connection manifest is invalid.');
            }
            if (!str_starts_with($viteContent, self::IMPORT) || substr_count($viteContent, self::INSERT) !== 1) {
                throw new RuntimeException('The managed Vite integration was modified.');
            }
            $originalVite = str_replace(self::INSERT, '', substr($viteContent, strlen(self::IMPORT)));
            if ($this->integrateVite($originalVite) !== $viteContent) {
                throw new RuntimeException('Cannot safely reconstruct the original Vite configuration.');
            }
        } else {
            $originalVite = $viteContent;
        }
        $project = $installer->inspectProject($path, $originalVite);
        $entry = $project['entry'];
        $expected = $installer->expectedInstallation($entry);
        if ($this->read($installer->frontendPath($root, '.lrg/frontend-manifest.json')) !== $expected['manifest']) {
            throw new RuntimeException('The installed LRG template is absent, modified, or incompatible with this package version.');
        }
        foreach ($expected['files'] as $name => $contents) {
            if ($name === $entry && $state !== null) {
                $contents = $this->connectedEntry($contents);
            }
            if ($this->read($installer->frontendPath($root, $name)) !== $contents) {
                throw new RuntimeException("Managed template file was modified or is incompatible: {$name}");
            }
        }
        $managed = array_merge([$entry, $vite], self::GENERATED);
        if ($state !== null) {
            if ($state['entry'] !== $entry || array_keys($state['files']) !== $managed) {
                throw new RuntimeException('The frontend connection manifest lists unexpected files.');
            }
            foreach ($state['files'] as $name => $hash) {
                if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
                    || !hash_equals($hash, hash('sha256', $this->read($installer->frontendPath($root, $name))))) {
                    throw new RuntimeException("Managed connection file was modified: {$name}");
                }
            }
        } else {
            foreach (self::GENERATED as $name) {
                if (@lstat($installer->frontendPath($root, $name)) !== false) {
                    throw new RuntimeException("Frontend connection file conflict: {$name}");
                }
            }
        }
        return compact('root', 'entry', 'vite', 'originalVite', 'state', 'expected');
    }

    /** Build the full update before touching the frontend. */
    public function prepare(string $path, string $appUrl): array
    {
        $frontend = $this->inspectFrontend($path);
        $json = (new MetadataGenerator())->json($this->backendRoot);
        $metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($metadata['version'] ?? null) !== '2.0' || empty($metadata['entities'])) {
            throw new RuntimeException('No verified LRG CRUD entities are available for connection.');
        }
        $prefix = $metadata['api_prefix'] ?? null;
        if (!is_string($prefix) || ($prefix !== '' && (!preg_match('#^/(?:[A-Za-z0-9_~.-]+(?:/[A-Za-z0-9_~.-]+)*)$#D', $prefix)
            || array_intersect(explode('/', $prefix), ['.', '..']) || preg_match('#(?:^|/)api/api(?:/|$)#i', $prefix)))) {
            throw new RuntimeException('The API prefix cannot be safely proxied.');
        }
        [$origin, $basePath] = $this->parseUrl($appUrl, $prefix);
        $this->validateMetadata($frontend['root'], $json);
        $source = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'react' . DIRECTORY_SEPARATOR . 'connect' . DIRECTORY_SEPARATOR . 'http.js';
        $proxyPattern = $prefix === '' ? $this->unprefixedProxyPattern($metadata, $frontend['root'])
            : '^' . preg_quote($prefix, '~') . '(?=/|$)';
        $encode = static fn (string $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $proxy = 'export default { server: { proxy: { ' . $encode($proxyPattern)
            . ': { target: ' . $encode($origin) . ', changeOrigin: true'
            . ($basePath === '' ? '' : ', rewrite: (path) => ' . $encode($basePath) . ' + path')
            . " } } } };\n";
        $connected = <<<'JS'
import App from "./lrg/App.jsx";
import metadata from "./generated/lrg-schema.json";
import { createHttpSource } from "./generated/lrg-http.js";

const source = createHttpSource(metadata);
export default function ConnectedApp() {
  return <App metadata={metadata} source={source} />;
}
JS;
        $changes = [
            $frontend['entry'] => $this->connectedEntry($frontend['expected']['files'][$frontend['entry']]),
            $frontend['vite'] => $this->integrateVite($frontend['originalVite']),
            'src/generated/lrg-schema.json' => $json,
            'src/generated/lrg-http.js' => $this->read($source),
            'src/lrg-connected.jsx' => $connected . "\n",
            'src/lrg-connected.d.ts' => "import type { ComponentType } from 'react';\ndeclare const ConnectedApp: ComponentType;\nexport default ConnectedApp;\n",
            '.lrg/vite-proxy.mjs' => $proxy,
        ];
        $hashes = [];
        foreach ($changes as $name => $contents) {
            $hashes[$name] = hash('sha256', $contents);
        }
        $changes[self::STATE] = json_encode([
            'contract' => 'lrg.frontend.connection',
            'version' => '1.0',
            'entry' => $frontend['entry'],
            'vite' => $frontend['vite'],
            'files' => $hashes,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return ['frontend' => $frontend, 'changes' => $changes, 'prefix' => $prefix, 'origin' => $origin];
    }

    public function apply(array $plan): bool
    {
        $frontend = $plan['frontend'];
        $installer = new TemplateInstaller($this->backendRoot);
        $lockPath = $installer->frontendPath($frontend['root'], '.lrg/frontend-connect.lock');
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Cannot lock the frontend connection; another operation may be running.');
        }
        try {
            $current = $this->inspectFrontend($frontend['root']);
            if ($current !== $frontend) {
                throw new RuntimeException('Frontend changed after connection planning; retry the command.');
            }
            $absolute = [];
            $before = [];
            foreach ($plan['changes'] as $relative => $contents) {
                $target = $installer->frontendPath($frontend['root'], $relative);
                $before[$target] = is_file($target) ? $this->read($target) : null;
                if ($before[$target] !== $contents) $absolute[$target] = $contents;
            }
            if ($absolute === []) return false;
            $transaction = new FileChangeTransaction(function (string $target) use ($installer, $frontend, $before): void {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($target, strlen($frontend['root']) + 1));
                $installer->frontendPath($frontend['root'], $relative);
                $actual = is_file($target) ? $this->read($target) : null;
                if ($actual !== $before[$target]) throw new RuntimeException("Frontend file changed during connection: {$relative}");
                if ($this->beforeWrite !== null) ($this->beforeWrite)($relative);
            });
            $transaction->apply($absolute);
            try {
                foreach ($absolute as $target => $contents) {
                    if ($this->read($target) !== $contents) throw new RuntimeException("Cannot verify connected frontend file: {$target}");
                }
            } catch (\Throwable $exception) {
                try {
                    $transaction->rollback();
                } catch (\Throwable $rollback) {
                    throw new RuntimeException("Connection verification failed; rollback also failed: {$rollback->getMessage()}", 0, $exception);
                }
                throw $exception;
            }
            $transaction->commit();
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function connectedEntry(string $entry): string
    {
        $search = 'from "./lrg/App.jsx"';
        if (substr_count($entry, $search) !== 1) throw new RuntimeException('The template entry point cannot be safely connected.');
        return str_replace($search, 'from "./lrg-connected.jsx"', $entry);
    }

    private function integrateVite(string $config): string
    {
        if (str_contains($config, '__lrgProxy') || str_contains($config, 'LRG_PROXY') || str_contains($config, '/*')) {
            throw new RuntimeException('The Vite configuration already contains an unrecognized LRG integration.');
        }
        $pattern = '/^([ \t]*export\s+default\s+defineConfig\s*\(\s*\{)/m';
        if (preg_match_all($pattern, $config) !== 1) throw new RuntimeException('Cannot safely integrate this Vite configuration.');
        $result = preg_replace($pattern, '$1' . self::INSERT, $config, 1, $count);
        if ($result === null || $count !== 1) throw new RuntimeException('Cannot safely integrate this Vite configuration.');
        return self::IMPORT . $result;
    }

    private function parseUrl(string $url, string $prefix): array
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user'], $parts['pass'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Laravel app.url must be an absolute HTTP(S) URL without credentials, query, or fragment.');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (($path !== '' && (!preg_match('#^/(?:[A-Za-z0-9_~.-]+(?:/[A-Za-z0-9_~.-]+)*)$#D', $path)
                || array_intersect(explode('/', $path), ['.', '..'])))
            || ($path !== '' && $prefix !== '' && ($path === $prefix || str_ends_with($path, $prefix)))) {
            throw new RuntimeException('Laravel app.url has an unsupported path or already contains the API prefix.');
        }
        $origin = strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return [$origin, $path];
    }

    private function unprefixedProxyPattern(array $metadata, string $root): string
    {
        $endpoints = [];
        foreach ($metadata['entities'] as $entity) {
            foreach ($entity['operations'] as $operation => $value) {
                foreach ($operation === 'update' ? $value : [$value] as $endpoint) $endpoints[] = $endpoint;
            }
        }
        foreach ($metadata['relations'] as $relation) {
            if (isset($relation['association_operations']['replace'])) $endpoints[] = $relation['association_operations']['replace'];
        }
        $patterns = [];
        foreach ($endpoints as $endpoint) {
            $segments = explode('/', ltrim($endpoint['path'], '/'));
            if (in_array($segments[0], ['src', 'node_modules', 'index.html'], true)) {
                throw new RuntimeException('Unprefixed API routes conflict with Vite frontend paths.');
            }
            $parts = array_map(static fn (string $segment): string => str_starts_with($segment, '{')
                ? '[^/?]+' : preg_quote($segment, '#'), $segments);
            $patterns[] = '/' . implode('/', $parts);
        }
        $patterns = array_values(array_unique($patterns));
        sort($patterns, SORT_STRING);
        if ($patterns === []) throw new RuntimeException('No API routes are available for the development proxy.');
        $pattern = '^(?:' . implode('|', $patterns) . ')(?=\\?|$)';
        $public = (new TemplateInstaller($this->backendRoot))->frontendPath($root, 'public');
        if (is_dir($public)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if ($item->isLink()) throw new RuntimeException('Cannot verify Vite public assets containing links.');
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($public)));
                if ($item->isFile() && preg_match('#' . $pattern . '#', $relative)) {
                    throw new RuntimeException("Unprefixed API route conflicts with a public asset: {$relative}");
                }
            }
        }
        return $pattern;
    }

    private function validateMetadata(string $root, string $json): void
    {
        $validator = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'lrg' . DIRECTORY_SEPARATOR . 'contract.js';
        $script = <<<'JS'
import { readFile } from 'node:fs/promises';
const code = await readFile(process.argv[1]);
const { validateMetadata } = await import('data:text/javascript;base64,' + code.toString('base64'));
let input = '';
for await (const chunk of process.stdin) input += chunk;
validateMetadata(JSON.parse(input));
JS;
        $process = new Process(['node', '--input-type=module', '-e', $script, $validator], $root, null, $json, 30);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('The installed React template rejected generated metadata: ' . trim($process->getErrorOutput()));
        }
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) throw new RuntimeException("Cannot read required frontend file: {$path}");
        return $contents;
    }
}
