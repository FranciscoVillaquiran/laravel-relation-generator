<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

class MigrationReader
{
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
}