<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Console\Commands;

use Franciscovillaquiran\LaravelRelationGenerator\Generators\RouteGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\QueryGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RelationshipGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\MigrationReader;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\FillableGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\CrudGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\FileChangeTransaction;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RegisteredApiRouteInspector;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\DatabaseSchema;
use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class RelationMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'relation:make {modelA : First model} {modelB : Second model} {cardinality : Relationship cardinality (1:1, 1:N, N:M)} {--crud : Generate structural CRUD controllers for both models} {--api : Register API routes for the generated CRUD controllers}';

    /**
     * The console command description.
     */
    protected $description = 'Generate selected Eloquent relationships, fillables, queries and routes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $transaction = null;
        try {
            $modelA = (string) $this->argument('modelA');
            $modelB = (string) $this->argument('modelB');
            $cardinality = (string) $this->argument('cardinality');
            $crud = (bool) $this->option('crud');
            $api = (bool) $this->option('api');

            if (!in_array($cardinality, ['1:1', '1:N', 'N:M'], true)) {
                $this->error('Invalid cardinality. Allowed: 1:1, 1:N, N:M.');
                return self::FAILURE;
            }
            if ($api && !$crud) {
                $this->error('The --api option requires --crud.');
                return self::FAILURE;
            }

            if ($crud) {
                $selected = ['relationship', 'fillable', 'crud'];
            } else {
                $selected = $this->parseGenerationSelection(
                    (string) $this->ask(
                        '¿Qué deseas generar? Escribe "todo" o una lista separada por comas: relacion, fillable, consultas, rutas',
                        'todo'
                    )
                );
                if ($selected === null) {
                    $this->error('Selección no válida. Usa: todo, relacion, fillable, consultas, rutas.');
                    return self::FAILURE;
                }
            }

            $this->info('Laravel Relation Generator');
            $this->line("Model A: {$modelA}");
            $this->line("Model B: {$modelB}");
            $this->line("Cardinality: {$cardinality}");

            $needsSchema = $crud
                || in_array('relationship', $selected, true)
                || in_array('fillable', $selected, true);
            $migrationPath = database_path('migrations');
            $reader = new MigrationReader();
            $schema = $needsSchema
                ? $reader->analyze($migrationPath, null, false)
                : null;

            $definition = new RelationDefinition(
                modelA: $modelA,
                modelB: $modelB,
                cardinality: $cardinality,
                modelATable: $needsSchema ? $this->modelTable($modelA) : null,
                modelBTable: $needsSchema ? $this->modelTable($modelB) : null,
            );

            if ($needsSchema) {
                $this->assertModelFilesExist($modelA, $modelB);
            }
            if (in_array('fillable', $selected, true) || $crud) {
                $this->assertSchemaTable($schema, $definition->modelATable, $modelA);
                $this->assertSchemaTable($schema, $definition->modelBTable, $modelB);
            }
            if ($crud) {
                $this->assertEloquentModels($modelA, $modelB, $definition, $schema);
            }

            $changes = $this->prepareChanges(
                $selected,
                $crud,
                $api,
                $modelA,
                $modelB,
                $definition,
                $schema
            );

            $watchedPaths = array_keys($changes);
            $indexPath = base_path('.lrg/lrg-index.json');
            if ($needsSchema) {
                $watchedPaths[] = $indexPath;
            }
            $transaction = $this->makeFileTransaction();
            $transaction->watch($watchedPaths);
            $transaction->apply($changes);

            if ($needsSchema) {
                $verifiedSchema = $reader->analyze($migrationPath, null, false);
                if ($verifiedSchema->toArray() !== $schema->toArray()) {
                    throw new \RuntimeException(
                        'The migration schema changed during generation; generated files were rolled back.'
                    );
                }
                $indexedSchema = $reader->analyze($migrationPath);
                if ($indexedSchema->toArray() !== $schema->toArray()) {
                    throw new \RuntimeException(
                        'The structural index did not match the schema used for generation.'
                    );
                }
            }
            if ($api) {
                (new RegisteredApiRouteInspector())->inspect(base_path());
            }
            $transaction->commit();

            if (in_array('relationship', $selected, true)) {
                $this->info('Relationships generated.');
            }
            if (in_array('fillable', $selected, true) || $crud) {
                $this->info('$fillable generated or verified.');
            }
            if ($crud) {
                $this->info('CRUD controllers generated.');
            }
            if ($api) {
                $this->info('API routes generated.');
            }
            if (in_array('queries', $selected, true)) {
                $this->info('ConsultasController generated.');
            }
            if (in_array('routes', $selected, true)) {
                $this->info('Legacy web routes generated.');
            }

            $this->info('Relation Generation completed successfully.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($transaction !== null && $transaction->isCommitted()) {
                $this->error($exception->getMessage());
                return self::FAILURE;
            }
            if ($transaction !== null) {
                try {
                    $transaction->rollback();
                } catch (Throwable $rollbackException) {
                    $this->error(
                        'Generation failed: ' . $exception->getMessage()
                        . ' Rollback failed: ' . $rollbackException->getMessage()
                    );
                    return self::FAILURE;
                }
            }
            $this->error('Generation failed: ' . $exception->getMessage());
            return self::FAILURE;
        }
    }

    private function prepareChanges(
        array $selected,
        bool $crud,
        bool $api,
        string $modelA,
        string $modelB,
        RelationDefinition $definition,
        ?DatabaseSchema $schema
    ): array {
        $projectRoot = base_path();
        $temporaryRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'lrg-plan-' . bin2hex(random_bytes(8));
        $stageModelsPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'Models';
        if (!mkdir($stageModelsPath, 0777, true)) {
            throw new \RuntimeException("Could not create generation staging directory: {$temporaryRoot}");
        }

        $targets = [
            'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $this->modelBasename($modelA) . '.php',
            'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $this->modelBasename($modelB) . '.php',
        ];
        if (in_array('queries', $selected, true)) {
            $targets[] = 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers'
                . DIRECTORY_SEPARATOR . 'ConsultasController.php';
        }
        if (in_array('routes', $selected, true)) {
            $targets[] = 'routes' . DIRECTORY_SEPARATOR . 'web.php';
        }
        if ($crud) {
            foreach ([$modelA, $modelB] as $model) {
                $targets[] = 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers'
                    . DIRECTORY_SEPARATOR . 'Api' . DIRECTORY_SEPARATOR . $this->modelBasename($model) . 'Controller.php';
            }
        }
        if ($api) {
            $targets[] = 'routes' . DIRECTORY_SEPARATOR . 'api.php';
            $targets[] = 'routes' . DIRECTORY_SEPARATOR . 'lrg_api.php';
            $targets[] = 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
            $targets[] = 'app' . DIRECTORY_SEPARATOR . 'Providers' . DIRECTORY_SEPARATOR . 'RouteServiceProvider.php';
        }
        $targets = array_values(array_unique($targets));

        try {
            foreach ($targets as $relativePath) {
                $sourcePath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;
                $stagePath = $temporaryRoot . DIRECTORY_SEPARATOR . $relativePath;
                if (!is_file($sourcePath)) {
                    continue;
                }
                $directory = dirname($stagePath);
                if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                    throw new \RuntimeException("Could not prepare staging directory: {$directory}");
                }
                if (!copy($sourcePath, $stagePath)) {
                    throw new \RuntimeException("Could not stage project file: {$sourcePath}");
                }
            }
            if ($api) {
                $apiControllersPath = $projectRoot . DIRECTORY_SEPARATOR . 'app'
                    . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers'
                    . DIRECTORY_SEPARATOR . 'Api';
                $stagedApiControllersPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'app'
                    . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers'
                    . DIRECTORY_SEPARATOR . 'Api';
                foreach (glob($apiControllersPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $sourcePath) {
                    if (!is_dir($stagedApiControllersPath)
                        && !mkdir($stagedApiControllersPath, 0777, true)
                        && !is_dir($stagedApiControllersPath)) {
                        throw new \RuntimeException(
                            "Could not prepare staging directory: {$stagedApiControllersPath}"
                        );
                    }
                    $stagePath = $stagedApiControllersPath . DIRECTORY_SEPARATOR . basename($sourcePath);
                    if (!copy($sourcePath, $stagePath)) {
                        throw new \RuntimeException("Could not stage existing API controller: {$sourcePath}");
                    }
                }
            }

            $generator = new RelationshipGenerator();
            $generatedRelations = null;
            if (in_array('relationship', $selected, true)) {
                $generatedRelations = $generator->generateFromSchema(
                    $definition,
                    $schema,
                    $stageModelsPath
                );
            }

            if (in_array('fillable', $selected, true) || $crud) {
                $fillableGenerator = new FillableGenerator();
                $fillableGenerator->generateFromTable(
                    $stageModelsPath . DIRECTORY_SEPARATOR . $this->modelBasename($modelA) . '.php',
                    $schema->table($definition->modelATable),
                    $this->modelBasename($modelA)
                );
                $fillableGenerator->generateFromTable(
                    $stageModelsPath . DIRECTORY_SEPARATOR . $this->modelBasename($modelB) . '.php',
                    $schema->table($definition->modelBTable),
                    $this->modelBasename($modelB)
                );
            }

            $relations = $generatedRelations ?? $generator->relationNames($definition);
            $associationA = [];
            $associationB = [];
            $legacyAssociationA = [];
            $legacyAssociationB = [];
            $apiAssociations = [];
            if ($definition->cardinality === 'N:M') {
                $classA = $this->modelClass($modelA);
                $classB = $this->modelClass($modelB);
                $associationA[] = [
                    'relatedModelClass' => $classB,
                    'relation' => $relations['relationA'],
                ];
                $legacyAssociationA = $associationA;
                $legacyAssociationB[] = [
                    'relatedModelClass' => $classA,
                    'relation' => $relations['relationB'],
                ];
                $apiAssociations = [
                    [
                        'parentModelClass' => $classA,
                        'relatedModelClass' => $classB,
                        'relation' => $relations['relationA'],
                    ],
                ];
            }

            if ($crud) {
                $crudGenerator = new CrudGenerator();
                $crudGenerator->generateFromTable(
                    $temporaryRoot,
                    $this->modelClass($modelA),
                    $schema->table($definition->modelATable),
                    $schema,
                    $associationA,
                    $legacyAssociationA
                );
                $crudGenerator->generateFromTable(
                    $temporaryRoot,
                    $this->modelClass($modelB),
                    $schema->table($definition->modelBTable),
                    $schema,
                    $associationB,
                    $legacyAssociationB
                );
            }

            if (in_array('queries', $selected, true)) {
                (new QueryGenerator())->generate(
                    $temporaryRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
                        . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'ConsultasController.php',
                    $modelA,
                    $modelB,
                    $relations['relationA'],
                    $relations['relationB']
                );
            }
            if (in_array('routes', $selected, true)) {
                (new RouteGenerator())->generate(
                    $temporaryRoot . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php',
                    $modelA,
                    $modelB
                );
            }
            if ($api) {
                (new \Franciscovillaquiran\LaravelRelationGenerator\Generators\ApiRouteGenerator())->generate(
                    $temporaryRoot,
                    [$this->modelClass($modelA), $this->modelClass($modelB)],
                    $apiAssociations
                );
            }

            $changes = [];
            foreach ($targets as $relativePath) {
                $stagePath = $temporaryRoot . DIRECTORY_SEPARATOR . $relativePath;
                if (!is_file($stagePath)) {
                    continue;
                }
                $contents = file_get_contents($stagePath);
                if ($contents === false) {
                    throw new \RuntimeException("Could not read staged output: {$stagePath}");
                }
                if (strtolower(pathinfo($stagePath, PATHINFO_EXTENSION)) === 'php') {
                    try {
                        token_get_all($contents, TOKEN_PARSE);
                    } catch (\ParseError $exception) {
                        throw new \RuntimeException("Staged output has invalid PHP: {$stagePath}", 0, $exception);
                    }
                }
                $realPath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;
                if (!is_file($realPath) || file_get_contents($realPath) !== $contents) {
                    $changes[$realPath] = $contents;
                }
            }
            foreach ($targets as $relativePath) {
                $stagePath = $temporaryRoot . DIRECTORY_SEPARATOR . $relativePath;
                $realPath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;
                if (!is_file($stagePath) && is_file($realPath)) {
                    $changes[$realPath] = null;
                }
            }

            return $changes;
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot);
        }
    }

    private function assertModelFilesExist(string ...$models): void
    {
        foreach ($models as $model) {
            if (!is_file($this->modelPath($model))) {
                throw new \RuntimeException("Model {$model} not found at {$this->modelPath($model)}.");
            }
        }
    }

    private function assertEloquentModels(
        string $modelA,
        string $modelB,
        RelationDefinition $definition,
        DatabaseSchema $schema
    ): void
    {
        foreach ([
            [$modelA, $definition->modelATable],
            [$modelB, $definition->modelBTable],
        ] as [$model, $table]) {
            $class = $this->modelClass($model);
            if (
                !class_exists($class)
                || !class_exists(\Illuminate\Database\Eloquent\Model::class)
                || !is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)
            ) {
                throw new \RuntimeException("CRUD requires an existing Eloquent model class: {$class}.");
            }
            $instance = new $class();
            if ($instance->getTable() !== $table) {
                throw new \RuntimeException(
                    "Model {$class} maps to {$instance->getTable()}, not migration table {$table}."
                );
            }
            $schemaTable = $schema->table($table);
            if (isset($schemaTable->columns['deleted_at'])) {
                $usedTraits = function_exists('class_uses_recursive')
                    ? class_uses_recursive($class)
                    : class_uses($class);
                if (!in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, $usedTraits, true)) {
                    throw new \RuntimeException(
                        "Model {$class} has a deleted_at column but does not use Eloquent SoftDeletes."
                    );
                }
            }
            $primaryKeys = [];
            foreach ($schemaTable->indexes as $index) {
                if ($index->type === 'primary') {
                    $primaryKeys = $index->columns;
                    break;
                }
            }
            if (count($primaryKeys) !== 1 || $instance->getKeyName() !== $primaryKeys[0]) {
                throw new \RuntimeException(
                    "Model {$class} must use the single primary key declared by table {$table}."
                );
            }
        }
    }

    private function modelClass(string $model): string
    {
        return str_contains($model, '\\')
            ? ltrim($model, '\\')
            : 'App\\Models\\' . $this->modelBasename($model);
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }

    protected function makeFileTransaction(): FileChangeTransaction
    {
        return new FileChangeTransaction();
    }

    private function parseGenerationSelection(string $selection): ?array
    {
        $names = [
            'relacion' => 'relationship',
            'fillable' => 'fillable',
            'consultas' => 'queries',
            'rutas' => 'routes',
        ];

        $choices = array_values(array_filter(array_map(
            static fn (string $choice): string => strtolower(trim($choice)),
            explode(',', $selection)
        )));

        if (count($choices) === 1 && $choices[0] === 'todo') {
            return ['relationship', 'fillable', 'queries', 'routes'];
        }

        if ($choices === [] || in_array('todo', $choices, true)) {
            return null;
        }

        $selected = [];
        foreach ($choices as $choice) {
            if (!isset($names[$choice])) {
                return null;
            }

            $selected[] = $names[$choice];
        }

        return array_values(array_unique($selected));
    }

    private function modelTable(string $model): string
    {
        $modelBasename = $this->modelBasename($model);
        $class = str_contains($model, '\\') ? ltrim($model, '\\') : 'App\\Models\\' . $modelBasename;
        if (
            class_exists($class)
            && class_exists(\Illuminate\Database\Eloquent\Model::class)
            && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)
        ) {
            $instance = new $class();
            return $instance->getTable();
        }

        return Str::plural(Str::snake($modelBasename));
    }

    private function modelBasename(string $model): string
    {
        $parts = explode('\\', ltrim($model, '\\'));
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) {
                throw new \InvalidArgumentException("Invalid model class name: {$model}");
            }
        }

        return end($parts);
    }

    private function modelPath(string $model): string
    {
        return app_path('Models' . DIRECTORY_SEPARATOR . $this->modelBasename($model) . '.php');
    }

    private function assertSchemaTable($schema, ?string $table, string $model): void
    {
        if ($table === null || $schema->table($table) === null) {
            throw new \RuntimeException(
                "Could not find a migration table for model {$model}."
            );
        }
    }

}