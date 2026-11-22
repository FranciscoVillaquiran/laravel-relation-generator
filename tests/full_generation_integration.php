<?php

use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\RelationMakeCommand;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

if (!isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Pass an autoloader that provides laravel/framework.');
}

$loader = require $argv[1];
$packageRoot = dirname(__DIR__);
$loader->addPsr4(
    'Franciscovillaquiran\\LaravelRelationGenerator\\',
    $packageRoot . DIRECTORY_SEPARATOR . 'src'
);

$projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-full-generation-' . bin2hex(random_bytes(6));
$directories = [
    'app' . DIRECTORY_SEPARATOR . 'Models',
    'app' . DIRECTORY_SEPARATOR . 'Console' . DIRECTORY_SEPARATOR . 'Commands',
    'bootstrap' . DIRECTORY_SEPARATOR . 'cache',
    'config',
    'database' . DIRECTORY_SEPARATOR . 'migrations',
    'routes',
    'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'data',
    'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'sessions',
    'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'views',
    'storage' . DIRECTORY_SEPARATOR . 'logs',
];
foreach ($directories as $directory) {
    $path = $projectRoot . DIRECTORY_SEPARATOR . $directory;
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Could not create Laravel integration fixture: {$path}");
    }
}

$write = static function (string $relativePath, string $contents) use ($projectRoot): void {
    $path = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create integration fixture directory: {$directory}");
    }
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        throw new RuntimeException("Could not write integration fixture: {$path}");
    }
};

$migration = static function (string $body, string $down) use ($write): void {
    static $number = 0;
    $number++;
    $name = sprintf('database/migrations/2026_11_18_00000%d_create_fixture.php', $number);
    $source = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n"
        . "use Illuminate\\Database\\Schema\\Blueprint;\n"
        . "use Illuminate\\Support\\Facades\\Schema;\n\n"
        . "return new class extends Migration {\n"
        . "    public function up(): void\n    {\n{$body}    }\n\n"
        . "    public function down(): void\n    {\n{$down}    }\n"
        . "};\n";
    $write($name, $source);
};

$run = static function (array $arguments, bool $expectSuccess = true) use ($projectRoot): string {
    $process = new Process([PHP_BINARY, $projectRoot . DIRECTORY_SEPARATOR . 'artisan', ...$arguments], $projectRoot);
    $process->run();
    if ($expectSuccess && !$process->isSuccessful()) {
        throw new RuntimeException(
            "Command failed ({$process->getExitCode()}):\n" . $process->getOutput() . $process->getErrorOutput()
        );
    }
    if (!$expectSuccess && $process->isSuccessful()) {
        throw new RuntimeException("Command unexpectedly succeeded:\n" . $process->getOutput());
    }

    return $process->getOutput() . $process->getErrorOutput();
};
$runInteractive = static function (array $arguments, string $input) use ($projectRoot): string {
    $process = new Process([PHP_BINARY, $projectRoot . DIRECTORY_SEPARATOR . 'artisan', ...$arguments], $projectRoot);
    $process->setInput($input);
    $process->run();
    if (!$process->isSuccessful()) {
        throw new RuntimeException(
            "Interactive command failed:\n" . $process->getOutput() . $process->getErrorOutput()
        );
    }

    return $process->getOutput() . $process->getErrorOutput();
};

$request = static function (
    HttpKernel $kernel,
    string $method,
    string $uri,
    ?array $payload = null
): array {
    $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
    $request = Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $body
    );
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $decoded = $response->getContent() === '' ? null : json_decode($response->getContent(), true);
    $kernel->terminate($request, $response);

    return [$status, $decoded];
};

