<?php

use Franciscovillaquiran\LaravelRelationGenerator\Generators\MigrationReader;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\ApiRouteGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\CrudGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\FileChangeTransaction;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\MigrationAnalysisException;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\MigrationParser;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaColumn;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaIndex;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaTable;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RelationshipGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;

$root = dirname(__DIR__);
if (!class_exists(\Illuminate\Support\Str::class)) {
    require_once __DIR__ . '/support/IlluminateStrStub.php';
}
foreach (glob($root . '/src/Generators/StructuralSchema/*.php') as $sourceFile) {
    require_once $sourceFile;
}
require_once $root . '/src/Generators/MigrationReader.php';
require_once $root . '/src/Generators/ModelSourceEditor.php';
require_once $root . '/src/Generators/FillableGenerator.php';
require_once $root . '/src/Generators/RelationshipGenerator.php';
require_once $root . '/src/Generators/RouteGenerator.php';
require_once $root . '/src/Generators/ApiRouteGenerator.php';
require_once $root . '/src/Generators/CrudGenerator.php';
require_once $root . '/src/Generators/FileChangeTransaction.php';
require_once $root . '/src/Relations/RelationDefinition.php';

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void {
    $tests[$name] = $callback;
};

$test('reads normalized columns, types, lengths, precision, nullability and defaults', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');
    $users = $schema->table('users');

    assertSameValue('string', $users->columns['display_name']->type);
    assertSameValue(160, $users->columns['display_name']->length);
    assertSameValue(false, $users->columns['display_name']->nullable);
    assertSameValue(10, $users->columns['balance']->precision);
    assertSameValue(2, $users->columns['balance']->scale);
    assertSameValue(0, $users->columns['balance']->default);
    assertSameValue(true, $users->columns['active']->default);
    assertSameValue('CURRENT_TIMESTAMP', $users->columns['last_seen']->default);
    assertSameValue(true, $users->columns['last_seen']->defaultIsExpression);
    assertSameValue(true, $users->columns['nickname']->nullable);
    assertSameValue(true, $users->columns['nickname']->hasDefault);
    assertSameValue(null, $users->columns['nickname']->default);
    assertSameValue(true, $users->columns['created_at']->nullable);
    assertSameValue(true, $users->columns['deleted_at']->nullable);
    assertSameValue(false, isset($users->columns['legacy_note']));
    assertSameValue(true, $users->columns['id']->autoIncrement);
    assertSameValue(true, $users->columns['id']->unsigned);
});

$test('analyzes the default Laravel users migration including rememberToken', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/laravel_default_users_migration.php');
    $users = $schema->table('users');
    $rememberToken = $users->columns['remember_token'];

    assertSameValue('string', $rememberToken->type);
    assertSameValue(100, $rememberToken->length);
    assertSameValue(true, $rememberToken->nullable);
    assertSameValue(true, $users->columns['email_verified_at']->nullable);
    assertSameValue(
        [
            'users',
            'personal_access_tokens',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'morph_variant_examples',
        ],
        array_keys($schema->tables)
    );
    assertSameValue(true, $schema->table('sessions')->columns['user_id']->nullable);
    assertSameValue(true, $schema->table('jobs')->columns['attempts']->unsigned);
    assertSameValue('CURRENT_TIMESTAMP', $schema->table('failed_jobs')->columns['failed_at']->default);

    $tokens = $schema->table('personal_access_tokens');
    assertSameValue('string', $tokens->columns['tokenable_type']->type);
    assertSameValue(255, $tokens->columns['tokenable_type']->length);
    assertSameValue('bigInteger', $tokens->columns['tokenable_id']->type);
    assertSameValue(true, $tokens->columns['tokenable_id']->unsigned);
    assertSameValue(
        ['tokenable_type', 'tokenable_id'],
        $tokens->indexes['personal_access_tokens_tokenable_type_tokenable_id_index']->columns
    );

    $morphVariants = $schema->table('morph_variant_examples');
    assertSameValue(true, $morphVariants->columns['nullable_numericable_type']->nullable);
    assertSameValue('uuid', $morphVariants->columns['uuidable_id']->type);
    assertSameValue('ulid', $morphVariants->columns['ulidable_id']->type);
    assertSameValue(true, $morphVariants->columns['nullable_ulidable_id']->nullable);
    assertSameValue(
        ['default_nullable_type', 'default_nullable_id'],
        $morphVariants->indexes['custom_default_nullable_index']->columns
    );

    $modelsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-default-users-' . bin2hex(random_bytes(6));
    if (!mkdir($modelsPath)) {
        throw new RuntimeException("Could not create temporary models directory: {$modelsPath}");
    }
    $modelPath = $modelsPath . DIRECTORY_SEPARATOR . 'User.php';
    try {
        file_put_contents($modelPath, "<?php\nclass User {}\n");
        (new \Franciscovillaquiran\LaravelRelationGenerator\Generators\FillableGenerator())
            ->generateFromTable($modelPath, $users, 'User');
        $modelSource = file_get_contents($modelPath);
        assertSameValue(false, str_contains($modelSource, 'remember_token'));
        assertSameValue(true, str_contains($modelSource, "'password'"));
    } finally {
        if (is_file($modelPath)) {
            unlink($modelPath);
        }
        rmdir($modelsPath);
    }
});

$test('reads unique and compound primary constraints and detects cardinalities', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');
    $users = $schema->table('users');
    $pivot = $schema->table('post_tag');

    assertSameValue(true, $users->hasUniqueColumns(['display_name']));
    assertSameValue(true, $users->hasUniqueColumns(['display_name', 'nickname']));
    assertSameValue('users_display_nickname_unique', $users->indexes['users_display_nickname_unique']->name);
    assertSameValue(true, $pivot->hasUniqueColumns(['post_id', 'tag_id']));
    assertSameValue(['post_id', 'tag_id'], $pivot->indexes['post_tag_post_id_tag_id_primary']->columns);
    assertSameValue(
        ['country_code', 'region_code'],
        $schema->table('localities')->foreignKeys['localities_region_fk']->columns
    );
    assertSameValue(
        ['country_code', 'region_code'],
        $schema->table('localities')->foreignKeys['localities_region_fk']->referencedColumns
    );

    $cardinalities = [];
    foreach ($schema->relations as $relation) {
        $cardinalities[$relation->sourceTable . ':' . $relation->targetTable] = $relation->cardinality;
        if ($relation->cardinality === 'N:M') {
            assertSameValue('post_tag', $relation->pivotTable);
        }
    }

    assertSameValue('1:1', $cardinalities['users:profiles']);
    assertSameValue('1:N', $cardinalities['users:posts']);
    assertSameValue('1:N', $cardinalities['regions:localities']);
    assertSameValue('N:M', $cardinalities['posts:tags']);
});

