<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

use JsonException;
use RuntimeException;

class StructuralSchemaIndex
{
    private const FORMAT_VERSION = 1;

    public function __construct(
        private string $indexPath,
        private string $projectRoot,
    ) {
        $this->indexPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $indexPath);
        $this->projectRoot = rtrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $projectRoot),
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * @param list<string> $migrationFiles
     */
    public function analyze(
        array $migrationFiles,
        MigrationParser $parser,
        bool $persistIndex = true
    ): DatabaseSchema
    {
        $cachedIndex = $this->readIndex();
        $cachedMigrations = [];
        foreach ($cachedIndex['migrations'] ?? [] as $migration) {
            $cachedMigrations[$migration['path']] = $migration;
        }

        $migrationEntries = [];
        $operations = [];

        foreach ($migrationFiles as $file) {
            $path = $this->migrationIdentity($file);
            $fingerprint = hash_file('sha256', $file);
            if ($fingerprint === false) {
                throw new RuntimeException("Could not calculate migration fingerprint: {$file}");
            }

            $cached = $cachedMigrations[$path] ?? null;
            if ($cached !== null && hash_equals($cached['sha256'], $fingerprint)) {
                $migrationOperations = $cached['operations'];
                foreach ($migrationOperations as &$operation) {
                    $operation['file'] = $file;
                }
                unset($operation);
            } else {
                $migrationOperations = $parser->parse($file);
            }

            $verifiedFingerprint = hash_file('sha256', $file);
            if ($verifiedFingerprint === false) {
                throw new RuntimeException("Could not verify migration fingerprint: {$file}");
            }
            if (!hash_equals($fingerprint, $verifiedFingerprint)) {
                throw new RuntimeException("Migration changed while the structural index was being built: {$file}");
            }

            array_push($operations, ...$migrationOperations);

            foreach ($migrationOperations as &$operation) {
                $operation['file'] = $path;
            }
            unset($operation);

            $migrationEntries[] = [
                'path' => $path,
                'sha256' => $fingerprint,
                'operations' => $migrationOperations,
            ];
        }

        $schema = (new SchemaBuilder())->build($operations, $migrationFiles);
        if ($persistIndex) {
            $this->writeIndex($migrationEntries, $schema);
        }

        return $schema;
    }

    private function readIndex(): ?array
    {
        if (!is_file($this->indexPath)) {
            return null;
        }

        $contents = @file_get_contents($this->indexPath);
        if ($contents === false) {
            return null;
        }

        try {
            $index = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$this->isValidIndex($index)) {
            return null;
        }

        return $index;
    }

    private function isValidIndex(mixed $index): bool
    {
        if (
            !is_array($index)
            || ($index['format_version'] ?? null) !== self::FORMAT_VERSION
            || !isset($index['migrations'], $index['schema'], $index['index_sha256'])
            || !is_array($index['migrations'])
            || !array_is_list($index['migrations'])
            || !is_array($index['schema'])
            || !is_array($index['schema']['tables'] ?? null)
            || !is_array($index['schema']['relations'] ?? null)
            || !array_is_list($index['schema']['relations'])
            || !is_array($index['schema']['migration_files'] ?? null)
            || !array_is_list($index['schema']['migration_files'])
            || !is_string($index['index_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/', $index['index_sha256'])
        ) {
            return false;
        }

        $paths = [];
        foreach ($index['migrations'] as $migration) {
            if (
                !is_array($migration)
                || !is_string($migration['path'] ?? null)
                || $migration['path'] === ''
                || isset($paths[$migration['path']])
                || !is_string($migration['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/', $migration['sha256'])
                || !is_array($migration['operations'] ?? null)
                || !array_is_list($migration['operations'])
            ) {
                return false;
            }

            foreach ($migration['operations'] as $operation) {
                if (
                    !is_array($operation)
                    || !is_string($operation['operation'] ?? null)
                    || !is_string($operation['table'] ?? null)
                    || !is_string($operation['file'] ?? null)
                    || !is_int($operation['line'] ?? null)
                    || !is_array($operation['statements'] ?? null)
                ) {
                    return false;
                }
            }

            $paths[$migration['path']] = true;
        }

        try {
            $expectedHash = $this->indexFingerprint(
                $index['format_version'],
                $index['migrations'],
                $index['schema']
            );
        } catch (JsonException) {
            return false;
        }

        return hash_equals($index['index_sha256'], $expectedHash);
    }

    /**
     * @param list<array<string, mixed>> $migrationEntries
     */
    private function writeIndex(array $migrationEntries, DatabaseSchema $schema): void
    {
        $schemaData = $schema->toArray();
        $schemaData['migration_files'] = array_map(
            fn (string $file): string => $this->migrationIdentity($file),
            $schema->migrationFiles
        );

        $index = [
            'format_version' => self::FORMAT_VERSION,
            'migrations' => $migrationEntries,
            'schema' => [
                'tables' => $schemaData['tables'],
                'relations' => $schemaData['relations'],
                'migration_files' => $schemaData['migration_files'],
            ],
        ];
        $index['index_sha256'] = $this->indexFingerprint(
            $index['format_version'],
            $index['migrations'],
            $index['schema']
        );

        try {
            $json = json_encode(
                $index,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the structural migration index.', 0, $exception);
        }

        $directory = dirname($this->indexPath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create structural index directory: {$directory}");
        }

        $temporaryPath = $this->indexPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporaryPath, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Could not create temporary structural index: {$temporaryPath}");
        }

        try {
            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new RuntimeException("Could not lock temporary structural index: {$temporaryPath}");
                }

                $length = strlen($json);
                $written = 0;
                while ($written < $length) {
                    $bytes = fwrite($handle, substr($json, $written));
                    if ($bytes === false || $bytes === 0) {
                        throw new RuntimeException("Could not completely write structural index: {$temporaryPath}");
                    }
                    $written += $bytes;
                }

                if (!fflush($handle)) {
                    throw new RuntimeException("Could not flush structural index: {$temporaryPath}");
                }
                if (function_exists('fsync') && !fsync($handle)) {
                    throw new RuntimeException("Could not synchronize structural index: {$temporaryPath}");
                }
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }

            if (!@rename($temporaryPath, $this->indexPath)) {
                throw new RuntimeException("Could not atomically replace structural index: {$this->indexPath}");
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $migrations
     * @param array<string, mixed> $schema
     */
    private function indexFingerprint(int $formatVersion, array $migrations, array $schema): string
    {
        return hash('sha256', json_encode(
            [
                'format_version' => $formatVersion,
                'migrations' => $migrations,
                'schema' => $schema,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    private function migrationIdentity(string $file): string
    {
        $absoluteFile = realpath($file) ?: $file;
        $absoluteRoot = realpath($this->projectRoot) ?: $this->projectRoot;
        $rootPrefix = rtrim($absoluteRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (
            str_starts_with(
                strtolower($absoluteFile),
                strtolower($rootPrefix)
            )
        ) {
            return str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($absoluteFile, strlen($rootPrefix))
            );
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $absoluteFile);
    }
}
