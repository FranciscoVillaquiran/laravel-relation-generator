# Laravel Relation Generator

This package provides the `relation:make` Artisan command for generating Eloquent
relationships, `$fillable` properties, query methods, and web routes. A separate
API route generator can register existing CRUD controllers without changing the
legacy command workflow.

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
- `fillable`: `$fillable` properties generated from the structural schema
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

`relation:make` uses the structural schema for relationship and fillable
generation. The Eloquent/SQLite integration test is available as
`php tests/eloquent_integration.php <path-to-vendor/autoload.php>` when
`illuminate/database` and PDO SQLite are installed.

## API route generation

The API route generator is deliberately separate from `relation:make` for this
increment. It preserves `RouteGenerator` and `routes/web.php` behavior:

```php
(new \Franciscovillaquiran\LaravelRelationGenerator\Generators\ApiRouteGenerator())
    ->generate(base_path(), ['App\\Models\\User', 'App\\Models\\BlogPost']);
```

It manages `routes/lrg_api.php` and inserts one marked `require_once` in
`routes/api.php`. The API route file must already be registered in
`bootstrap/app.php` (Laravel 11+) or `app/Providers/RouteServiceProvider.php`
(Laravel 10); ambiguous or disabled API routing is rejected. CRUD controllers
must exist under `app/Http/Controllers/Api` and expose public `index`, `store`,
`show`, `update`, and `destroy` methods. Existing manual routes and controllers
are preserved; detected URI/name conflicts fail rather than being duplicated.

Verify generation and dispatch against a Laravel autoloader with:

```bash
php tests/api_routes_integration.php <path-to-vendor/autoload.php>
```

## Project documentation

For the detailed architecture, command flow, generated files, current
limitations, and possible next increments, see
[the project guide](docs/PROJECT_GUIDE.md).
