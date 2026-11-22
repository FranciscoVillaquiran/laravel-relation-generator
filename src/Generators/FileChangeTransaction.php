<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use Closure;
use RuntimeException;
use Throwable;

class FileChangeTransaction
{
    /**
     * @var array<string, array{exists: bool, backup: ?string, permissions: ?int}>
     */
    private array $snapshots = [];

    /**
     * @var list<string>
     */
    private array $createdDirectories = [];

    private bool $active = false;

    private bool $committed = false;

    public function __construct(private ?Closure $beforeWrite = null)
    {
    }

    /**
     * @param list<string> $paths
     */
    public function watch(array $paths): void
    {
        if ($this->active) {
            throw new RuntimeException('Cannot add watched files after a transaction has started.');
        }

        foreach (array_unique($paths) as $path) {
            if (isset($this->snapshots[$path])) {
                continue;
            }
            if (is_link($path)) {
                throw new RuntimeException("Refusing to modify symbolic link: {$path}");
            }

            $exists = file_exists($path);
            if ($exists && !is_file($path)) {
                throw new RuntimeException("Expected a regular file: {$path}");
            }
            $directory = dirname($path);
            while (!is_dir($directory)) {
                if (file_exists($directory) || dirname($directory) === $directory) {
                    throw new RuntimeException("Cannot prepare output directory for {$path}.");
                }
                $directory = dirname($directory);
            }
            if (!is_writable($directory)) {
                throw new RuntimeException("Output directory is not writable: {$directory}");
            }

            $backup = null;
            $permissions = null;
            if ($exists) {
                if (!is_readable($path) || !is_writable($path)) {
                    throw new RuntimeException("File is not readable and writable: {$path}");
                }

                $backup = tempnam(dirname($path), '.lrg-backup-');
                if ($backup === false || !copy($path, $backup)) {
                    if (is_string($backup) && is_file($backup)) {
                        @unlink($backup);
                    }
                    throw new RuntimeException("Could not preserve a recoverable copy of {$path}.");
                }
                $permissions = fileperms($path);
            }

            $this->snapshots[$path] = [
                'exists' => $exists,
                'backup' => $backup,
                'permissions' => $permissions === false ? null : $permissions,
            ];
        }
    }

    /**
     * @param array<string, string|null> $changes Absolute path to generated contents, or null to delete.
     */
    public function apply(array $changes): void
    {
        $this->watch(array_keys($changes));
        $this->active = true;

        try {
            foreach ($changes as $path => $contents) {
                $snapshot = $this->snapshots[$path];
                if ($contents === null) {
                    if (!$snapshot['exists']) {
                        continue;
                    }
                    if ($this->beforeWrite !== null) {
                        ($this->beforeWrite)($path);
                    }
                    if (!@unlink($path)) {
                        throw new RuntimeException("Could not remove obsolete generated file: {$path}");
                    }
                    continue;
                }
                if ($snapshot['exists'] && file_get_contents($path) === $contents) {
                    continue;
                }

                $this->ensureDirectory(dirname($path));
                if ($this->beforeWrite !== null) {
                    ($this->beforeWrite)($path);
                }
                $this->writeAtomically($path, $contents, $snapshot['permissions']);
            }
        } catch (Throwable $exception) {
            try {
                $this->rollback();
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    "Generation failed and rollback also failed: {$rollbackException->getMessage()}",
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }

    public function commit(): void
    {
        $backups = [];
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot['backup'] !== null && is_file($snapshot['backup'])) {
                $backups[] = $snapshot['backup'];
            }
        }

        $this->snapshots = [];
        $this->createdDirectories = [];
        $this->active = false;
        $this->committed = true;

        $failures = [];
        foreach ($backups as $backup) {
            if (!@unlink($backup) && is_file($backup)) {
                $failures[] = $backup;
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(
                'Generation was committed, but transaction backups could not be removed: '
                . implode(', ', $failures) . '.'
            );
        }
    }

    public function isCommitted(): bool
    {
        return $this->committed;
    }

    public function rollback(): void
    {
        $failures = [];
        foreach (array_reverse($this->snapshots, true) as $path => $snapshot) {
            if ($snapshot['exists']) {
                if ($snapshot['backup'] === null || !is_file($snapshot['backup'])) {
                    $failures[] = "missing backup for {$path}";
                    continue;
                }
                if (!@copy($snapshot['backup'], $path)) {
                    $failures[] = "could not restore {$path}";
                    continue;
                }
                if ($snapshot['permissions'] !== null) {
                    @chmod($path, $snapshot['permissions'] & 0777);
                }
            } elseif (file_exists($path) && !@unlink($path)) {
                $failures[] = "could not remove newly generated {$path}";
            }
        }

        foreach (array_reverse($this->createdDirectories) as $directory) {
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }

        if ($failures !== []) {
            throw new RuntimeException('Rollback was incomplete: ' . implode('; ', $failures) . '.');
        }

        foreach ($this->snapshots as $snapshot) {
            if ($snapshot['backup'] !== null && is_file($snapshot['backup'])) {
                @unlink($snapshot['backup']);
            }
        }
        $this->snapshots = [];
        $this->createdDirectories = [];
        $this->active = false;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        $missing = [];
        for ($current = $directory; !is_dir($current); $current = dirname($current)) {
            if (file_exists($current)) {
                throw new RuntimeException("Cannot create output directory: {$directory}");
            }
            $missing[] = $current;
            if (dirname($current) === $current) {
                throw new RuntimeException("Cannot resolve output directory: {$directory}");
            }
        }

        foreach (array_reverse($missing) as $path) {
            if (!@mkdir($path) && !is_dir($path)) {
                throw new RuntimeException("Could not create output directory: {$path}");
            }
            $this->createdDirectories[] = $path;
        }
    }

    private function writeAtomically(string $path, string $contents, ?int $permissions): void
    {
        $temporaryPath = tempnam(dirname($path), '.lrg-write-');
        if ($temporaryPath === false) {
            throw new RuntimeException("Could not prepare generated file: {$path}");
        }

        try {
            $written = file_put_contents($temporaryPath, $contents, LOCK_EX);
            if ($written !== strlen($contents)) {
                throw new RuntimeException("Could not completely write generated file: {$path}");
            }
            if ($permissions !== null) {
                @chmod($temporaryPath, $permissions & 0777);
            }
            if (!@rename($temporaryPath, $path)) {
                throw new RuntimeException("Could not replace generated file: {$path}");
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
