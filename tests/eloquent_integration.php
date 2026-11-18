<?php

use Franciscovillaquiran\LaravelRelationGenerator\Generators\MigrationReader;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RelationshipGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;
use Illuminate\Database\Capsule\Manager as Capsule;

if (!isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Pass the Composer autoloader that provides illuminate/database.');
}

require $argv[1];
$root = dirname(__DIR__);
foreach (glob($root . '/src/Generators/StructuralSchema/*.php') as $sourceFile) {
    require_once $sourceFile;
}
require_once $root . '/src/Generators/MigrationReader.php';
require_once $root . '/src/Generators/ModelSourceEditor.php';
require_once $root . '/src/Generators/RelationshipGenerator.php';
require_once $root . '/src/Relations/RelationDefinition.php';

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-eloquent-' . bin2hex(random_bytes(6));
$modelsPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'Models';
if (!mkdir($modelsPath, 0777, true)) {
    throw new RuntimeException("Could not create temporary Eloquent test directory: {$modelsPath}");
}
if (function_exists('base_path') && class_exists(\Illuminate\Foundation\Application::class)) {
    new \Illuminate\Foundation\Application($temporaryRoot);
}

try {
    $models = [
        'User' => 'users',
        'Profile' => 'profiles',
        'Post' => 'posts',
        'Tag' => 'tags',
        'Account' => 'accounts',
        'Invoice' => 'invoices',
    ];
    foreach ($models as $model => $table) {
        $source = "<?php\nnamespace App\\Models;\nclass {$model} extends \\Illuminate\\Database\\Eloquent\\Model\n{\n"
            . "    protected \$table = '{$table}';\n"
            . "    public \$timestamps = false;\n"
            . "}\n";
        if (file_put_contents($modelsPath . DIRECTORY_SEPARATOR . $model . '.php', $source) === false) {
            throw new RuntimeException("Could not write Eloquent test model {$model}.");
        }
    }

    $schema = (new MigrationReader())->analyze($root . '/tests/fixtures/migrations');
    $generator = new RelationshipGenerator();
    $generator->generateFromSchema(
        new RelationDefinition('User', 'Profile', '1:1', modelATable: 'users', modelBTable: 'profiles'),
        $schema,
        $modelsPath
    );
    $generator->generateFromSchema(
        new RelationDefinition('User', 'Post', '1:N', modelATable: 'users', modelBTable: 'posts'),
        $schema,
        $modelsPath
    );
    $generator->generateFromSchema(
        new RelationDefinition('Post', 'Tag', 'N:M', modelATable: 'posts', modelBTable: 'tags'),
        $schema,
        $modelsPath
    );

    $customMigration = $temporaryRoot . DIRECTORY_SEPARATOR . 'custom_keys.php';
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

    foreach ($models as $model => $_table) {
        require $modelsPath . DIRECTORY_SEPARATOR . $model . '.php';
    }

    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $database = $capsule->getConnection();

    $database->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, display_name TEXT)');
    $database->statement('CREATE TABLE profiles (id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE, biography TEXT)');
    $database->statement('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
    $database->statement('CREATE TABLE tags (id INTEGER PRIMARY KEY, label TEXT)');
    $database->statement('CREATE TABLE post_tag (post_id INTEGER, tag_id INTEGER, PRIMARY KEY (post_id, tag_id))');
    $database->statement('CREATE TABLE accounts (code TEXT UNIQUE)');
    $database->statement('CREATE TABLE invoices (id INTEGER PRIMARY KEY, account_ref TEXT)');
    $database->table('users')->insert(['id' => 1, 'display_name' => 'Ada']);
    $database->table('profiles')->insert(['id' => 1, 'user_id' => 1, 'biography' => 'Engineer']);
    $database->table('posts')->insert([
        ['id' => 1, 'user_id' => 1, 'title' => 'First'],
        ['id' => 2, 'user_id' => 1, 'title' => 'Second'],
    ]);
    $database->table('tags')->insert(['id' => 1, 'label' => 'Laravel']);
    $database->table('post_tag')->insert(['post_id' => 1, 'tag_id' => 1]);
    $database->table('accounts')->insert(['code' => 'AC-1']);
    $database->table('invoices')->insert(['id' => 1, 'account_ref' => 'AC-1']);

    $user = App\Models\User::query()->with(['profile', 'posts'])->findOrFail(1);
    if ($user->profile?->biography !== 'Engineer' || $user->posts->count() !== 2) {
        throw new RuntimeException('Eloquent one-to-one or one-to-many relation query returned unexpected results.');
    }
    if (App\Models\Profile::query()->with('user')->findOrFail(1)->user?->display_name !== 'Ada') {
        throw new RuntimeException('Eloquent belongsTo query returned unexpected results.');
    }

    $post = App\Models\Post::query()->with('tags')->findOrFail(1);
    $tag = App\Models\Tag::query()->with('posts')->findOrFail(1);
    if ($post->tags->count() !== 1 || $post->tags[0]->label !== 'Laravel' || $tag->posts->count() !== 1) {
        throw new RuntimeException('Eloquent many-to-many relation query returned unexpected results.');
    }
    $account = App\Models\Account::query()->with('invoices')->firstOrFail();
    $invoice = App\Models\Invoice::query()->with('account')->findOrFail(1);
    if ($account->invoices->count() !== 1 || $invoice->account?->code !== 'AC-1') {
        throw new RuntimeException('Eloquent custom-key relationship query returned unexpected results.');
    }

    fwrite(STDOUT, "PASS Eloquent relationship queries against SQLite\n");
} finally {
    foreach (glob($modelsPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $model) {
        unlink($model);
    }
    if (is_file($customMigration ?? '')) {
        unlink($customMigration);
    }
    $indexPath = $temporaryRoot . DIRECTORY_SEPARATOR . '.lrg' . DIRECTORY_SEPARATOR . 'lrg-index.json';
    if (is_file($indexPath)) {
        unlink($indexPath);
        rmdir(dirname($indexPath));
    }
    rmdir($modelsPath);
    rmdir($temporaryRoot);
}
