<?php

namespace FranciscoVillaquiran\LaravelRelationGenerator\Console\Commands;

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
    protected $description = 'Generate Eloquent relationships, queries and routes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelA = $this->argument('modelA');
        $modelB = $this->argument('modelB');
        $cardinality = $this->argument('cardinality');

        if (!in_array($cardinality, ['1:1', '1:N', 'N:M'])) {
            $this->error('Invalid cardinality.');
            $this->line('Allowd: 1:1, 1:N, N:M');

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

        if ($cardinality === '1:1' || $cardinality === '1:N') {

        $foreignTable = $this->ask(
            '¿Que tabla contiene la clave foranea?'
            );

        $this->newLine();

        $this->info('Relationship information:');

        $this->line("Foreign Table: {$foreignTable}");

        $definition = new RelationDefinition(
            modelA: $modelA,
            modelB: $modelB,
            cardinality: $cardinality,
            foreignTable: $foreignTable,
        );
        }

        if ($cardinality === 'N:M') {
            $pivotTable = $this->ask(
                '¿Cuál es el nombre de la tabla pivote?'
            );

            $this->newLine();

            $this->info('Pivot information:');

            $this->line("Pivot Table: {$pivotTable}");

            $definition = new RelationDefinition(
            modelA: $modelA,
            modelB: $modelB,
            cardinality: $cardinality,
            pivotTable: $pivotTable,
            );
        }

        $this->newLine();

        $generator = new RelationshipGenerator();

        $relations = $generator->generate(
            $definition,
            app_path('Models')
        );

        $this->newLine();
        $this->info('Relationship generated successfully.');

        $migrationReader = new MigrationReader();

        $migrationPathA = $this->findMigration($modelA);
        $migrationPathB = $this->findMigration($modelB);

        if (!$migrationPathA) {
            $this->error(
                "Migration for model {$modelA} not found."
            );
            return self::FAILURE;
        }

        if (!$migrationPathB) {
            $this->error(
                "Migration for model {$modelB} not found."
            );
            return self::FAILURE;
        }

        $columnsA = $migrationReader->getColumns($migrationPathA);
        $columnsB = $migrationReader->getColumns($migrationPathB);


        $fillableGenerator = new FillableGenerator();

        $fillableGenerator->generate(
            app_path("Models/{$modelA}.php"),
            $columnsA
        );

        $fillableGenerator->generate(
            app_path("Models/{$modelB}.php"),
            $columnsB
        );

        $this->info('$fillable generated');

        $this->newLine();

        $queryGenerator = new QueryGenerator();

        $queryGenerator->generate(
            app_path('Http/Controllers/ConsultasController.php'),
            $modelA,
            $modelB,
            $relations['relationA'],
            $relations['relationB']
        );
        $this->info('ConsultasController generated.');

        $routeGenerator = new RouteGenerator();

        $routeGenerator->generate(
            base_path('routes/web.php'),
            $modelA,
            $modelB
        );

        $this->info('Routes generated.');

        $this->newLine();

        $this->info('Relation Generation completed successfully.');

        return self::SUCCESS;
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