$test('recognizes conventional Laravel pivots with surrogate IDs and no unique pair index', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/laravel_conventional_pivot_with_id.php');
    $manyToMany = array_values(array_filter(
        $schema->relations,
        static fn ($relation): bool => $relation->cardinality === 'N:M'
    ));

    assertSameValue(1, count($manyToMany));
    assertSameValue('course_student', $manyToMany[0]->pivotTable);
    assertSameValue(false, $schema->table('course_student')->hasUniqueColumns(['course_id', 'student_id']));

    $modelsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-course-student-' . bin2hex(random_bytes(6));
    if (!mkdir($modelsPath)) {
        throw new RuntimeException("Could not create temporary models directory: {$modelsPath}");
    }

    $coursePath = $modelsPath . DIRECTORY_SEPARATOR . 'Course.php';
    $studentPath = $modelsPath . DIRECTORY_SEPARATOR . 'Student.php';
    try {
        file_put_contents($coursePath, "<?php\nnamespace App\\Models;\nclass Course {}\n");
        file_put_contents($studentPath, "<?php\nnamespace App\\Models;\nclass Student {}\n");
        (new RelationshipGenerator())->generateFromSchema(
            new RelationDefinition('Course', 'Student', 'N:M', modelATable: 'courses', modelBTable: 'students'),
            $schema,
            $modelsPath
        );

        assertSameValue(true, str_contains(
            file_get_contents($coursePath),
            "belongsToMany(\\App\\Models\\Student::class, 'course_student', 'course_id', 'student_id', 'id', 'id')"
        ));
        assertSameValue(true, str_contains(
            file_get_contents($studentPath),
            "belongsToMany(\\App\\Models\\Course::class, 'course_student', 'student_id', 'course_id', 'id', 'id')"
        ));
    } finally {
        foreach ([$coursePath, $studentPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($modelsPath);
    }
});

$test('reads foreign keys, referential policies and explicit index names', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');
    $foreignKey = $schema->table('profiles')->foreignKeys['profiles_user_foreign'];

    assertSameValue(['user_id'], $foreignKey->columns);
    assertSameValue('users', $foreignKey->referencedTable);
    assertSameValue(['id'], $foreignKey->referencedColumns);
    assertSameValue('cascade', $foreignKey->onDelete);
    assertSameValue('restrict', $foreignKey->onUpdate);

    $relation = null;
    foreach ($schema->relations as $candidate) {
        if ($candidate->foreignKey === $foreignKey->name) {
            $relation = $candidate;
            break;
        }
    }

    assertSameValue('users', $relation->sourceTable);
    assertSameValue('profiles', $relation->targetTable);
    assertSameValue('profiles', $relation->foreignKeyTable);
    assertSameValue('users', $relation->referencedTable);
});

$test('applies migrations in order and handles supported Schema::table changes', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');

    assertSameValue(
        [
            '2026_01_01_000001_create_base_tables.php',
            '2026_01_02_000001_create_post_tag.php',
            '2026_01_03_000001_alter_users.php',
        ],
        array_map('basename', $schema->migrationFiles)
    );
    assertSameValue(160, $schema->table('users')->columns['display_name']->length);
    assertSameValue(80, $schema->table('users')->columns['nickname']->length);
    assertSameValue('integer', $schema->table('users')->columns['legacy_code']->type);
    assertSameValue(null, $schema->table('users')->columns['legacy_code']->length);
    assertSameValue(false, isset($schema->table('users')->columns['temporary_value']));
});

$test('exposes normalized table and relation data for downstream consumers', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');
    $serialized = $schema->toArray();

    assertSameValue(
        ['name', 'columns', 'indexes', 'foreign_keys'],
        array_keys(reset($serialized['tables']))
    );
    assertSameValue(
        [
            'cardinality',
            'source_table',
            'source_columns',
            'target_table',
            'target_columns',
            'foreign_key',
            'pivot_table',
            'foreign_key_table',
            'foreign_key_columns',
            'referenced_table',
            'referenced_columns',
        ],
        array_keys($serialized['relations'][0])
    );
    assertSameValue(3, count($serialized['migration_files']));
});

$test('rejects dynamic, conditional and unsupported migration structures with context', static function () use ($root): void {
    foreach ([
        'ambiguous_dynamic_column.php',
        'ambiguous_conditional_schema.php',
        'ambiguous_unsupported_modifier.php',
        'ambiguous_chained_blueprint.php',
        'ambiguous_chained_schema.php',
    ] as $fixture) {
        try {
            (new MigrationReader())->analyze($root . '/tests/fixtures/' . $fixture);
            throw new RuntimeException("Expected {$fixture} to be rejected.");
        } catch (MigrationAnalysisException $exception) {
            assertSameValue($root . '/tests/fixtures/' . $fixture, $exception->migrationFile);
            assertSameValue(true, $exception->line > 0);
            assertSameValue(true, $exception->structure !== '');
            assertSameValue(true, $exception->unknownData !== '');
        }
    }
});

$test('resolves an aliased Schema facade and ignores down() operations', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/aliased_schema_facade.php');

    assertSameValue(['aliased_rows'], array_keys($schema->tables));
    assertSameValue(40, $schema->table('aliased_rows')->columns['label']->length);
});