try {
    $write('.env', "APP_NAME=LRG-Test\nAPP_ENV=testing\nAPP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nAPP_DEBUG=true\nDB_CONNECTION=sqlite\n");
    $write(
        'composer.json',
        json_encode(
            [
                'name' => 'tests/lrg-full-generation',
                'type' => 'project',
                'autoload' => ['psr-4' => ['App\\' => 'app/']],
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . "\n"
    );
    $write(
        'artisan',
        "<?php\n\$loader = require " . var_export($argv[1], true) . ";\n"
            . "\$loader->addPsr4('Franciscovillaquiran\\\\LaravelRelationGenerator\\\\', "
            . var_export($packageRoot . DIRECTORY_SEPARATOR . 'src', true) . ");\n"
            . "\$loader->addPsr4('App\\\\', __DIR__ . '/app');\n"
            . "\$app = require __DIR__ . '/bootstrap/app.php';\n"
            . "exit(\$app->handleCommand(new \\Symfony\\Component\\Console\\Input\\ArgvInput()));\n"
    );
    $write(
        'bootstrap/app.php',
        <<<'PHP'
<?php

use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\RelationMakeCommand;
use App\Console\Commands\FailingRelationMakeCommand;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php'
    )
    ->withCommands([RelationMakeCommand::class, FailingRelationMakeCommand::class])
    ->withMiddleware()
    ->withExceptions()
    ->create();
PHP
    );
    $write(
        'app/Console/Commands/FailingRelationMakeCommand.php',
        <<<'PHP'
<?php

namespace App\Console\Commands;

use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\RelationMakeCommand;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\FileChangeTransaction;
use RuntimeException;

class FailingRelationMakeCommand extends RelationMakeCommand
{
    protected $signature = 'relation:make-failing {modelA} {modelB} {cardinality} {--crud} {--api}';

    protected function makeFileTransaction(): FileChangeTransaction
    {
        $writes = 0;

        return new FileChangeTransaction(static function () use (&$writes): void {
            if (++$writes === 2) {
                throw new RuntimeException('controlled write failure');
            }
        });
    }
}
PHP
    );
    $write(
        'config/database.php',
        <<<'PHP'
<?php

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => database_path('database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
PHP
    );
    $write(
        'routes/api.php',
        "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/health', fn () => response()->json(['ok' => true]));\n"
    );
    $webOriginal = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/manual', fn () => 'manual');\n";
    $write('routes/web.php', $webOriginal);

    $modelTemplate = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class %s extends Model
{
}
PHP;
    foreach (['User', 'Profile', 'Category', 'Product', 'Post', 'Tag', 'Author', 'Book'] as $model) {
        $write('app/Models/' . $model . '.php', sprintf($modelTemplate, $model) . "\n");
    }

    $migration(<<<'PHP'
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
PHP, <<<'PHP'
        Schema::dropIfExists('users');
PHP);
    $migration(<<<'PHP'
        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('biography')->nullable();
        });
PHP, <<<'PHP'
        Schema::dropIfExists('profiles');
PHP);
    $migration(<<<'PHP'
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
        });
PHP, <<<'PHP'
        Schema::dropIfExists('categories');
PHP);
    $migration(<<<'PHP'
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('name');
        });
PHP, <<<'PHP'
        Schema::dropIfExists('products');
PHP);
    $migration(<<<'PHP'
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
PHP, <<<'PHP'
        Schema::dropIfExists('posts');
PHP);
    $migration(<<<'PHP'
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
        });
PHP, <<<'PHP'
        Schema::dropIfExists('tags');
PHP);
    $migration(<<<'PHP'
        Schema::create('post_tag', function (Blueprint $table): void {
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['post_id', 'tag_id']);
        });
PHP, <<<'PHP'
        Schema::dropIfExists('post_tag');
PHP);
    $migration(<<<'PHP'
        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
PHP, <<<'PHP'
        Schema::dropIfExists('authors');
PHP);
    $migration(<<<'PHP'
        Schema::create('books', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->constrained('authors')->restrictOnDelete();
            $table->string('title');
        });
PHP, <<<'PHP'
        Schema::dropIfExists('books');
