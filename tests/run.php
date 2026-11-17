<?php

use Franciscovillaquiran\LaravelRelationGenerator\Generators\MigrationReader;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\MigrationAnalysisException;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\MigrationParser;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RelationshipGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;

$root = dirname(__DIR__);
foreach (glob($root . '/src/Generators/StructuralSchema/*.php') as $sourceFile) {
    require_once $sourceFile;
}
require_once $root . '/src/Generators/MigrationReader.php';
require_once $root . '/src/Generators/RelationshipGenerator.php';
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
        throw new RuntimeException(sprintf(
            "Expected %s, got %s",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