$test('generates structurally oriented Eloquent relations and custom pivot keys', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');
    $modelsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-relations-' . bin2hex(random_bytes(6));
    if (!mkdir($modelsPath)) {
        throw new RuntimeException("Could not create temporary models directory: {$modelsPath}");
    }

    $sources = [
        'User' => "<?php\nnamespace App\\Models;\nclass User {\n    public function existing(): string { return 'kept'; }\n}\n",
        'Profile' => "<?php\nnamespace App\\Models;\nclass Profile {\n}\n",
        'Post' => "<?php\nnamespace App\\Models;\nclass Post {\n}\n",
        'Tag' => "<?php\nnamespace App\\Models;\nclass Tag {\n}\n",
        'Account' => "<?php\nnamespace App\\Models;\nclass Account {\n}\n",
        'Invoice' => "<?php\nnamespace App\\Models;\nclass Invoice {\n}\n",
        'LegacyParent' => "<?php\nnamespace App\\Models;\nclass LegacyParent {\n}\n",
        'LegacyChild' => "<?php\nnamespace App\\Models;\nclass LegacyChild {\n}\n",
    ];

    try {
        foreach ($sources as $model => $source) {
            if (file_put_contents($modelsPath . DIRECTORY_SEPARATOR . $model . '.php', $source) === false) {
                throw new RuntimeException("Could not create temporary model {$model}.");
            }
        }

        $generator = new RelationshipGenerator();
        $oneToOne = new RelationDefinition('User', 'Profile', '1:1', modelATable: 'users', modelBTable: 'profiles');
        $generator->generateFromSchema($oneToOne, $schema, $modelsPath);
        $userSource = file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'User.php');
        $profileSource = file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Profile.php');
        assertSameValue(true, str_contains($userSource, "hasOne(\\App\\Models\\Profile::class, 'user_id', 'id')"));
        assertSameValue(true, str_contains($profileSource, "belongsTo(\\App\\Models\\User::class, 'user_id', 'id')"));
        assertSameValue(true, str_contains($userSource, "public function existing(): string"));

        $beforeRepeat = $userSource;
        $generator->generateFromSchema($oneToOne, $schema, $modelsPath);
        assertSameValue($beforeRepeat, file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'User.php'));

        $oneToMany = new RelationDefinition('User', 'Post', '1:N', modelATable: 'users', modelBTable: 'posts');
        $generator->generateFromSchema($oneToMany, $schema, $modelsPath);
        assertSameValue(
            true,
            str_contains(
                file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'User.php'),
                "hasMany(\\App\\Models\\Post::class, 'user_id', 'id')"
            )
        );
        assertSameValue(
            true,
            str_contains(
                file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Post.php'),
                "belongsTo(\\App\\Models\\User::class, 'user_id', 'id')"
            )
        );

        $manyToMany = new RelationDefinition(
            'Post',
            'Tag',
            'N:M',
            modelATable: 'posts',
            modelBTable: 'tags'
        );
        $generator->generateFromSchema($manyToMany, $schema, $modelsPath);
        assertSameValue(
            true,
            str_contains(
                file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Post.php'),
                "belongsToMany(\\App\\Models\\Tag::class, 'post_tag', 'post_id', 'tag_id', 'id', 'id')"
            )
        );
        assertSameValue(
            true,
            str_contains(
                file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Tag.php'),
                "belongsToMany(\\App\\Models\\Post::class, 'post_tag', 'tag_id', 'post_id', 'id', 'id')"
            )
        );

        $customMigration = $modelsPath . DIRECTORY_SEPARATOR . 'custom_keys.php';
        file_put_contents($customMigration, <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->string('code')->unique();
        });
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('account_ref');
            $table->foreign('account_ref')->references('code')->on('accounts');
        });
    }
};
PHP);
        $customSchema = (new MigrationReader())->analyze($customMigration);
        $generator->generateFromSchema(
            new RelationDefinition('Account', 'Invoice', '1:N', modelATable: 'accounts', modelBTable: 'invoices'),
            $customSchema,
            $modelsPath
        );
        assertSameValue(
            true,
            str_contains(
                file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Account.php'),
                "hasMany(\\App\\Models\\Invoice::class, 'account_ref', 'code')"
            )
        );
        assertSameValue(
            true,
            str_contains(
                file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Invoice.php'),
                "belongsTo(\\App\\Models\\Account::class, 'account_ref', 'code')"
            )
        );

        $generator->generate(
            new RelationDefinition(
                'LegacyParent',
                'LegacyChild',
                '1:N',
                foreignTable: 'legacy_children'
            ),
            $modelsPath
        );
        $legacySource = file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'LegacyParent.php');
        assertSameValue(true, str_contains($legacySource, 'hasMany(\\App\\Models\\LegacyChild::class)'));
        $generator->generate(
            new RelationDefinition(
                'LegacyParent',
                'LegacyChild',
                '1:N',
                foreignTable: 'legacy_children'
            ),
            $modelsPath
        );
        assertSameValue($legacySource, file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'LegacyParent.php'));
    } finally {
        foreach (glob($modelsPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $model) {
            unlink($model);
        }
        rmdir($modelsPath);
    }
});