PHP);

    $run(['migrate', '--force']);
    $loader->addPsr4('App\\', $projectRoot . DIRECTORY_SEPARATOR . 'app');
    $run(['relation:make', 'User', 'Profile', '1:1', '--crud', '--api', '--no-interaction']);
    $run(['relation:make', 'Category', 'Product', '1:N', '--crud', '--api', '--no-interaction']);
    $run(['relation:make', 'Post', 'Tag', 'N:M', '--crud', '--api', '--no-interaction']);

    $generatedFiles = [
        'app/Models/User.php',
        'app/Models/Profile.php',
        'app/Models/Category.php',
        'app/Models/Product.php',
        'app/Models/Post.php',
        'app/Models/Tag.php',
        'app/Http/Controllers/Api/UserController.php',
        'app/Http/Controllers/Api/ProfileController.php',
        'app/Http/Controllers/Api/CategoryController.php',
        'app/Http/Controllers/Api/ProductController.php',
        'app/Http/Controllers/Api/PostController.php',
        'app/Http/Controllers/Api/TagController.php',
        'routes/api.php',
        'routes/lrg_api.php',
        '.lrg/lrg-index.json',
    ];
    $hashes = [];
    foreach ($generatedFiles as $relativePath) {
        $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            throw new RuntimeException("Expected generated file is missing: {$relativePath}");
        }
        $hashes[$relativePath] = hash_file('sha256', $path);
    }
    if (is_file($projectRoot . DIRECTORY_SEPARATOR . 'app/Http/Controllers/Api/PostTagController.php')) {
        throw new RuntimeException('LRG generated CRUD for a pivot table.');
    }

    foreach ([
        ['User', 'Profile', '1:1'],
        ['Category', 'Product', '1:N'],
        ['Post', 'Tag', 'N:M'],
    ] as [$modelA, $modelB, $cardinality]) {
        $run(['relation:make', $modelA, $modelB, $cardinality, '--crud', '--api', '--no-interaction']);
    }
    foreach ($generatedFiles as $relativePath) {
        $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (hash_file('sha256', $path) !== $hashes[$relativePath]) {
            throw new RuntimeException("Repeated command changed an output unnecessarily: {$relativePath}");
        }
    }
    if (file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'routes/web.php') !== $webOriginal) {
        throw new RuntimeException('The API flow modified routes/web.php.');
    }

    $routeList = $run(['route:list', '--path=api']);
    foreach (['api/categories', 'api/categories/{category}', 'CategoryController@index', 'ProductController@store', 'PostController@destroy', 'TagController@update'] as $expected) {
        if (!str_contains($routeList, $expected)) {
            throw new RuntimeException("route:list omitted {$expected}:\n{$routeList}");
        }
    }

    $app = require $projectRoot . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
    $kernel = $app->make(HttpKernel::class);
    [$status, $categoryResponse] = $request($kernel, 'POST', '/api/categories', ['name' => 'Hardware']);
    if ($status !== 201) {
        throw new RuntimeException(
            "Category creation returned HTTP {$status}, expected 201: " . var_export($categoryResponse, true)
        );
    }
    [$categoryListStatus, $categoryList] = $request($kernel, 'GET', '/api/categories');
    if ($categoryListStatus !== 200 || count($categoryList) !== 1) {
        throw new RuntimeException('Collection endpoint returned an unexpected response.');
    }
    $categoryId = $categoryList[0]['id'];
    [$productStatus, $product] = $request(
        $kernel,
        'POST',
        '/api/products',
        ['category_id' => $categoryId, 'name' => 'Keyboard']
    );
    if ($productStatus !== 201) {
        throw new RuntimeException("Product creation returned HTTP {$productStatus}, expected 201.");
    }
    [$patchStatus, $updatedProduct] = $request(
        $kernel,
        'PATCH',
        '/api/products/' . $product['id'],
        ['name' => 'Mechanical keyboard']
    );
    if ($patchStatus !== 200 || $updatedProduct['category_id'] !== $categoryId) {
        throw new RuntimeException('Partial product update did not preserve omitted attributes.');
    }
    [$showStatus] = $request($kernel, 'GET', '/api/products/' . $product['id']);
    if ($showStatus !== 200) {
        throw new RuntimeException("Product show returned HTTP {$showStatus}, expected 200.");
    }
    [$missingRecordStatus] = $request($kernel, 'GET', '/api/products/99999');
    if ($missingRecordStatus !== 404) {
        throw new RuntimeException("Missing product returned HTTP {$missingRecordStatus}, expected 404.");
    }
    [$productDeleteStatus] = $request($kernel, 'DELETE', '/api/products/' . $product['id']);
    if ($productDeleteStatus !== 204) {
        throw new RuntimeException("Product delete returned HTTP {$productDeleteStatus}, expected 204.");
    }
    [$categoryDeleteStatus] = $request($kernel, 'DELETE', '/api/categories/' . $categoryId);
    if ($categoryDeleteStatus !== 204) {
        throw new RuntimeException("Category delete returned HTTP {$categoryDeleteStatus}, expected 204.");
    }

    [$userStatus, $userResponse] = $request($kernel, 'POST', '/api/users', ['email' => 'api@example.test']);
    if ($userStatus !== 201) {
        throw new RuntimeException("User creation returned HTTP {$userStatus}, expected 201.");
    }
    [$profileStatus, $profileResponse] = $request(
        $kernel,
        'POST',
        '/api/profiles',
        ['user_id' => $userResponse['id'], 'biography' => 'API profile']
    );
    if ($profileStatus !== 201) {
        throw new RuntimeException("Profile creation returned HTTP {$profileStatus}, expected 201.");
    }
    [$profileUpdateStatus] = $request(
        $kernel,
        'PATCH',
        '/api/profiles/' . $profileResponse['id'],
        ['biography' => 'Updated profile']
    );
    if ($profileUpdateStatus !== 200) {
        throw new RuntimeException("Profile partial update returned HTTP {$profileUpdateStatus}, expected 200.");
    }
    [$profileDeleteStatus] = $request($kernel, 'DELETE', '/api/profiles/' . $profileResponse['id']);
    if ($profileDeleteStatus !== 204) {
        throw new RuntimeException("Profile delete returned HTTP {$profileDeleteStatus}, expected 204.");
    }
    [$userDeleteStatus] = $request($kernel, 'DELETE', '/api/users/' . $userResponse['id']);
    if ($userDeleteStatus !== 204) {
        throw new RuntimeException("User delete returned HTTP {$userDeleteStatus}, expected 204.");
    }

    [$postStatus, $postResponse] = $request($kernel, 'POST', '/api/posts', ['title' => 'API post']);
    [$tagStatus, $tagResponse] = $request($kernel, 'POST', '/api/tags', ['name' => 'API tag']);
    if ($postStatus !== 201 || $tagStatus !== 201) {
        throw new RuntimeException('N:M participant CRUD creation did not return HTTP 201.');
    }
    [$postUpdateStatus] = $request(
        $kernel,
        'PATCH',
        '/api/posts/' . $postResponse['id'],
        ['title' => 'Updated API post']
    );
    if ($postUpdateStatus !== 200) {
        throw new RuntimeException("Post partial update returned HTTP {$postUpdateStatus}, expected 200.");
    }
    foreach ([
        ['posts', $postResponse['id']],
        ['tags', $tagResponse['id']],
    ] as [$resource, $id]) {
        [$deleteStatus] = $request($kernel, 'DELETE', "/api/{$resource}/{$id}");
        if ($deleteStatus !== 204) {
            throw new RuntimeException("N:M participant DELETE {$resource} returned HTTP {$deleteStatus}, expected 204.");
        }
    }

    $user = new App\Models\User();
    $user->timestamps = false;
    $user->fill(['email' => 'test@example.test']);
    $user->save();
    $profile = new App\Models\Profile();
    $profile->timestamps = false;
    $profile->fill(['user_id' => $user->getKey(), 'biography' => 'Generated relation']);
    $profile->save();
    if ($user->profile?->biography !== 'Generated relation') {
        throw new RuntimeException('Generated one-to-one relationship did not query successfully.');
    }
    $category = new App\Models\Category();
    $category->timestamps = false;
    $category->fill(['name' => 'Relationship category']);
    $category->save();
    $product = new App\Models\Product();
    $product->timestamps = false;
    $product->fill(['category_id' => $category->getKey(), 'name' => 'Relationship product']);
    $product->save();
    if ($category->fresh()->products->count() !== 1 || $product->fresh()->category?->getKey() !== $category->getKey()) {
        throw new RuntimeException('Generated one-to-many relationship did not query successfully.');
    }
    $post = new App\Models\Post();
    $post->timestamps = false;
    $post->fill(['title' => 'Generated post']);
    $post->save();
    $tag = new App\Models\Tag();
    $tag->timestamps = false;
    $tag->fill(['name' => 'Generated tag']);
    $tag->save();
    $post->tags()->attach($tag->getKey());
    if ($post->fresh()->tags->count() !== 1 || $tag->fresh()->posts->count() !== 1) {
        throw new RuntimeException('Generated many-to-many relationship did not query successfully.');
    }

    $apiRoutesBeforeLegacy = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'routes/api.php');
    $legacyOutput = $runInteractive(
        ['relation:make', 'Category', 'Product', '1:N'],
        "todo\n"
    );
    if (
        !str_contains($legacyOutput, 'ConsultasController generated.')
        || !str_contains($legacyOutput, 'Legacy web routes generated.')
        || !is_file($projectRoot . DIRECTORY_SEPARATOR . 'app/Http/Controllers/ConsultasController.php')
        || file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'routes/api.php') !== $apiRoutesBeforeLegacy
    ) {
        throw new RuntimeException('The legacy interactive todo selection was not preserved.');
    }
    $legacyRoutes = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'routes/web.php');
    if (!str_contains($legacyRoutes, '/consultas/category-product')) {
        throw new RuntimeException('The legacy todo selection did not generate the web query routes.');
    }

    $manualControllerPath = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
        . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api' . DIRECTORY_SEPARATOR . 'CategoryController.php';
    $categoryControllerOriginal = file_get_contents($manualControllerPath);
    file_put_contents($manualControllerPath, $categoryControllerOriginal . "// developer note\n");
    $beforeConflictModel = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'app/Models/Category.php');
    $beforeConflictIndex = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json');
    $conflictOutput = $run(
        ['relation:make', 'Category', 'Product', '1:N', '--crud', '--api', '--no-interaction'],
        false
    );
    if (!str_contains($conflictOutput, 'already exists')) {
        throw new RuntimeException("Manual controller conflict was not reported:\n{$conflictOutput}");
    }
    if (
        file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'app/Models/Category.php') !== $beforeConflictModel
        || file_get_contents($projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json') !== $beforeConflictIndex
    ) {
        throw new RuntimeException('Manual controller conflict changed generated models or the index.');
    }
    file_put_contents($manualControllerPath, $categoryControllerOriginal);

    $authorModelPath = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models'
        . DIRECTORY_SEPARATOR . 'Author.php';
    $bookModelPath = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models'
        . DIRECTORY_SEPARATOR . 'Book.php';
    $authorControllerPath = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
        . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api' . DIRECTORY_SEPARATOR . 'AuthorController.php';
    $bookControllerPath = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
        . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api' . DIRECTORY_SEPARATOR . 'BookController.php';
    $authorModelOriginal = file_get_contents($authorModelPath);
    $bookModelOriginal = file_get_contents($bookModelPath);
    $beforeWriteFailureIndex = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json');
    $writeFailureOutput = $run(
        ['relation:make-failing', 'Author', 'Book', '1:N', '--crud', '--api', '--no-interaction'],
        false
    );
    if (!str_contains($writeFailureOutput, 'controlled write failure')) {
        throw new RuntimeException("Controlled write failure was not reported:\n{$writeFailureOutput}");
    }
    if (
        file_get_contents($authorModelPath) !== $authorModelOriginal
        || file_get_contents($bookModelPath) !== $bookModelOriginal
        || file_exists($authorControllerPath)
        || file_exists($bookControllerPath)
        || file_get_contents($projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json') !== $beforeWriteFailureIndex
    ) {
        throw new RuntimeException('Controlled write failure did not restore files and preserve the structural index.');
    }

    $migrationPath = $projectRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations'
        . DIRECTORY_SEPARATOR . '2026_11_18_000003_create_fixture.php';
    $migrationOriginal = file_get_contents($migrationPath);
    file_put_contents(
        $migrationPath,
        str_replace(
            "    }\n\n    public function down(): void",
            "        if (\$dynamicCondition) {\n"
                . "            Schema::table('categories', function (Blueprint \$table): void { \$table->string('ambiguous'); });\n"
                . "        }\n"
                . "    }\n\n    public function down(): void",
            $migrationOriginal
        )
    );
    $beforeAmbiguousIndex = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json');
    $ambiguousOutput = $run(
        ['relation:make', 'Category', 'Product', '1:N', '--crud', '--api', '--no-interaction'],
        false
    );
    if (!str_contains($ambiguousOutput, 'migration')) {
        throw new RuntimeException("Ambiguous migration was not reported:\n{$ambiguousOutput}");
    }
    file_put_contents($migrationPath, $migrationOriginal);
    if (file_get_contents($projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json') !== $beforeAmbiguousIndex) {
        throw new RuntimeException('An ambiguous migration changed the structural index.');
    }

    fwrite(STDOUT, "PASS full relation:make CRUD/API flow, migrations, route:list, HTTP, idempotency, and conflicts\n");
} finally {
    if (is_dir($projectRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($projectRoot);
    }
}
