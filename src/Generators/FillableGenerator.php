<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

class FillableGenerator
{
    /**
     * Genera el contenido de $fillable a partir de una lista de columnas.
     */
    public function generate(string $modelPath, array $columns): void
    {
        if (!file_exists($modelPath)) {
            throw new \RuntimeException(
                "Model not found: {$modelPath}"
            );
        }

        $fillableColumns = array_values(
            array_filter(
                $columns,
                fn ($column) => !in_array($column, [
                    'id',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ])
            )
        );

        $fillable = $this->buildFillable($fillableColumns);

        $content = file_get_contents($modelPath);

        $pattern = '/protected\s+\$fillable\s*=\s*\[.*?\];/s';

        if (preg_match($pattern, $content)) {
            $content = preg_replace(
                $pattern,
                $fillable,
                $content,
                1
            );
        } else {
            $position = strrpos($content, '}');

            if ($position === false) {
                throw new \RuntimeException(
                    "Could not find the end of model: {$modelPath}"
                );
            }

            $content = substr_replace(
                $content,
                "\n    {$fillable}\n",
                $position,
                0
            );
        }

        file_put_contents($modelPath, $content);
    }

    /**
     * Construye el código PHP de protected $fillable.
     */
    private function buildFillable(array $columns): string
    {
        if (empty($columns)) {
            return 'protected $fillable = [];';
        }

        $lines = [
            'protected $fillable = [',
        ];

        foreach ($columns as $column) {
            $lines[] = "        '{$column}',";
        }

        $lines[] = '    ];';

        return implode("\n", $lines);
    }
}