$test('rejects wrong cardinalities and ambiguous foreign keys before editing models', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');
    $modelsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-ambiguous-' . bin2hex(random_bytes(6));
    if (!mkdir($modelsPath)) {
        throw new RuntimeException("Could not create temporary models directory: {$modelsPath}");
    }

    $organizationModel = "<?php\nnamespace App\\Models;\nclass Organization {\n}\n";
    $projectModel = "<?php\nnamespace App\\Models;\nclass Project {\n}\n";
    $userModel = "<?php\nnamespace App\\Models;\nclass User {\n    public function posts() { return 'manual'; }\n}\n";
    $postModel = "<?php\nnamespace App\\Models;\nclass Post {\n}\n";
    $regionModel = "<?php\nnamespace App\\Models;\nclass Region {\n}\n";
    $localityModel = "<?php\nnamespace App\\Models;\nclass Locality {\n}\n";
    file_put_contents($modelsPath . DIRECTORY_SEPARATOR . 'Organization.php', $organizationModel);
    file_put_contents($modelsPath . DIRECTORY_SEPARATOR . 'Project.php', $projectModel);
    file_put_contents($modelsPath . DIRECTORY_SEPARATOR . 'User.php', $userModel);
    file_put_contents($modelsPath . DIRECTORY_SEPARATOR . 'Post.php', $postModel);
    file_put_contents($modelsPath . DIRECTORY_SEPARATOR . 'Region.php', $regionModel);
    file_put_contents($modelsPath . DIRECTORY_SEPARATOR . 'Locality.php', $localityModel);

    try {
        $generator = new RelationshipGenerator();
        try {
            $generator->generateFromSchema(
                new RelationDefinition('User', 'Post', '1:1', modelATable: 'users', modelBTable: 'posts'),
                $schema,
                $modelsPath
            );
            throw new RuntimeException('Expected an incompatible one-to-one relationship to fail.');
        } catch (RuntimeException $exception) {
            assertSameValue(true, str_contains($exception->getMessage(), 'Could not resolve'));
        }

        $ambiguousMigration = $modelsPath . DIRECTORY_SEPARATOR . '2026_01_01_000001_create_ambiguous.php';
        file_put_contents($ambiguousMigration, <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class {
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('organizations');
            $table->foreignId('reviewer_id')->constrained('organizations');
        });
    }
};
PHP);
        $ambiguousSchema = (new MigrationReader())->analyze($ambiguousMigration);
        try {
            $generator->generateFromSchema(
                new RelationDefinition(
                    'Organization',
                    'Project',
                    '1:N',
                    modelATable: 'organizations',
                    modelBTable: 'projects'
                ),
                $ambiguousSchema,
                $modelsPath
            );
            throw new RuntimeException('Expected multiple foreign keys to be reported as ambiguous.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'multiple possible relationships')) {
                throw new RuntimeException('Unexpected ambiguity error: ' . $exception->getMessage());
            }
        }

        assertSameValue($organizationModel, file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Organization.php'));
        assertSameValue($projectModel, file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Project.php'));
        assertSameValue($userModel, file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'User.php'));
        assertSameValue($postModel, file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Post.php'));

        try {
            $generator->generateFromSchema(
                new RelationDefinition('User', 'Post', '1:N', modelATable: 'users', modelBTable: 'posts'),
                $schema,
                $modelsPath
            );
            throw new RuntimeException('Expected an existing relationship method to conflict.');
        } catch (RuntimeException $exception) {
            assertSameValue(true, str_contains($exception->getMessage(), 'conflicting posts()'));
        }
        assertSameValue($postModel, file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Post.php'));

        try {
            $generator->generateFromSchema(
                new RelationDefinition(
                    'Region',
                    'Locality',
                    '1:N',
                    modelATable: 'regions',
                    modelBTable: 'localities'
                ),
                $schema,
                $modelsPath
            );
            throw new RuntimeException('Expected composite foreign keys to be rejected by Eloquent generation.');
        } catch (RuntimeException $exception) {
            assertSameValue(true, str_contains($exception->getMessage(), 'composite foreign key'));
        }
        assertSameValue($regionModel, file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Region.php'));
        assertSameValue($localityModel, file_get_contents($modelsPath . DIRECTORY_SEPARATOR . 'Locality.php'));
    } finally {
        foreach (glob($modelsPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $model) {
            unlink($model);
        }
        rmdir($modelsPath);
    }
});

$test('generates fillable safely from schema and protects manual configuration', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');
    $modelsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-fillable-' . bin2hex(random_bytes(6));
    if (!mkdir($modelsPath)) {
        throw new RuntimeException("Could not create temporary models directory: {$modelsPath}");
    }

    $profileModel = "<?php\nnamespace App\\Models;\nclass Profile {\n    protected \$fillable = ['manual_note', 'user_id'];\n    public function existing(): string { return 'kept'; }\n}\n";
    try {
        $modelPath = $modelsPath . DIRECTORY_SEPARATOR . 'Profile.php';
        file_put_contents($modelPath, $profileModel);
        $generator = new \Franciscovillaquiran\LaravelRelationGenerator\Generators\FillableGenerator();
        try {
            $generator->generateFromTable($modelPath, $schema->table('profiles'));
            throw new RuntimeException('Expected an incomplete manual fillable allowlist to conflict.');
        } catch (RuntimeException $exception) {
            assertSameValue(true, str_contains($exception->getMessage(), 'manual $fillable allowlist'));
            assertSameValue($profileModel, file_get_contents($modelPath));
        }

        $automaticModel = "<?php\nnamespace App\\Models;\nclass AutomaticProfile {\n    public function existing(): string { return 'kept'; }\n}\n";
        $automaticPath = $modelsPath . DIRECTORY_SEPARATOR . 'AutomaticProfile.php';
        file_put_contents($automaticPath, $automaticModel);
        $generator->generateFromTable($automaticPath, $schema->table('profiles'));

        $generated = file_get_contents($automaticPath);
        assertSameValue(true, str_contains($generated, "'biography'"));
        assertSameValue(true, str_contains($generated, "'user_id'"));
        assertSameValue(false, str_contains($generated, "'id'"));
        assertSameValue(true, str_contains($generated, "public function existing(): string"));
        assertSameValue(1, substr_count($generated, "'user_id'"));
        $generator->generateFromTable($automaticPath, $schema->table('profiles'));
        assertSameValue($generated, file_get_contents($automaticPath));

        $userPath = $modelsPath . DIRECTORY_SEPARATOR . 'User.php';
        file_put_contents($userPath, "<?php\nclass User {\n}\n");
        $generator->generateFromTable($userPath, $schema->table('users'));
        $userSource = file_get_contents($userPath);
        assertSameValue(true, str_contains($userSource, "'display_name'"));
        assertSameValue(true, str_contains($userSource, "'legacy_code'"));
        foreach (['id', 'created_at', 'updated_at', 'deleted_at'] as $excluded) {
            assertSameValue(false, str_contains($userSource, var_export($excluded, true)));
        }

        $compatiblePath = $modelsPath . DIRECTORY_SEPARATOR . 'CompatibleProfile.php';
        $compatibleSource = "<?php\nclass CompatibleProfile {\n    protected \$fillable = ['user_id', 'biography', 'manual_note'];\n}\n";
        file_put_contents($compatiblePath, $compatibleSource);
        $generator->generateFromTable($compatiblePath, $schema->table('profiles'));
        assertSameValue($compatibleSource, file_get_contents($compatiblePath));

        $legacyPath = $modelsPath . DIRECTORY_SEPARATOR . 'LegacyFillable.php';
        file_put_contents($legacyPath, "<?php\nclass LegacyFillable {\n}\n");
        $generator->generate($legacyPath, ['id', 'name', 'name', 'created_at']);
        $legacyFillable = file_get_contents($legacyPath);
        assertSameValue(true, str_contains($legacyFillable, "'name'"));
        assertSameValue(false, str_contains($legacyFillable, "'id'"));
        assertSameValue(1, substr_count($legacyFillable, "'name'"));

        $guardedPath = $modelsPath . DIRECTORY_SEPARATOR . 'Guarded.php';
        $guardedSource = "<?php\nclass Guarded {\n    protected \$guarded = [];\n}\n";
        file_put_contents($guardedPath, $guardedSource);
        try {
            $generator->generate($guardedPath, ['field']);
            throw new RuntimeException('Expected $guarded to conflict with generated fillable.');
        } catch (RuntimeException $exception) {
            assertSameValue(true, str_contains($exception->getMessage(), '$guarded'));
            assertSameValue($guardedSource, file_get_contents($guardedPath));
        }

        $computedPath = $modelsPath . DIRECTORY_SEPARATOR . 'Computed.php';
        $computedSource = "<?php\nclass Computed {\n    protected \$fillable = self::FIELDS;\n}\n";
        file_put_contents($computedPath, $computedSource);
        try {
            $generator->generate($computedPath, ['field']);
            throw new RuntimeException('Expected computed fillable configuration to be rejected.');
        } catch (RuntimeException $exception) {
            assertSameValue(true, str_contains($exception->getMessage(), 'computed'));
            assertSameValue($computedSource, file_get_contents($computedPath));
        }
    } finally {
        foreach (glob($modelsPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $model) {
            unlink($model);
        }
        rmdir($modelsPath);
    }
});

$test('generates simple idempotent CRUD controllers and protects manual controllers', static function () use ($root): void {
    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-crud-' . bin2hex(random_bytes(6));
    if (!mkdir($projectRoot)) {
        throw new RuntimeException("Could not create temporary CRUD project: {$projectRoot}");
    }

    try {
        $generator = new CrudGenerator();
        $posts = $schema->table('posts');
        $controllerPath = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api'
            . DIRECTORY_SEPARATOR . 'PostController.php';

        assertSameValue(true, $generator->generateFromTable($projectRoot, 'App\\Models\\Post', $posts, $schema));
        $source = file_get_contents($controllerPath);
        token_get_all($source, TOKEN_PARSE);
        assertSameValue(true, str_contains($source, 'public function index()'));
        assertSameValue(true, str_contains($source, 'public function store(Request $request)'));
        assertSameValue(true, str_contains($source, 'public function show(string $id)'));
        assertSameValue(true, str_contains($source, 'public function update(Request $request, string $id)'));
        assertSameValue(true, str_contains($source, 'public function destroy(string $id)'));
        assertSameValue(true, str_contains($source, 'Post::create($request->all())'));
        assertSameValue(true, str_contains($source, 'Post::findOrFail($id)'));
        assertSameValue(true, str_contains($source, '$record->update($request->all())'));
        assertSameValue(true, str_contains($source, '$record->delete()'));
        assertSameValue(false, str_contains($source, 'validate('));
        assertSameValue(false, str_contains($source, 'QueryException'));
        assertSameValue(false, str_contains($source, 'Rule::'));
        assertSameValue(false, $generator->generateFromTable($projectRoot, 'App\\Models\\Post', $posts, $schema));
        assertSameValue($source, file_get_contents($controllerPath));

        $usersController = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api'
            . DIRECTORY_SEPARATOR . 'UserController.php';
        $generator->generateFromTable($projectRoot, 'App\\Models\\User', $schema->table('users'), $schema);
        $usersSource = file_get_contents($usersController);
        token_get_all($usersSource, TOKEN_PARSE);
        assertSameValue(false, str_contains($usersSource, 'rules('));

        $manualSource = $source . "// manual edit\n";
        file_put_contents($controllerPath, $manualSource);
        try {
            $generator->generateFromTable($projectRoot, 'App\\Models\\Post', $posts, $schema);
            throw new RuntimeException('Expected an edited generated controller to be protected.');
        } catch (RuntimeException $exception) {
            assertSameValue(true, str_contains($exception->getMessage(), 'already exists'));
            assertSameValue($manualSource, file_get_contents($controllerPath));
        }

        $pivotController = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api'
            . DIRECTORY_SEPARATOR . 'PostTagController.php';
        assertSameValue(
            true,
            $generator->generateFromTable(
                $projectRoot,
                'App\\Models\\PostTag',
                $schema->table('post_tag'),
                $schema
            )
        );
        assertSameValue(true, is_file($pivotController));

        $binaryTable = new SchemaTable('binary_records', [
            'id' => new SchemaColumn('id', 'bigInteger', autoIncrement: true),
            'payload' => new SchemaColumn('payload', 'binary'),
        ], [
            'binary_records_primary' => new SchemaIndex('binary_records_primary', 'primary', ['id'], true),
        ]);
        $binaryController = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api'
            . DIRECTORY_SEPARATOR . 'BinaryRecordController.php';
        assertSameValue(
            true,
            $generator->generateFromTable(
                $projectRoot,
                'App\\Models\\BinaryRecord',
                $binaryTable,
                new \Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\DatabaseSchema()
            )
        );
        assertSameValue(true, is_file($binaryController));
    } finally {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($projectRoot);
    }
});

$test('rolls back all watched files after a controlled write failure', static function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-transaction-' . bin2hex(random_bytes(6));
    if (!mkdir($root)) {
        throw new RuntimeException("Could not create transaction test directory: {$root}");
    }
    $first = $root . DIRECTORY_SEPARATOR . 'first.php';
    $second = $root . DIRECTORY_SEPARATOR . 'second.php';
    $new = $root . DIRECTORY_SEPARATOR . 'new.php';
    $index = $root . DIRECTORY_SEPARATOR . 'index.json';
    file_put_contents($first, '<?php $value = 1;');
    file_put_contents($second, '<?php $value = 2;');
    file_put_contents($index, '{"schema":"original"}');
    $writes = 0;
    $transaction = new FileChangeTransaction(static function () use (&$writes): void {
        if (++$writes === 3) {
            throw new RuntimeException('controlled write failure');
        }
    });

    try {
        $transaction->watch([$first, $second, $new, $index]);
        try {
            $transaction->apply([
                $first => '<?php $value = 10;',
                $new => '<?php $value = 30;',
                $second => '<?php $value = 20;',
            ]);
            throw new RuntimeException('Expected the controlled write to fail.');
        } catch (RuntimeException $exception) {
            assertSameValue('controlled write failure', $exception->getMessage());
        }
        assertSameValue('<?php $value = 1;', file_get_contents($first));
        assertSameValue('<?php $value = 2;', file_get_contents($second));
        assertSameValue('{"schema":"original"}', file_get_contents($index));
        assertSameValue(false, file_exists($new));
        assertSameValue([], glob($root . DIRECTORY_SEPARATOR . '.lrg-backup-*'));
    } finally {
        foreach (glob($root . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($root);
    }
});

$test('persists and incrementally refreshes the structural migration index', static function () use ($root): void {
    $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-index-' . bin2hex(random_bytes(6));
    $migrationDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'migrations';
    $indexDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . '.lrg';
    $indexPath = $indexDirectory . DIRECTORY_SEPARATOR . 'lrg-index.json';

    if (!mkdir($migrationDirectory, 0777, true)) {
        throw new RuntimeException("Could not create test migration directory: {$migrationDirectory}");
    }

    try {
        foreach (glob($root . '/tests/fixtures/migrations/*.php') as $fixture) {
            if (!copy($fixture, $migrationDirectory . DIRECTORY_SEPARATOR . basename($fixture))) {
                throw new RuntimeException("Could not copy migration fixture: {$fixture}");
            }
        }

        $deferredIndexPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'deferred' . DIRECTORY_SEPARATOR . 'index.json';
        (new MigrationReader())->analyze($migrationDirectory, $deferredIndexPath, false);
        assertSameValue(false, file_exists($deferredIndexPath));

        $parser = new class extends MigrationParser {
            public int $calls = 0;

            public function parse(string $file): array
            {
                $this->calls++;
                return parent::parse($file);
            }
        };
        $reader = new MigrationReader($parser);
        $firstSchema = $reader->analyze($migrationDirectory, $indexPath);

        assertSameValue(3, $parser->calls);
        assertSameValue(true, is_file($indexPath));
        $initialIndexJson = file_get_contents($indexPath);
        $initialIndex = json_decode($initialIndexJson, true, 512, JSON_THROW_ON_ERROR);
        assertSameValue(1, $initialIndex['format_version']);
        assertSameValue(3, count($initialIndex['migrations']));
        assertSameValue(array_keys($firstSchema->tables), array_keys($initialIndex['schema']['tables']));
        assertSameValue(
            array_map(static fn ($relation): array => $relation->toArray(), $firstSchema->relations),
            $initialIndex['schema']['relations']
        );
        assertSameValue(
            array_map('basename', $firstSchema->migrationFiles),
            array_map('basename', $initialIndex['schema']['migration_files'])
        );
        foreach ($initialIndex['migrations'] as $migration) {
            assertSameValue(
                hash_file('sha256', $migrationDirectory . DIRECTORY_SEPARATOR . basename($migration['path'])),
                $migration['sha256']
            );
        }

        $unchangedSchema = $reader->analyze($migrationDirectory, $indexPath);
        assertSameValue(3, $parser->calls);
        assertSameValue($firstSchema->toArray(), $unchangedSchema->toArray());

        $alterMigration = $migrationDirectory . DIRECTORY_SEPARATOR . '2026_01_03_000001_alter_users.php';
        $alterContents = file_get_contents($alterMigration);
        $modifiedContents = str_replace(
            "\$table->string('name', 160)->change()",
            "\$table->string('name', 140)->change()",
            $alterContents
        );
        if ($modifiedContents === $alterContents || file_put_contents($alterMigration, $modifiedContents) === false) {
            throw new RuntimeException('Could not modify the migration fixture for the index test.');
        }

        $modifiedSchema = $reader->analyze($migrationDirectory, $indexPath);
        assertSameValue(4, $parser->calls);
        assertSameValue(140, $modifiedSchema->table('users')->columns['display_name']->length);

        $newMigration = $migrationDirectory . DIRECTORY_SEPARATOR . '2026_01_04_000001_add_indexed_flag.php';
        $newMigrationContents = <<<'PHP'
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('indexed_flag', 12)->nullable();
        });
    }
};
PHP;
        if (file_put_contents($newMigration, $newMigrationContents) === false) {
            throw new RuntimeException('Could not create a new migration fixture.');
        }

        $addedSchema = $reader->analyze($migrationDirectory, $indexPath);
        assertSameValue(5, $parser->calls);
        assertSameValue(true, isset($addedSchema->table('users')->columns['indexed_flag']));
        assertSameValue(
            $addedSchema->toArray(),
            (new MigrationReader())->analyze($migrationDirectory)->toArray()
        );

        $renamedMigration = $migrationDirectory . DIRECTORY_SEPARATOR . '2026_01_05_000001_add_indexed_flag.php';
        if (!rename($newMigration, $renamedMigration)) {
            throw new RuntimeException('Could not rename a migration fixture.');
        }
        $renamedSchema = $reader->analyze($migrationDirectory, $indexPath);
        assertSameValue(6, $parser->calls);

        $deletedMigration = $migrationDirectory . DIRECTORY_SEPARATOR . '2026_01_02_000001_create_post_tag.php';
        if (!unlink($deletedMigration)) {
            throw new RuntimeException('Could not delete a migration fixture.');
        }
        $deletedSchema = $reader->analyze($migrationDirectory, $indexPath);
        assertSameValue(6, $parser->calls);
        assertSameValue([], array_values(array_filter(
            $deletedSchema->relations,
            static fn ($relation): bool => $relation->cardinality === 'N:M'
        )));
        assertSameValue(
            $deletedSchema->toArray(),
            (new MigrationReader())->analyze($migrationDirectory)->toArray()
        );

        $badMigration = $migrationDirectory . DIRECTORY_SEPARATOR . '2026_01_09_000001_ambiguous.php';
        if (!copy($root . '/tests/fixtures/ambiguous_dynamic_column.php', $badMigration)) {
            throw new RuntimeException('Could not create an ambiguous migration fixture.');
        }
        $validIndexContents = file_get_contents($indexPath);
        try {
            $reader->analyze($migrationDirectory, $indexPath);
            throw new RuntimeException('Expected an ambiguous migration to fail without replacing the index.');
        } catch (MigrationAnalysisException) {
            assertSameValue($validIndexContents, file_get_contents($indexPath));
        }
        unlink($badMigration);

        if (file_put_contents($indexPath, '{"damaged":') === false) {
            throw new RuntimeException('Could not damage the structural index fixture.');
        }
        $recoveredSchema = $reader->analyze($migrationDirectory, $indexPath);
        assertSameValue(10, $parser->calls);
        assertSameValue(
            $deletedSchema->toArray(),
            $recoveredSchema->toArray()
        );
        assertSameValue(
            $recoveredSchema->toArray(),
            (new MigrationReader())->analyze($migrationDirectory)->toArray()
        );
        json_decode(file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR);
        assertSameValue([], glob($indexPath . '.*.tmp'));
        assertSameValue(false, in_array(
            '2026_01_02_000001_create_post_tag.php',
            array_map('basename', array_column(
                json_decode(file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR)['migrations'],
                'path'
            )),
            true
        ));
        assertSameValue(
            true,
            in_array(
                '2026_01_05_000001_add_indexed_flag.php',
                array_map('basename', array_column(
                    json_decode(file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR)['migrations'],
                    'path'
                )),
                true
            )
        );
    } finally {
        foreach (glob($migrationDirectory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $migration) {
            unlink($migration);
        }
        if (is_file($indexPath)) {
            unlink($indexPath);
        }
        if (is_dir($indexDirectory)) {
            rmdir($indexDirectory);
        }
        rmdir($migrationDirectory);
        rmdir($temporaryRoot);
    }
});

