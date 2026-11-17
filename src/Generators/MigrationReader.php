<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\DatabaseSchema;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\MigrationParser;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaBuilder;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\StructuralSchemaIndex;

class MigrationReader
{
    public function __construct(private ?MigrationParser $parser = null)
    {
        $this->parser ??= new MigrationParser();
    }

    /**
     * Read migration files in Laravel's filename order and return normalized schema metadata.
     *
     * @param string|list<string> $migrationPaths A migration file, directory, or list of either.
     * @param string|null $indexPath Override the Laravel application's default structural index path.
     */
    public function analyze(string|array $migrationPaths, ?string $indexPath = null): DatabaseSchema
    {
        $files = $this->migrationFiles($migrationPaths);

        if ($indexPath === null && function_exists('base_path')) {
            $indexPath = base_path('.lrg/lrg-index.json');
        }

        if ($indexPath !== null) {
            $projectRoot = function_exists('base_path') ? base_path() : getcwd();

            return (new StructuralSchemaIndex($indexPath, $projectRoot))->analyze($files, $this->parser);
        }

        $operations = [];

        foreach ($files as $file) {
            array_push($operations, ...$this->parser->parse($file));
        }

        return (new SchemaBuilder())->build($operations, $files);
    }

    /**
     * Obtiene las columnas definidas en una migración.
     */
    public function getColumns(string $migrationPath): array
    {
        if (!file_exists($migrationPath)) {
            throw new \RuntimeException(
                "Migration not found: {$migrationPath}"
            );
        }

        $content = file_get_contents($migrationPath);

        $columns = [];

        /*
         * Busca definiciones como:
         *
         * $table->string('placa');
         * $table->integer('edad');
         * $table->foreignId('truker_id');
         * $table->boolean('activo');
         */
        preg_match_all(
            '/\$table->\w+\(\s*[\'"]([^\'"]+)[\'"]/',
            $content,
            $matches
        );

        if (!empty($matches[1])) {
            $columns = $matches[1];
        }

        /*
         * $table->id() no tiene nombre explícito,
         * por lo que lo añadimos para que FillableGenerator
         * pueda excluirlo posteriormente.
         */
        if (str_contains($content, '$table->id()')) {
            array_unshift($columns, 'id');
        }

        /*
         * timestamps() tampoco aparece en la expresión
         * anterior porque no recibe nombre de columna.
         */
        if (str_contains($content, '$table->timestamps()')) {
            $columns[] = 'created_at';
            $columns[] = 'updated_at';
        }

        /*
         * softDeletes() genera deleted_at.
         */
        if (str_contains($content, '$table->softDeletes()')) {
            $columns[] = 'deleted_at';
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param string|list<string> $paths
     * @return list<string>
     */
    private function migrationFiles(string|array $paths): array
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $files = [];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                $matches = glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php');
                if ($matches === false) {
                    throw new \RuntimeException("Could not read migration directory: {$path}");
                }
                array_push($files, ...$matches);
                continue;
            }

            if (!is_file($path)) {
                throw new \RuntimeException("Migration not found: {$path}");
            }

            $files[] = $path;
        }

        $files = array_values(array_unique($files));
        usort(
            $files,
            static fn (string $left, string $right): int => basename($left) <=> basename($right)
        );

        return $files;
    }
}