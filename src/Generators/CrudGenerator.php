<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\DatabaseSchema;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaTable;
use Illuminate\Support\Str;
use RuntimeException;

class CrudGenerator
{
    public function generateFromTable(
        string $projectRoot,
        string $modelClass,
        SchemaTable $table,
        DatabaseSchema $schema,
        array $associations = []
    ): bool {
        $modelBasename = $this->modelBasename($modelClass);
        $controllerPath = rtrim($projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api'
            . DIRECTORY_SEPARATOR . $modelBasename . 'Controller.php';
        $hasTimestamps = isset($table->columns['created_at'], $table->columns['updated_at']);
        $source = $this->controllerSource($modelClass, $modelBasename, $associations, $hasTimestamps);
        $basicSource = $associations === []
            ? $source
            : $this->controllerSource($modelClass, $modelBasename, [], $hasTimestamps);
        $legacyBasicSource = $hasTimestamps
            ? $basicSource
            : $this->controllerSource($modelClass, $modelBasename, [], true);

        if (is_file($controllerPath)) {
            $existing = file_get_contents($controllerPath);
            if ($existing === $source) {
                return false;
            }
            if ($existing !== $basicSource && $existing !== $legacyBasicSource) {
                throw new RuntimeException(
                    "Controller {$controllerPath} already exists and is not an unchanged LRG-generated controller."
                );
            }
        }

        $directory = dirname($controllerPath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create controller directory: {$directory}");
        }
        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new RuntimeException("Generated controller has invalid PHP: {$controllerPath}", 0, $exception);
        }

        $temporaryPath = tempnam($directory, '.lrg-controller-');
        if ($temporaryPath === false) {
            throw new RuntimeException("Could not prepare generated controller: {$controllerPath}");
        }
        try {
            $written = file_put_contents($temporaryPath, $source, LOCK_EX);
            if ($written !== strlen($source) || file_get_contents($temporaryPath) !== $source) {
                throw new RuntimeException("Could not verify generated controller: {$controllerPath}");
            }
            if (!@rename($temporaryPath, $controllerPath)) {
                throw new RuntimeException("Could not install generated controller: {$controllerPath}");
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return true;
    }

    private function controllerSource(
        string $modelClass,
        string $modelBasename,
        array $associations,
        bool $hasTimestamps
    ): string
    {
        $modelClass = '\\' . ltrim($modelClass, '\\');
        if (!str_contains(ltrim($modelClass, '\\'), '\\')) {
            $modelClass = '\\App\\Models\\' . ltrim($modelClass, '\\');
        }
        $modelImport = ltrim($modelClass, '\\');
        $resourceController = $modelBasename . 'Controller';
        $storeOperation = $hasTimestamps
            ? "        \$record = {$modelClass}::create(\$request->all());\n"
            : "        \$record = new {$modelClass}();\n"
                . "        \$record->timestamps = false;\n"
                . "        \$record->fill(\$request->all());\n"
                . "        \$record->save();\n";
        $updateTimestamp = $hasTimestamps ? '' : "        \$record->timestamps = false;\n";
        $associationActions = '';
        foreach ($associations as $association) {
            $relatedModelClass = (string) ($association['relatedModelClass'] ?? '');
            $relatedModelBasename = $this->modelBasename($relatedModelClass);
            $relation = (string) ($association['relation'] ?? '');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $relation) !== 1) {
                throw new RuntimeException("Invalid Eloquent relationship method: {$relation}");
            }

            $relatedModelClass = '\\' . ltrim($relatedModelClass, '\\');
            if (!str_contains(ltrim($relatedModelClass, '\\'), '\\')) {
                $relatedModelClass = '\\App\\Models\\' . ltrim($relatedModelClass, '\\');
            }
            $parentParameter = Str::camel(Str::singular(Str::plural(Str::snake($modelBasename))));
            $relatedParameter = Str::camel(Str::singular(Str::plural(Str::snake($relatedModelBasename))));
            $parentVariable = '$' . $parentParameter;
            $relatedVariable = '$' . $relatedParameter;
            $methodSuffix = Str::studly($relatedModelBasename);
            $associationActions .=
                "    public function attach{$methodSuffix}(string {$parentVariable}, string {$relatedVariable})\n"
                . "    {\n"
                . "        \$parentRecord = {$modelClass}::findOrFail({$parentVariable});\n"
                . "        \$relatedRecord = {$relatedModelClass}::findOrFail({$relatedVariable});\n"
                . "        \$parentRecord->{$relation}()->syncWithoutDetaching([\$relatedRecord->getKey()]);\n"
                . "        return response()->json(\$parentRecord->load(" . var_export($relation, true) . "), 201);\n"
                . "    }\n\n"
                . "    public function detach{$methodSuffix}(string {$parentVariable}, string {$relatedVariable})\n"
                . "    {\n"
                . "        \$parentRecord = {$modelClass}::findOrFail({$parentVariable});\n"
                . "        \$relatedRecord = {$relatedModelClass}::findOrFail({$relatedVariable});\n"
                . "        \$parentRecord->{$relation}()->detach(\$relatedRecord->getKey());\n"
                . "        return response()->noContent();\n"
                . "    }\n\n";
        }

        return "<?php\n\n"
            . "// LRG generated controller; do not edit this file manually.\n"
            . "namespace App\\Http\\Controllers\\Api;\n\n"
            . "use Illuminate\\Http\\Request;\n"
            . "use {$modelImport};\n\n"
            . "class {$resourceController}\n{\n"
            . "    public function index()\n    {\n"
            . "        return response()->json({$modelClass}::all());\n"
            . "    }\n\n"
            . "    public function store(Request \$request)\n    {\n"
            . $storeOperation
            . "        return response()->json(\$record, 201);\n"
            . "    }\n\n"
            . "    public function show(string \$id)\n    {\n"
            . "        return response()->json({$modelClass}::findOrFail(\$id));\n"
            . "    }\n\n"
            . "    public function update(Request \$request, string \$id)\n    {\n"
            . "        \$record = {$modelClass}::findOrFail(\$id);\n"
            . $updateTimestamp
            . "        \$record->update(\$request->all());\n"
            . "        return response()->json(\$record);\n"
            . "    }\n\n"
            . "    public function destroy(string \$id)\n    {\n"
            . "        \$record = {$modelClass}::findOrFail(\$id);\n"
            . "        \$record->delete();\n"
            . "        return response()->noContent();\n"
            . "    }\n"
            . $associationActions
            . "}\n";
    }

    private function modelBasename(string $modelClass): string
    {
        $parts = explode('\\', ltrim($modelClass, '\\'));
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) {
                throw new RuntimeException("Invalid model class name: {$modelClass}");
            }
        }

        if ($parts === [] || end($parts) === '') {
            throw new RuntimeException("Invalid model class name: {$modelClass}");
        }

        return end($parts);
    }
}
