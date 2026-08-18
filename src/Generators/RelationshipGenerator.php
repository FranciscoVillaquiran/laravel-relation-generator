<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;

class RelationshipGenerator
{
    public function generate(RelationDefinition $definition, string $modelsPath): array
    {
        if ($definition->cardinality === '1:N') {
            return $this->generateOneToMany($definition, $modelsPath);
        }
        throw new \RuntimeException(
            "Cardinality {$definition->cardinality} is not implemented yet."
        );
    }

    private function generateOneToMany(
        RelationDefinition $definition,
        string $modelsPath
    ): array {
        $modelA = $definition->modelA;
        $modelB = $definition->modelB;

        $modelAPath = $modelsPath . DIRECTORY_SEPARATOR . $modelA . '.php';
        $modelBPath = $modelsPath . DIRECTORY_SEPARATOR . $modelB . '.php';

        if (!file_exists($modelAPath)) {
            throw new \RuntimeException(
                "Model {$modelA} not found."
            );
        }

        if (!file_exists($modelBPath)) {
            throw new \RuntimeException(
                "Model {$modelB} not found."
            );
        }

        $relationA = $this->pluralize(
            $this->lowerFirst($modelB)
        );

        $relationB = $this->lowerFirst($modelA);

        $this->addMethod(
            $modelAPath,
            $this->buildHasManyMethod($relationA, $modelB)
        );

        $this->addMethod(
            $modelBPath,
            $this->buildBelongsToMethod($relationB, $modelA)
        );

        return [
            'relationA' => $relationA,
            'relationB' => $relationB,
        ];
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