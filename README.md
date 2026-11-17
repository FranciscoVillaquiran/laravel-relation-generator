# Laravel Relation Generator

This package provides the `relation:make` Artisan command for generating Eloquent
relationships, `$fillable` properties, query methods, and routes.

## Requirements

- PHP 8.1 or later
- Laravel 10, 11, 12, or 13

## Usage

The command keeps its existing three required arguments:

```bash
php artisan relation:make ModelA ModelB 1:N
```

After entering the command, choose `todo` (the default) to generate all supported
elements, or enter a comma-separated selection:

- `relacion`: relationship methods on both models
- `fillable`: `$fillable` properties read from each model's migration
- `consultas`: query methods in `ConsultasController`
- `rutas`: routes in `routes/web.php`

For example, `relacion, fillable` generates only the model relationships and
fillable properties. `consultas` assumes the corresponding model relationships
already exist, and `rutas` assumes the controller query methods are available.

The package follows semantic versioning through Git tags (`vMAJOR.MINOR.PATCH`).

## Structural migration analysis

`MigrationReader::analyze($path)` accepts a migration file, a migration
directory, or a list of paths and returns a normalized schema. In Laravel it
also maintains `.lrg/lrg-index.json` in the application root, reusing cached
operations for unchanged migrations. Outside Laravel, pass an optional second
argument to select an index path. Unsupported or dynamic schema code raises a
contextual `MigrationAnalysisException`; migrations are tokenized, never
executed.

Run the self-contained reader tests with:

```bash
php tests/run.php
```

## Project documentation

For the detailed architecture, command flow, generated files, current
limitations, and possible next increments, see
[the project guide](docs/PROJECT_GUIDE.md).