$test('preserves the legacy getColumns result', static function () use ($root): void {
    $columns = (new MigrationReader())->getColumns(
        $root . '/tests/fixtures/migrations/2026_01_01_000001_create_base_tables.php'
    );

    assertSameValue(true, in_array('id', $columns, true));
    assertSameValue(true, in_array('name', $columns, true));
    assertSameValue(true, in_array('user_id', $columns, true));
    assertSameValue(true, in_array('created_at', $columns, true));
    assertSameValue(true, in_array('deleted_at', $columns, true));
});

$test('preserves existing relationship generator naming behavior', static function (): void {
    $generator = new RelationshipGenerator();

    assertSameValue(
        ['relationA' => 'profiles', 'relationB' => 'user'],
        $generator->relationNames(new RelationDefinition('User', 'Profile', '1:N'))
    );
    assertSameValue(
        ['relationA' => 'tag', 'relationB' => 'post'],
        $generator->relationNames(new RelationDefinition('Post', 'Tag', '1:1'))
    );
});

$test('generates API resources without changing web routes and protects manual routes', static function (): void {
    $projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-api-routes-' . bin2hex(random_bytes(6));
    $routesPath = $projectRoot . DIRECTORY_SEPARATOR . 'routes';
    $controllersPath = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
        . 'Http' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api';
    $bootstrapPath = $projectRoot . DIRECTORY_SEPARATOR . 'bootstrap';
    foreach ([$routesPath, $controllersPath, $bootstrapPath] as $directory) {
        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create API route fixture directory: {$directory}");
        }
    }

    $apiRoutesPath = $routesPath . DIRECTORY_SEPARATOR . 'api.php';
    $managedRoutesPath = $routesPath . DIRECTORY_SEPARATOR . 'lrg_api.php';
    $webRoutesPath = $routesPath . DIRECTORY_SEPARATOR . 'web.php';
    file_put_contents(
        $bootstrapPath . DIRECTORY_SEPARATOR . 'app.php',
        "<?php\nreturn Application::configure(basePath: dirname(__DIR__))"
            . "->withRouting(api: __DIR__ . '/../routes/api.php')->create();\n"
    );
    $apiOriginal = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n"
        . "Route::get('/health', fn () => 'ok');\n"
        . "Route::match(['OPTIONS'], '/users', fn () => null);\n";
    $managedManualOriginal = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/local-health', fn () => 'ok');\n";
    $webOriginal = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => 'home');\n";
    file_put_contents($apiRoutesPath, $apiOriginal);
    file_put_contents($managedRoutesPath, $managedManualOriginal);
    file_put_contents($webRoutesPath, $webOriginal);

    $controllerTemplate = <<<'PHP'
