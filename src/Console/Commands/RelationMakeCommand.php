<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Console\Commands;

use Franciscovillaquiran\LaravelRelationGenerator\Generators\RouteGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\QueryGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RelationshipGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\MigrationReader;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\FillableGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;
use Illuminate\Console\Command;

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

        $foreignTable = null;
        $pivotTable = null;

        if (in_array('relationship', $selected, true)) {
            if ($cardinality === '1:1' || $cardinality === '1:N') {
                $foreignTable = $this->ask(
                    '¿Qué tabla contiene la clave foránea?'
                );

                if (!is_string($foreignTable) || trim($foreignTable) === '') {
                    $this->error('La tabla que contiene la clave foránea es obligatoria.');

                    return self::FAILURE;
                }

                $this->line("Foreign Table: {$foreignTable}");
            }

            if ($cardinality === 'N:M') {
                $pivotTable = $this->ask('¿Cuál es el nombre de la tabla pivote?');

                if (!is_string($pivotTable) || trim($pivotTable) === '') {
                    $this->error('El nombre de la tabla pivote es obligatorio.');

                    return self::FAILURE;
                }

                $this->line("Pivot Table: {$pivotTable}");
            }
        }

        $definition = new RelationDefinition(
            modelA: $modelA,
            modelB: $modelB,
            cardinality: $cardinality,
            foreignTable: $foreignTable,
            pivotTable: $pivotTable,
        );

        $migrationPathA = null;
        $migrationPathB = null;
        if (in_array('fillable', $selected, true)) {
            $migrationPathA = $this->findMigration($modelA);
            $migrationPathB = $this->findMigration($modelB);

            if (!$migrationPathA) {
                $this->error("Migration for model {$modelA} not found.");

                return self::FAILURE;
            }

            if (!$migrationPathB) {
                $this->error("Migration for model {$modelB} not found.");

                return self::FAILURE;
            }
        }

        $this->newLine();

        $generator = new RelationshipGenerator();
        if (in_array('relationship', $selected, true)) {
            $generator->generate($definition, app_path('Models'));
            $this->info('Relationship generated successfully.');
        }

        if (in_array('fillable', $selected, true)) {
            $migrationReader = new MigrationReader();
            $fillableGenerator = new FillableGenerator();

            $fillableGenerator->generate(
                app_path("Models/{$modelA}.php"),
                $migrationReader->getColumns($migrationPathA)
            );

            $fillableGenerator->generate(
                app_path("Models/{$modelB}.php"),
                $migrationReader->getColumns($migrationPathB)
            );

            $this->info('$fillable generated');
        }

        $relations = $generator->relationNames($definition);
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

    private function findMigration(string $model): ?string
    {
        $table = str($model)->snake()->plural();

        $migrationDirectory = database_path('migrations');

        $files = glob(
            $migrationDirectory . '/*.php'
        );

        foreach ($files as $file) {

            $content = file_get_contents($file);

            if (
                str_contains(
                    $content,
                    "Schema::create('{$table}'"
                )
            ) {
                return $file;
            }
        }

        return null;
    }
}