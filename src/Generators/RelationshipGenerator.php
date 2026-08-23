<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;

class RelationshipGenerator
{
    public function generate(RelationDefinition $definition, string $modelsPath): array
    {
        if ($definition->cardinality === '1:1') {
            return $this->generateOneToOne($definition, $modelsPath);
        }

        if ($definition->cardinality === '1:N') {
            return $this->generateOneToMany($definition, $modelsPath);
        }

        if ($definition->cardinality === 'N:M') {
            return $this->generateManyToMany($definition, $modelsPath);
        }

        throw new \RuntimeException(
            "Cardinality {$definition->cardinality} is not implemented yet."
        );
    }

    private function generateOneToOne(
        RelationDefinition $definition,
        string $modelsPath
    ): array {
        [$modelAPath, $modelBPath] = $this->modelPaths($definition, $modelsPath);

        $relationA = $this->lowerFirst($definition->modelB);
        $relationB = $this->lowerFirst($definition->modelA);

        if ($this->foreignTableIs($definition, $definition->modelB)) {
            $methodA = $this->buildHasOneMethod($relationA, $definition->modelB);
            $methodB = $this->buildBelongsToMethod($relationB, $definition->modelA);
        } else {
            $methodA = $this->buildBelongsToMethod($relationA, $definition->modelB);
            $methodB = $this->buildHasOneMethod($relationB, $definition->modelA);
        }

        $this->addMethod($modelAPath, $methodA);
        $this->addMethod($modelBPath, $methodB);

        return [
            'relationA' => $relationA,
            'relationB' => $relationB,
        ];
    }

    private function generateOneToMany(
        RelationDefinition $definition,
        string $modelsPath
    ): array {
        $modelA = $definition->modelA;
        $modelB = $definition->modelB;

        [$modelAPath, $modelBPath] = $this->modelPaths($definition, $modelsPath);

        $relationA = $this->pluralize(
            $this->lowerFirst($modelB)
        );

        $relationB = $this->lowerFirst($modelA);

        if ($this->foreignTableIs($definition, $modelB)) {
            $methodA = $this->buildHasManyMethod($relationA, $modelB);
            $methodB = $this->buildBelongsToMethod($relationB, $modelA);
        } else {
            $methodA = $this->buildBelongsToMethod($relationA, $modelB);
            $methodB = $this->buildHasManyMethod($relationB, $modelA);
        }

        $this->addMethod($modelAPath, $methodA);
        $this->addMethod($modelBPath, $methodB);

        return [
            'relationA' => $relationA,
            'relationB' => $relationB,
        ];
    }

    private function generateManyToMany(
        RelationDefinition $definition,
        string $modelsPath
    ): array {
        [$modelAPath, $modelBPath] = $this->modelPaths($definition, $modelsPath);

        if (!$definition->pivotTable) {
            throw new \InvalidArgumentException(
                'A pivot table is required for N:M relationships.'
            );
        }

        $relationA = $this->pluralize($this->lowerFirst($definition->modelB));
        $relationB = $this->pluralize($this->lowerFirst($definition->modelA));

        $this->addMethod(
            $modelAPath,
            $this->buildBelongsToManyMethod(
                $relationA,
                $definition->modelB,
                $definition->pivotTable
            )
        );

        $this->addMethod(
            $modelBPath,
            $this->buildBelongsToManyMethod(
                $relationB,
                $definition->modelA,
                $definition->pivotTable
            )
        );

        return [
            'relationA' => $relationA,
            'relationB' => $relationB,
        ];
    }

    private function modelPaths(
        RelationDefinition $definition,
        string $modelsPath
    ): array {
        $paths = [
            $modelsPath . DIRECTORY_SEPARATOR . $definition->modelA . '.php',
            $modelsPath . DIRECTORY_SEPARATOR . $definition->modelB . '.php',
        ];

        foreach ($paths as $index => $path) {
            if (!file_exists($path)) {
                $model = $index === 0 ? $definition->modelA : $definition->modelB;

                throw new \RuntimeException("Model {$model} not found.");
            }
        }

        return $paths;
    }

    private function foreignTableIs(
        RelationDefinition $definition,
        string $model
    ): bool {
        $foreignTable = trim((string) $definition->foreignTable);
        $modelTable = $this->pluralize(
            strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $model))
        );

        return $foreignTable === $model || $foreignTable === $modelTable;
    }

    private function buildHasManyMethod(
        string $relationName,
        string $relatedModel
    ): string {
        return <<<PHP
    
    public function {$relationName}()
    {
        return \$this->hasMany('App\\\\Models\\\\{$relatedModel}');
    }
PHP;
    }

    private function buildHasOneMethod(
        string $relationName,
        string $relatedModel
    ): string {
        return <<<PHP

    public function {$relationName}()
    {
        return \$this->hasOne('App\\\\Models\\\\{$relatedModel}');
    }
PHP;
    }

    private function buildBelongsToManyMethod(
        string $relationName,
        string $relatedModel,
        string $pivotTable
    ): string {
        return <<<PHP

    public function {$relationName}()
    {
        return \$this->belongsToMany('App\\\\Models\\\\{$relatedModel}', '{$pivotTable}');
    }
PHP;
    }

    private function buildBelongsToMethod(
        string $relationName,
        string $relatedModel
    ): string {
        return <<<PHP

    public function {$relationName}()
    {
        return \$this->belongsTo('App\\\\Models\\\\{$relatedModel}');
    }
PHP;

    }

    private function addMethod(
        string $modelPath,
        string $method
    ): void {
        $content = file_get_contents($modelPath);

        if (str_contains($content, trim($method))) {
            return;
        }

        $position = strrpos($content, '}');

        if ($position === false) {
            throw new \RuntimeException(
                "Could not find the end of model {$modelPath}."
            );
        }

        $content = substr_replace(
            $content,
            $method,
            $position,
            0
        );

        file_put_contents($modelPath, $content);
    }

    private function lowerFirst(string $value): string
    {
        return lcfirst($value);
    }

    private function pluralize(string $value): string
    {
        if (str_ends_with($value, 'y')) {
            return substr($value, 0, -1) . 'ies';
        }

        if (str_ends_with($value, 's')) {
            return $value;
        }

        return $value . 's';
    }
}