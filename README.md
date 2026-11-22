# Laravel Relation Generator

This package provides the `relation:make` Artisan command for generating Eloquent
relationships, `$fillable` properties, structural CRUD controllers, and API routes,
while preserving the legacy query and web-route workflow.

## Requirements

- PHP 8.1 or later
- Laravel 10, 11, 12, or 13

## Usage

The command keeps its existing three required arguments:

```bash
php artisan relation:make ModelA ModelB 1:N
```

After entering the command, choose `todo` (the default) to generate all supported
legacy elements, or enter a comma-separated selection:

- `relacion`: relationship methods on both models
- `fillable`: `$fillable` properties generated from the structural schema
- `consultas`: query methods in `ConsultasController`
- `rutas`: routes in `routes/web.php`

For example, `relacion, fillable` generates only the model relationships and
fillable properties. `consultas` assumes the corresponding model relationships
already exist, and `rutas` assumes the controller query methods are available.
`todo` retains its original meaning and does not include CRUD.

Generate relationships, fillables, CRUD controllers for both models, and API
routes without an interactive prompt:

```bash
php artisan relation:make Category Product 1:N --crud --api
```

`--crud` generates controllers without registering API routes.
`--api` requires `--crud`. Models and migration files must already exist;
LRG does not create models or migrations, or run migrations.
Generated controllers expose `index`, `store`, `show`, `update`, and `destroy`
using direct Eloquent calls. They do not add request validation or custom
database-error handling; Eloquent and the database retain their normal behavior.

Generation is prepared in temporary copies, then applied with recoverable
backups. If validation, a write, schema verification, or index update fails,
LRG restores the previous project files. The structural index
`.lrg/lrg-index.json` is updated only after generation succeeds.

The package follows semantic versioning through Git tags (`vMAJOR.MINOR.PATCH`).
The direct, validation-free CRUD controller behavior is introduced in `v3.0.0`.
Inline API routes and many-to-many association endpoints are introduced in `v3.1.0`.
Many-to-many assignment through a CRUD field and one owner route are introduced
in `v3.2.0`.

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

`relation:make` uses the structural schema for relationships, fillables, and
CRUD generation. The Eloquent/SQLite integration test is available as
`php tests/eloquent_integration.php <path-to-vendor/autoload.php>` when
`illuminate/database` and PDO SQLite are installed.

The full command integration test runs against a Laravel autoloader:

```bash
php tests/full_generation_integration.php <path-to-vendor/autoload.php>
```

It exercises all three cardinalities, CRUD HTTP responses, many-to-many
assignment/removal, routes written directly to `routes/api.php`, idempotency,
manual conflicts, migration ambiguity, and rollback after a controlled write
failure. The supported package
constraints remain PHP `^8.1` and Laravel 10–13; this integration has been run
on PHP 8.3.33 with Laravel 12.72.0.

## API routes and many-to-many associations

The command writes managed `Route::apiResource()` declarations directly into a
marked block in `routes/api.php`. When upgrading from an earlier release, it
migrates the old managed routes and manual routes from `routes/lrg_api.php`
into `routes/api.php`, then removes the obsolete file. It preserves unrelated
routes and `routes/web.php`. Laravel must already load `routes/api.php`;
configurations that cannot be verified are rejected.

For an N:M relation generated with `--crud --api`, the first model's controller
owns the assignment. Its create and update endpoints accept `related_ids` as
an array of existing related-model IDs:

```json
{
  "title": "My post",
  "related_ids": [2, 5]
}
```

On update, omitting `related_ids` leaves the existing assignments unchanged;
including it replaces them (an empty array clears them). A single additional
route can also replace assignments directly, for example
`PUT /api/posts/{post}/tags` with `{"related_ids":[2,5]}`. The response includes
the updated relation when assignments are supplied. The second model does not
receive duplicate association routes. Both Eloquent relationship methods
remain available for listing related records.

```php
(new \Franciscovillaquiran\LaravelRelationGenerator\Generators\ApiRouteGenerator())
    ->generate(base_path(), ['App\\Models\\Post', 'App\\Models\\Tag'], [
        [
            'parentModelClass' => 'App\\Models\\Post',
            'relatedModelClass' => 'App\\Models\\Tag',
            'relation' => 'tags',
        ],
    ]);
```

The API route generator is also available independently when CRUD controllers
already exist. The command's CRUD generator refuses to overwrite a manual
controller.

## Project documentation

For the detailed architecture, command flow, generated files, current
limitations, and possible next increments, see
[the project guide](docs/PROJECT_GUIDE.md).
