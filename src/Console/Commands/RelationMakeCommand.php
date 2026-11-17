<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Console\Commands;

use Franciscovillaquiran\LaravelRelationGenerator\Generators\RouteGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\QueryGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RelationshipGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\MigrationReader;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\FillableGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RelationMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'relation:make {modelA : First model} {modelB : Second model} {cardinality : Relationship cardinality (1:1, 1:N, N:M)}';

    /**
     * The console command description.
     */
    protected $description = 'Generate selected Eloquent relationships, fillables, queries and routes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelA = $this->argument('modelA');
        $modelB = $this->argument('modelB');
        $cardinality = $this->argument('cardinality');

        if (!in_array($cardinality, ['1:1', '1:N', 'N:M'], true)) {
            $this->error('Invalid cardinality.');
            $this->line('Allowed: 1:1, 1:N, N:M');

            return self::FAILURE;
        }

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

        $this->info('Laravel Relation Generator');
        $this->newLine();

        $this->line("Model A: {$modelA}");
        $this->line("Model B: {$modelB}");
        $this->line("Cardinality: {$cardinality}");

        $this->newLine();

        $needsSchema = in_array('relationship', $selected, true)
            || in_array('fillable', $selected, true);
        $schema = $needsSchema
            ? (new MigrationReader())->analyze(database_path('migrations'))
            : null;

        $definition = new RelationDefinition(
            modelA: $modelA,
            modelB: $modelB,
            cardinality: $cardinality,
            modelATable: $needsSchema ? $this->modelTable($modelA) : null,
            modelBTable: $needsSchema ? $this->modelTable($modelB) : null,
        );

        if (in_array('fillable', $selected, true)) {
            $this->assertSchemaTable($schema, $definition->modelATable, $modelA);
            $this->assertSchemaTable($schema, $definition->modelBTable, $modelB);
        }

        $this->newLine();

        $generator = new RelationshipGenerator();
        $generatedRelations = null;
        if (in_array('relationship', $selected, true)) {
            $generatedRelations = $generator->generateFromSchema(
                $definition,
                $schema,
                app_path('Models')
            );
            $this->info('Relationship generated successfully.');
        }

        if (in_array('fillable', $selected, true)) {
            $fillableGenerator = new FillableGenerator();

            $fillableGenerator->generateFromTable(
                $this->modelPath($modelA),
                $schema->table($definition->modelATable),
                $this->modelBasename($modelA)
            );

            $fillableGenerator->generateFromTable(
                $this->modelPath($modelB),
                $schema->table($definition->modelBTable),
                $this->modelBasename($modelB)
            );

            $this->info('$fillable generated');
        }

        $relations = $generatedRelations ?? $generator->relationNames($definition);
        if (in_array('queries', $selected, true)) {
            $queryGenerator = new QueryGenerator();
            $queryGenerator->generate(
                app_path('Http/Controllers/ConsultasController.php'),
                $modelA,
                $modelB,
                $relations['relationA'],
                $relations['relationB']
            );
            $this->info('ConsultasController generated.');

            if (!in_array('relationship', $selected, true)) {
                $this->warn('Las consultas esperan que esas relaciones ya estén definidas en los modelos.');
            }
        }

        if (in_array('routes', $selected, true)) {
            $routeGenerator = new RouteGenerator();
            $routeGenerator->generate(
                base_path('routes/web.php'),
                $modelA,
                $modelB
            );
            $this->info('Routes generated.');

            if (!in_array('queries', $selected, true)) {
                $this->warn('Las rutas esperan que los métodos de consulta ya existan en ConsultasController.');
            }
        }

        $this->newLine();

        $this->info('Relation Generation completed successfully.');

        return self::SUCCESS;
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