<?php

namespace App\Http\Controllers\Api;

class %sController
{
    public function index() {}
    public function store() {}
    public function show() {}
    public function update() {}
    public function destroy() {}
}
PHP;
    foreach (['User', 'BlogPost'] as $model) {
        file_put_contents(
            $controllersPath . DIRECTORY_SEPARATOR . $model . 'Controller.php',
            sprintf($controllerTemplate, $model)
        );
    }

    try {
        $generator = new ApiRouteGenerator();
        $generator->generate($projectRoot, ['App\\Models\\User']);
        $generator->generate($projectRoot, ['App\\Models\\User', 'App\\Models\\BlogPost']);
        $managedSource = file_get_contents($managedRoutesPath);
        $apiSource = file_get_contents($apiRoutesPath);

        assertRouteContains('use Illuminate\\Support\\Facades\\Route;', $managedSource);
        assertRouteContains(
            "Route::apiResource('users', \\App\\Http\\Controllers\\Api\\UserController::class);",
            $managedSource
        );
        assertRouteContains(
            "Route::apiResource('blog_posts', \\App\\Http\\Controllers\\Api\\BlogPostController::class);",
            $managedSource
        );
        assertRouteContains("Route::get('/local-health'", $managedSource);
        assertRouteContains("Route::get('/health'", $apiSource);
        assertSameValue(1, substr_count($apiSource, 'lrg_api.php'));

        $generatedApi = $apiSource;
        $generatedManaged = $managedSource;
        $generator->generate($projectRoot, ['App\\Models\\User', 'App\\Models\\BlogPost']);
        assertSameValue($generatedApi, file_get_contents($apiRoutesPath));
        assertSameValue($generatedManaged, file_get_contents($managedRoutesPath));
        assertSameValue($webOriginal, file_get_contents($webRoutesPath));

        (new Franciscovillaquiran\LaravelRelationGenerator\Generators\RouteGenerator())->generate(
            $webRoutesPath,
            'User',
            'BlogPost'
        );
        $webWithLegacyRoutes = file_get_contents($webRoutesPath);
        assertRouteContains('/consultas/user-blog-post', $webWithLegacyRoutes);
        $generator->generate($projectRoot, ['App\\Models\\User', 'App\\Models\\BlogPost']);
        assertSameValue($webWithLegacyRoutes, file_get_contents($webRoutesPath));

        file_put_contents(
            $apiRoutesPath,
            rtrim(file_get_contents($apiRoutesPath))
                . "\nRoute::delete('/users/{user}', fn () => null);\n"
        );
        $beforeConflict = file_get_contents($managedRoutesPath);
        try {
            $generator->generate($projectRoot, ['App\\Models\\User']);
            throw new RuntimeException('Expected an overlapping API route to be rejected.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Route conflict')) {
                throw new RuntimeException('Unexpected API conflict result: ' . $exception->getMessage());
            }
            assertSameValue($beforeConflict, file_get_contents($managedRoutesPath));
        }

        $apiWithoutUriConflict = str_replace(
            "\nRoute::delete('/users/{user}', fn () => null);\n",
            "\n",
            file_get_contents($apiRoutesPath)
        );
        file_put_contents(
            $apiRoutesPath,
            rtrim($apiWithoutUriConflict) . "\nRoute::get('/other')->name('users.index');\n"
        );
        try {
            $generator->generate($projectRoot, ['App\\Models\\User']);
            throw new RuntimeException('Expected an API route name conflict to be rejected.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Route name conflict')) {
                throw new RuntimeException('Unexpected API route name result: ' . $exception->getMessage());
            }
        }

        file_put_contents(
            $controllersPath . DIRECTORY_SEPARATOR . 'PrivateController.php',
            str_replace('public function destroy()', 'private function destroy()', sprintf($controllerTemplate, 'Private'))
        );
        try {
            $generator->generate($projectRoot, ['App\\Models\\Private']);
            throw new RuntimeException('Expected a controller missing a public action to be rejected.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'missing public actions')) {
                throw new RuntimeException('Unexpected controller validation result: ' . $exception->getMessage());
            }
        }

        $disabledRoot = $projectRoot . DIRECTORY_SEPARATOR . 'api-disabled';
        mkdir($disabledRoot . DIRECTORY_SEPARATOR . 'routes', 0777, true);
        mkdir($disabledRoot . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        file_put_contents($disabledRoot . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php', "<?php\n");
        file_put_contents($disabledRoot . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php', "<?php\nreturn Application::configure()->withRouting(web: 'routes/web.php')->create();");
        try {
            $generator->generate($disabledRoot, ['App\\Models\\User']);
            throw new RuntimeException('Expected an unregistered API routes file to be rejected.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Could not verify')) {
                throw new RuntimeException('Unexpected API registration result: ' . $exception->getMessage());
            }
            assertSameValue(false, file_exists($disabledRoot . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'lrg_api.php'));
        }

        $customRoutingRoot = $projectRoot . DIRECTORY_SEPARATOR . 'custom-routing';
        mkdir($customRoutingRoot . DIRECTORY_SEPARATOR . 'routes', 0777, true);
        mkdir($customRoutingRoot . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        file_put_contents($customRoutingRoot . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php', "<?php\n");
        file_put_contents(
            $customRoutingRoot . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\nApplication::configure()->withRouting(using: function () {}, api: __DIR__ . '/../routes/api.php');\n"
        );
        try {
            $generator->generate($customRoutingRoot, ['App\\Models\\User']);
            throw new RuntimeException('Expected custom Laravel routing to be rejected as ambiguous.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Could not verify')) {
                throw new RuntimeException('Unexpected custom routing result: ' . $exception->getMessage());
            }
            assertSameValue(false, file_exists($customRoutingRoot . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'lrg_api.php'));
        }

        $providerRoot = $projectRoot . DIRECTORY_SEPARATOR . 'laravel-10';
        $providerRoutes = $providerRoot . DIRECTORY_SEPARATOR . 'routes';
        $providerDirectory = $providerRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Providers';
        $providerControllers = $providerRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
            . 'Http' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api';
        mkdir($providerRoutes, 0777, true);
        mkdir($providerDirectory, 0777, true);
        mkdir($providerControllers, 0777, true);
        file_put_contents($providerRoutes . DIRECTORY_SEPARATOR . 'api.php', "<?php\n");
        file_put_contents(
            $providerDirectory . DIRECTORY_SEPARATOR . 'RouteServiceProvider.php',
            "<?php\nRoute::middleware('api')->prefix('api')->group(base_path('routes/api.php'));\n"
        );
        file_put_contents(
            $providerControllers . DIRECTORY_SEPARATOR . 'UserController.php',
            sprintf($controllerTemplate, 'User')
        );
        $generator->generate($providerRoot, ['App\\Models\\User']);
        assertSameValue(
            true,
            file_exists($providerRoutes . DIRECTORY_SEPARATOR . 'lrg_api.php')
        );
    } finally {
        foreach (glob($controllersPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $controller) {
            unlink($controller);
        }
        foreach ([$apiRoutesPath, $managedRoutesPath, $webRoutesPath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_file($bootstrapPath . DIRECTORY_SEPARATOR . 'app.php')) {
            unlink($bootstrapPath . DIRECTORY_SEPARATOR . 'app.php');
        }
        foreach ([
            $controllersPath,
            dirname($controllersPath),
            dirname(dirname($controllersPath)),
            dirname(dirname(dirname($controllersPath))),
            $bootstrapPath,
            $routesPath,
        ] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        $disabledRoutes = $projectRoot . DIRECTORY_SEPARATOR . 'api-disabled' . DIRECTORY_SEPARATOR . 'routes';
        if (is_file($disabledRoutes . DIRECTORY_SEPARATOR . 'api.php')) {
            unlink($disabledRoutes . DIRECTORY_SEPARATOR . 'api.php');
        }
        if (is_dir($disabledRoutes)) {
            rmdir($disabledRoutes);
        }
        $disabledBootstrap = dirname($disabledRoutes) . DIRECTORY_SEPARATOR . 'bootstrap';
        if (is_file($disabledBootstrap . DIRECTORY_SEPARATOR . 'app.php')) {
            unlink($disabledBootstrap . DIRECTORY_SEPARATOR . 'app.php');
        }
        if (is_dir($disabledBootstrap)) {
            rmdir($disabledBootstrap);
        }
        if (is_dir(dirname($disabledRoutes))) {
            rmdir(dirname($disabledRoutes));
        }
        $customRoutingRoot = $projectRoot . DIRECTORY_SEPARATOR . 'custom-routing';
        foreach ([
            $customRoutingRoot . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php',
            $customRoutingRoot . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
        ] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ([
            $customRoutingRoot . DIRECTORY_SEPARATOR . 'routes',
            $customRoutingRoot . DIRECTORY_SEPARATOR . 'bootstrap',
            $customRoutingRoot,
        ] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        if (isset($providerRoutes)) {
            foreach ([
                $providerRoutes . DIRECTORY_SEPARATOR . 'api.php',
                $providerRoutes . DIRECTORY_SEPARATOR . 'lrg_api.php',
                $providerDirectory . DIRECTORY_SEPARATOR . 'RouteServiceProvider.php',
                $providerControllers . DIRECTORY_SEPARATOR . 'UserController.php',
            ] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach ([
                $providerControllers,
                dirname($providerControllers),
                dirname(dirname($providerControllers)),
                $providerDirectory,
                $providerRoot . DIRECTORY_SEPARATOR . 'app',
                $providerRoutes,
                $providerRoot,
            ] as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
        }
        if (is_dir($projectRoot)) {
            rmdir($projectRoot);
        }
    }
});

$failures = [];
foreach ($tests as $name => $callback) {
    try {
        $callback();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures[] = [$name, $exception];
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), count($failures)));
exit($failures === [] ? 0 : 1);

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        $line = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'] ?? 0;
        throw new RuntimeException(sprintf(
            "Expected %s, got %s at test line %d",
            var_export($expected, true),
            var_export($actual, true),
            $line
        ));
    }
}

function assertRouteContains(string $expected, string $actual): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException("Expected generated route file to contain: {$expected}");
    }
}
