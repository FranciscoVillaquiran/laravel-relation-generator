<?php

use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\RelationMakeCommand;
use Franciscovillaquiran\LaravelRelationGenerator\Frontend\MetadataGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Frontend\FrontendConnector;
use Franciscovillaquiran\LaravelRelationGenerator\Frontend\TemplateInstaller;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\ApiRouteGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\CrudGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\FillableGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\MigrationReader;
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
$frontendFixture = $projectRoot . '-frontend';
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

$testFailure = null;
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
    $localModelClassMap = [];
    foreach (['User', 'Profile', 'Category', 'Product', 'Post', 'Tag', 'Author', 'Book', 'Widget', 'Voucher', 'Teacher', 'Course', 'Student'] as $model) {
        $localModelClassMap['App\\Models\\' . $model] = $projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . $model . '.php';
    }
    $write(
        'artisan',
        "<?php\n\$loader = require " . var_export($argv[1], true) . ";\n"
            . "\$loader->addPsr4('Franciscovillaquiran\\\\LaravelRelationGenerator\\\\', "
            . var_export($packageRoot . DIRECTORY_SEPARATOR . 'src', true) . ");\n"
            . "\$loader->addClassMap(" . var_export($localModelClassMap, true) . ");\n"
            . "\$loader->addPsr4('App\\\\', __DIR__ . '/app', true);\n"
            . "\$app = require __DIR__ . '/bootstrap/app.php';\n"
            . "exit(\$app->handleCommand(new \\Symfony\\Component\\Console\\Input\\ArgvInput()));\n"
    );
    $write(
        'bootstrap/app.php',
        <<<'PHP'
<?php

use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\RelationMakeCommand;
use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\FrontendConnectCommand;
use Franciscovillaquiran\LaravelRelationGenerator\Console\Commands\FrontendTemplateCommand;
use App\Console\Commands\FailingRelationMakeCommand;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
    )
    ->withCommands([RelationMakeCommand::class, FrontendConnectCommand::class, FrontendTemplateCommand::class, FailingRelationMakeCommand::class])
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
    foreach (['User', 'Profile', 'Category', 'Product', 'Post', 'Tag', 'Author', 'Book', 'Widget', 'Teacher', 'Course', 'Student'] as $model) {
        $write('app/Models/' . $model . '.php', sprintf($modelTemplate, $model) . "\n");
    }
    $write('app/Models/Voucher.php', <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Voucher extends Model {
    protected $primaryKey = 'serial';
}
PHP);

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
            $table->string('author_name')->nullable();
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
    $migration(<<<'PHP'
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120)->nullable()->unique();
            $table->boolean('enabled')->default(true);
            $table->string('api_key')->nullable();
        });
PHP, <<<'PHP'
        Schema::dropIfExists('widgets');
PHP);
    $migration(<<<'PHP'
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->bigIncrements('serial');
            $table->string('title');
        });
PHP, <<<'PHP'
        Schema::dropIfExists('vouchers');
PHP);
    $migration(<<<'PHP'
        Schema::create('product_tag', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('tag_id')->constrained('tags');
            $table->primary(['product_id', 'tag_id']);
        });
PHP, <<<'PHP'
        Schema::dropIfExists('product_tag');
PHP);
    $migration(<<<'PHP'
        Schema::create('teachers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
PHP, <<<'PHP'
        Schema::dropIfExists('teachers');
PHP);
    $migration(<<<'PHP'
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
PHP, <<<'PHP'
        Schema::dropIfExists('students');
PHP);
    $migration(<<<'PHP'
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->string('title');
            $table->string('description')->nullable();
        });
PHP, <<<'PHP'
        Schema::dropIfExists('courses');
PHP);
    $migration(<<<'PHP'
        Schema::create('course_student', function (Blueprint $table): void {
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->primary(['course_id', 'student_id']);
        });
PHP, <<<'PHP'
        Schema::dropIfExists('course_student');
PHP);

    $run(['migrate', '--force']);
    $loader->addClassMap($localModelClassMap);
    $loader->addPsr4('App\\', $projectRoot . DIRECTORY_SEPARATOR . 'app', true);
    $originalBootstrap = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'bootstrap/app.php');
    $originalUser = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'app/Models/User.php');
    $run(['route:cache']);
    $failure = $run(['relation:make', 'User', 'Profile', '1:1', '--crud', '--api', '--no-interaction'], false);
    if (!str_contains($failure, 'route:clear')
        || file_exists($projectRoot . DIRECTORY_SEPARATOR . 'routes/api.php')
        || file_exists($projectRoot . DIRECTORY_SEPARATOR . 'app/Http/Controllers/Api/UserController.php')
        || file_exists($projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json')
        || file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'bootstrap/app.php') !== $originalBootstrap
        || file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'app/Models/User.php') !== $originalUser) {
        throw new RuntimeException("Cached routes were not detected or changes were not rolled back:\n{$failure}");
    }
    $run(['route:clear']);
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
        'bootstrap/app.php',
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
    if (is_file($projectRoot . DIRECTORY_SEPARATOR . 'routes/lrg_api.php')) {
        throw new RuntimeException('LRG generated API routes in a separate route file.');
    }
    $generatedApiRoutes = file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'routes/api.php');
    if (str_contains($generatedApiRoutes, 'lrg_api.php')) {
        throw new RuntimeException('Generated routes/api.php still includes a separate LRG routes file.');
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
    $run(['relation:make', 'Product', 'Tag', 'N:M', '--crud', '--api', '--no-interaction']);
    $run(['relation:make', 'Teacher', 'Course', '1:N', '--crud', '--api', '--no-interaction']);
    $run(['relation:make', 'Course', 'Student', 'N:M', '--crud', '--api', '--no-interaction']);
    $schema = (new MigrationReader())->analyzeCurrent(
        $projectRoot . DIRECTORY_SEPARATOR . 'database/migrations',
        $projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json',
        $projectRoot
    );
    foreach (['Widget' => 'widgets', 'Voucher' => 'vouchers'] as $model => $table) {
        (new FillableGenerator())->generateFromTable(
            $projectRoot . DIRECTORY_SEPARATOR . 'app/Models/' . $model . '.php',
            $schema->table($table),
            $model
        );
        (new CrudGenerator())->generateFromTable($projectRoot, 'App\\Models\\' . $model, $schema->table($table), $schema);
    }
    (new ApiRouteGenerator())->generate($projectRoot, ['App\\Models\\Widget', 'App\\Models\\Voucher']);

    $routeList = $run(['route:list', '--path=api']);
    foreach ([
        'api/categories',
        'api/categories/{category}',
        'CategoryController@index',
        'ProductController@store',
        'PostController@destroy',
        'TagController@update',
        'api/posts/{post}/tags',
        'PostController@syncTag',
    ] as $expected) {
        if (!str_contains($routeList, $expected)) {
            throw new RuntimeException("route:list omitted {$expected}:\n{$routeList}");
        }
    }

    $app = require $projectRoot . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
    $kernel = $app->make(HttpKernel::class);
    $kernel->bootstrap();
    $database = $app->make('db')->connection();
    $assertConstraint = static function (callable $action, string $kind): void {
        try {
            $action();
        } catch (\Illuminate\Database\QueryException $exception) {
            if (!str_contains(strtolower($exception->getMessage()), strtolower($kind))) throw $exception;
            return;
        }
        throw new RuntimeException("Expected a {$kind} database constraint violation.");
    };
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
    $assertConstraint(
        fn () => $database->table('profiles')->insert(['user_id' => $userResponse['id'], 'biography' => 'Duplicate']),
        'unique'
    );
    $assertConstraint(fn () => $database->table('users')->where('id', $userResponse['id'])->delete(), 'foreign key');
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

    [$tagStatus, $tagResponse] = $request($kernel, 'POST', '/api/tags', ['name' => 'API tag']);
    [$secondTagStatus, $secondTagResponse] = $request($kernel, 'POST', '/api/tags', ['name' => 'Second API tag']);
    if ($tagStatus !== 201 || $secondTagStatus !== 201) {
        throw new RuntimeException('N:M participant CRUD creation did not return HTTP 201.');
    }
    [$postStatus, $postResponse] = $request(
        $kernel,
        'POST',
        '/api/posts',
        ['title' => 'API post', 'related_ids' => [$tagResponse['id']]]
    );
    if ($postStatus !== 201) {
        throw new RuntimeException("N:M post creation returned HTTP {$postStatus}, expected 201.");
    }
    $postModel = App\Models\Post::findOrFail($postResponse['id']);
    if (!$postModel->fresh()->tags->contains('id', $tagResponse['id'])) {
        throw new RuntimeException('N:M creation field did not attach the selected related model.');
    }
    [$postUpdateStatus, $updatedPost] = $request(
        $kernel,
        'PATCH',
        '/api/posts/' . $postResponse['id'],
        ['title' => 'Updated API post', 'related_ids' => [$secondTagResponse['id']]]
    );
    if ($postUpdateStatus !== 200 || $updatedPost['title'] !== 'Updated API post') {
        throw new RuntimeException("N:M post update returned HTTP {$postUpdateStatus}, expected 200.");
    }
    $currentTagIds = $postModel->fresh()->tags->pluck('id')->all();
    if ($currentTagIds !== [$secondTagResponse['id']]) {
        throw new RuntimeException('N:M update field did not replace the selected related models.');
    }
    [$clearRelationsStatus] = $request(
        $kernel,
        'PATCH',
        '/api/posts/' . $postResponse['id'],
        ['related_ids' => []]
    );
    if ($clearRelationsStatus !== 200 || $postModel->fresh()->tags->isNotEmpty()) {
        throw new RuntimeException('An empty related_ids field did not clear the N:M assignments.');
    }
    [$associationStatus] = $request(
        $kernel,
        'PUT',
        '/api/posts/' . $postResponse['id'] . '/tags',
        ['related_ids' => [$tagResponse['id']]]
    );
    if ($associationStatus !== 200) {
        throw new RuntimeException("N:M assignment PUT returned HTTP {$associationStatus}, expected 200.");
    }
    if ($postModel->fresh()->tags->pluck('id')->all() !== [$tagResponse['id']]) {
        throw new RuntimeException('N:M assignment route did not replace the selected related models.');
    }
    [$partialUpdateStatus] = $request(
        $kernel,
        'PATCH',
        '/api/posts/' . $postResponse['id'],
        ['title' => 'Updated again without changing tags']
    );
    if ($partialUpdateStatus !== 200 || $postModel->fresh()->tags->pluck('id')->all() !== [$tagResponse['id']]) {
        throw new RuntimeException('Omitting related_ids from a partial update changed the existing assignment.');
    }
    foreach ([
        ['posts', $postResponse['id']],
        ['tags', $tagResponse['id']],
        ['tags', $secondTagResponse['id']],
    ] as [$resource, $id]) {
        [$deleteStatus] = $request($kernel, 'DELETE', "/api/{$resource}/{$id}");
        if ($deleteStatus !== 204) {
            throw new RuntimeException("N:M participant DELETE {$resource} returned HTTP {$deleteStatus}, expected 204.");
        }
    }

    [$teacherStatus, $teacher] = $request($kernel, 'POST', '/api/teachers', ['name' => 'Test teacher']);
    [$studentStatus, $student] = $request($kernel, 'POST', '/api/students', ['name' => 'Test student']);
    [$otherStudentStatus, $otherStudent] = $request($kernel, 'POST', '/api/students', ['name' => 'Second student']);
    if ($teacherStatus !== 201 || $studentStatus !== 201 || $otherStudentStatus !== 201) {
        throw new RuntimeException('Combined model participants could not be created.');
    }
    [$teacherListStatus, $teacherList] = $request($kernel, 'GET', '/api/teachers');
    [$teacherShowStatus, $teacherShow] = $request($kernel, 'GET', '/api/teachers/' . $teacher['id']);
    [$teacherUpdateStatus, $teacherUpdated] = $request($kernel, 'PATCH', '/api/teachers/' . $teacher['id'], ['name' => 'Updated teacher']);
    [$studentShowStatus, $studentShow] = $request($kernel, 'GET', '/api/students/' . $student['id']);
    [$studentUpdateStatus, $studentUpdated] = $request($kernel, 'PATCH', '/api/students/' . $student['id'], ['name' => 'Updated student']);
    if ($teacherListStatus !== 200 || count($teacherList) !== 1 || $teacherShowStatus !== 200 || $teacherShow['id'] !== $teacher['id']
        || $teacherUpdateStatus !== 200 || $teacherUpdated['name'] !== 'Updated teacher'
        || $studentShowStatus !== 200 || $studentShow['id'] !== $student['id']
        || $studentUpdateStatus !== 200 || $studentUpdated['name'] !== 'Updated student') {
        throw new RuntimeException('Teacher or student read/update CRUD failed.');
    }
    [$courseStatus, $course] = $request($kernel, 'POST', '/api/courses', [
        'teacher_id' => $teacher['id'], 'title' => 'Test course', 'related_ids' => [$student['id']],
    ]);
    if ($courseStatus !== 201) throw new RuntimeException("Combined course creation returned HTTP {$courseStatus}.");
    [$courseListStatus, $courseList] = $request($kernel, 'GET', '/api/courses');
    [$courseShowStatus, $courseShow] = $request($kernel, 'GET', '/api/courses/' . $course['id']);
    if ($courseListStatus !== 200 || count($courseList) !== 1 || $courseShowStatus !== 200 || $courseShow['id'] !== $course['id']) {
        throw new RuntimeException('Course list or show CRUD failed.');
    }
    $courseModel = App\Models\Course::findOrFail($course['id']);
    if ($courseModel->teacher->getKey() !== $teacher['id']
        || $courseModel->students->pluck('id')->all() !== [$student['id']]) {
        throw new RuntimeException('Combined 1:N and N:M relationships did not match persisted records.');
    }
    $assertConstraint(fn () => $database->table('courses')->insert(['teacher_id' => 99999, 'title' => 'Invalid FK']), 'foreign key');
    $assertConstraint(fn () => $database->table('teachers')->where('id', $teacher['id'])->delete(), 'foreign key');
    $assertConstraint(fn () => $database->table('course_student')->insert(['course_id' => $course['id'], 'student_id' => 99999]), 'foreign key');
    $assertConstraint(fn () => $database->table('course_student')->insert(['course_id' => $course['id'], 'student_id' => $student['id']]), 'unique');
    [$duplicateSync] = $request($kernel, 'PUT', '/api/courses/' . $course['id'] . '/students', ['related_ids' => [$student['id'], $student['id']]]);
    if ($duplicateSync !== 200 || $courseModel->fresh()->students->count() !== 1) {
        throw new RuntimeException('Repeated N:M identifiers created duplicate associations.');
    }
    [$courseUpdate, $changedCourse] = $request($kernel, 'PATCH', '/api/courses/' . $course['id'], ['title' => 'Updated course']);
    if ($courseUpdate !== 200 || $changedCourse['teacher_id'] !== $teacher['id'] || $courseModel->fresh()->students->count() !== 1) {
        throw new RuntimeException('Combined course update changed existing relations.');
    }
    [$detachStatus] = $request($kernel, 'PUT', '/api/courses/' . $course['id'] . '/students', ['related_ids' => []]);
    if ($detachStatus !== 200 || $courseModel->fresh()->students->isNotEmpty()) throw new RuntimeException('Combined N:M unlink failed.');
    foreach ([['courses', $course['id']], ['students', $student['id']], ['students', $otherStudent['id']], ['teachers', $teacher['id']]] as [$resource, $id]) {
        [$deleted] = $request($kernel, 'DELETE', "/api/{$resource}/{$id}");
        if ($deleted !== 204) throw new RuntimeException("Combined model deletion failed for {$resource}.");
    }
    fwrite(STDOUT, "PASS requested 1:1, 1:N and combined N:M CRUD, constraints and associations\n");

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

    $metadataGenerator = new MetadataGenerator();
    $metadataJson = $metadataGenerator->json($projectRoot);
    if ($metadataJson !== $metadataGenerator->json($projectRoot)) {
        throw new RuntimeException('Frontend metadata is not deterministic.');
    }
    if (in_array('--validate-frontend-contract', $argv, true)) {
        $validator = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'react' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'contract.js';
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';
const { validateMetadata } = await import(pathToFileURL(process.argv[1]).href);
let input = '';
for await (const chunk of process.stdin) input += chunk;
validateMetadata(JSON.parse(input));
console.log('React validator accepted generated metadata 2.0.');
JS;
        $node = new Process(['node', '--input-type=module', '-e', $script, $validator], null, null, $metadataJson, 30);
        $node->run();
        if (!$node->isSuccessful()) {
            throw new RuntimeException("React contract validation failed:\n" . $node->getOutput() . $node->getErrorOutput());
        }
        echo $node->getOutput();
    }
    $metadata = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR);
    $entities = array_column($metadata['entities'], null, 'id');
    $widgetFields = array_column($entities['widgets']['fields'], null, 'name');
    $productFields = array_column($entities['products']['fields'], null, 'name');
    $relationsById = array_column($metadata['relations'], null, 'id');
    if ($metadata['version'] !== '2.0' || $metadata['api_prefix'] !== '/api'
        || $entities['vouchers']['primary_key'] !== 'serial'
        || $widgetFields['title']['constraints']['nullable'] !== true
        || $widgetFields['title']['constraints']['unique'] !== true
        || $widgetFields['title']['access'] !== 'editable'
        || $widgetFields['id']['access'] !== 'read_only'
        || $widgetFields['enabled']['default'] !== true
        || isset($widgetFields['api_key'])
        || !isset($productFields['author_name'])
        || $relationsById['foreign:profiles.profiles_user_id_foreign']['cardinality'] !== '1:1'
        || $relationsById['foreign:products.products_category_id_foreign']['cardinality'] !== '1:N'
        || $relationsById['pivot:post_tag']['association_operations']['replace']['body_field'] !== 'related_ids'
        || $relationsById['pivot:product_tag']['association_operations']['replace']['method'] !== 'PUT'
        || $relationsById['pivot:product_tag']['pivot_foreign_keys']['source']['columns'] !== ['product_id']
        || $relationsById['pivot:course_student']['cardinality'] !== 'N:M'
        || $relationsById['foreign:courses.courses_teacher_id_foreign']['cardinality'] !== '1:N'
        || isset($entities['course_students'])) {
        throw new RuntimeException('Frontend metadata did not reflect the generated schema and routes.');
    }
    if (in_array('--validate-frontend-connection', $argv, true)) {
        $modulesIndex = array_search('--validate-frontend-connection', $argv, true) + 1;
        $modules = realpath($argv[$modulesIndex] ?? '');
        if ($modules === false || !is_dir($modules)) throw new RuntimeException('Pass an installed React/Vite node_modules after --validate-frontend-connection.');
        mkdir($frontendFixture . DIRECTORY_SEPARATOR . 'src', 0777, true);
        $frontendWrite = static function (string $relative, string $contents) use ($frontendFixture): void {
            $path = $frontendFixture . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
            if (file_put_contents($path, $contents) !== strlen($contents)) throw new RuntimeException("Cannot write fixture {$relative}");
        };
        $frontendWrite('package.json', '{"name":"connect-fixture","type":"module","scripts":{"build":"vite build"},"dependencies":{"react":"^19.1.0","react-dom":"^19.1.0"},"devDependencies":{"vite":"^7.0.0","@vitejs/plugin-react":"^5.0.0"}}');
        $frontendWrite('index.html', '<div id="root"></div><script type="module" src="/src/main.jsx"></script>');
        $frontendWrite('vite.config.js', "import { defineConfig } from 'vite';\nimport react from '@vitejs/plugin-react';\nexport default defineConfig({ plugins: [react()] });\n");
        $frontendWrite('src/main.jsx', "import { StrictMode } from 'react';\nimport { createRoot } from 'react-dom/client';\nimport './index.css';\nimport App from './App.jsx';\ncreateRoot(document.getElementById('root')).render(<StrictMode><App /></StrictMode>);\n");
        $frontendWrite('src/App.jsx', 'export default function App() { return null }');
        $frontendWrite('src/index.css', '');
        $frontendWrite('src/custom.js', 'developer-owned');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir($frontendFixture . DIRECTORY_SEPARATOR . 'node_modules');
        foreach ($iterator as $item) {
            if ($item->isLink()) throw new RuntimeException('Fixture dependencies must not contain links.');
            $dest = $frontendFixture . DIRECTORY_SEPARATOR . 'node_modules' . substr($item->getPathname(), strlen($modules));
            if ($item->isDir()) mkdir($dest);
            elseif (!copy($item->getPathname(), $dest)) throw new RuntimeException("Cannot copy dependency {$dest}");
        }
        $frontendPath = '..' . DIRECTORY_SEPARATOR . basename($frontendFixture);
        $template = new TemplateInstaller($projectRoot);
        $connector = new FrontendConnector($projectRoot);
        try {
            $connector->inspectFrontend($frontendPath);
            throw new RuntimeException('Missing template was accepted.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'frontend-manifest.json')) throw $exception;
        }
        $templatePlan = $template->prepare($frontendPath);
        $runInteractive(['lrg:frontend:template', '--path=' . $frontendPath], "yes\n");
        $templateBefore = [];
        foreach (array_keys($templatePlan['changes']) as $relative) {
            $templateBefore[$relative] = hash_file('sha256', $frontendFixture . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        }
        $templateRepeat = $run(['lrg:frontend:template', '--path=' . $frontendPath, '--no-interaction']);
        if (!str_contains($templateRepeat, 'already installed')) throw new RuntimeException('Template command was not idempotent.');
        foreach ($templateBefore as $relative => $hash) {
            if (hash_file('sha256', $frontendFixture . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)) !== $hash) {
                throw new RuntimeException("Repeated template command changed {$relative}.");
            }
        }
        $originalEntry = file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/main.jsx');
        $originalVite = file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'vite.config.js');
        $customVite = str_replace('plugins: [react()]', "plugins: [react()], server: { proxy: { '/existing': 'http://example.test' } }", $originalVite);
        file_put_contents($frontendFixture . DIRECTORY_SEPARATOR . 'vite.config.js', $customVite);
        try {
            $connector->prepare($frontendPath, 'http://lrg-api.test');
            throw new RuntimeException('An unrecognized existing Vite proxy was accepted.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Cannot verify')) throw $exception;
        } finally {
            if (file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'vite.config.js') !== $customVite) throw new RuntimeException('Vite conflict changed developer configuration.');
            file_put_contents($frontendFixture . DIRECTORY_SEPARATOR . 'vite.config.js', $originalVite);
        }
        foreach (['bad-url', 'http://lrg-api.test/api', 'http://user:pass@lrg-api.test'] as $badUrl) {
            try {
                $connector->prepare($frontendPath, $badUrl);
                throw new RuntimeException('Invalid URL was accepted.');
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'app.url')) throw $exception;
            }
        }
        $writes = 0;
        $failingConnector = new FrontendConnector($projectRoot, static function () use (&$writes): void {
            if (++$writes === 3) throw new RuntimeException('injected connection failure');
        });
        try {
            $failingConnector->apply($failingConnector->prepare($frontendPath, 'http://lrg-api.test'));
            throw new RuntimeException('Expected connection write failure.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'injected connection failure')) throw $exception;
        }
        if (file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/main.jsx') !== $originalEntry
            || file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'vite.config.js') !== $originalVite
            || is_file($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json')) {
            throw new RuntimeException('Connection rollback did not preserve the frontend.');
        }
        $plan = $connector->prepare($frontendPath, 'http://lrg-api.test');
        if (!$connector->apply($plan)) throw new RuntimeException('Connection installation failed.');
        $managedHashes = [];
        foreach (array_keys($plan['changes']) as $relative) {
            $managedHashes[$relative] = hash_file('sha256', $frontendFixture . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        }
        if ($connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'))) {
            throw new RuntimeException('Connection installation or idempotency failed.');
        }
        foreach ($managedHashes as $relative => $hash) {
            if (hash_file('sha256', $frontendFixture . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)) !== $hash) {
                throw new RuntimeException("Repeated connection changed {$relative}.");
            }
        }
        if (!$template->prepare($frontendPath)['installed']
            || file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/custom.js') !== 'developer-owned'
            || file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json') !== $metadataJson) {
            throw new RuntimeException('Connected template or metadata was not preserved.');
        }
        $npm = DIRECTORY_SEPARATOR === '\\' ? 'npm.cmd' : 'npm';
        $build = new Process([$npm, 'run', 'build'], $frontendFixture, null, null, 120);
        $build->run();
        if (!$build->isSuccessful()) throw new RuntimeException("Connected Vite build failed:\n" . $build->getOutput() . $build->getErrorOutput());
        $write('config/app.php', "<?php return ['url' => 'http://lrg-api.test', 'key' => env('APP_KEY'), 'cipher' => 'AES-256-CBC'];\n");
        $connectedCommand = $run(['lrg:frontend:connect', '--path=' . $frontendPath, '--no-interaction']);
        if (!str_contains($connectedCommand, 'already synchronized')) throw new RuntimeException("Artisan connect did not synchronize:\n{$connectedCommand}");
        $templateAfterConnect = $run(['lrg:frontend:template', '--path=' . $frontendPath, '--no-interaction']);
        if (!str_contains($templateAfterConnect, 'already installed')) throw new RuntimeException('Template command rejected its connected installation.');
        $stateFile = $frontendFixture . DIRECTORY_SEPARATOR . '.lrg/frontend-connection.json';
        $originalState = file_get_contents($stateFile);
        file_put_contents($stateFile, '{ malformed');
        try {
            $connector->prepare($frontendPath, 'http://lrg-api.test');
            throw new RuntimeException('Malformed connection JSON was accepted.');
        } catch (JsonException $exception) {
            if ($exception->getCode() !== JSON_ERROR_SYNTAX) throw $exception;
        } finally {
            file_put_contents($stateFile, $originalState);
        }
        $proxyFile = $frontendFixture . DIRECTORY_SEPARATOR . '.lrg/vite-proxy.mjs';
        $proxyOriginal = file_get_contents($proxyFile);
        chmod($proxyFile, 0444);
        clearstatcache(true, $proxyFile);
        try {
            if (is_writable($proxyFile)) {
                fwrite(STDOUT, "SKIP permission failure: filesystem reports the read-only fixture as writable\n");
            } else {
                try {
                    $connector->apply($connector->prepare($frontendPath, 'http://different.test'));
                    throw new RuntimeException('Read-only connection file was overwritten.');
                } catch (RuntimeException $exception) {
                    if (!str_contains($exception->getMessage(), 'not readable and writable')) throw $exception;
                }
                if (file_get_contents($proxyFile) !== $proxyOriginal) throw new RuntimeException('Read-only proxy changed.');
                fwrite(STDOUT, "PASS read-only connection file preserved\n");
            }
        } finally {
            chmod($proxyFile, 0666);
        }
        $controllerPath = $projectRoot . DIRECTORY_SEPARATOR . 'app/Http/Controllers/Api/WidgetController.php';
        $controllerOriginal = file_get_contents($controllerPath);
        unlink($controllerPath);
        try {
            $connector->prepare($frontendPath, 'http://lrg-api.test');
            throw new RuntimeException('Missing CRUD controller was accepted.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Missing CRUD controller was accepted.') throw $exception;
        } finally {
            file_put_contents($controllerPath, $controllerOriginal);
        }
        $schemaPath = $frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json';
        file_put_contents($schemaPath, $metadataJson . ' ');
        try {
            $connector->prepare($frontendPath, 'http://lrg-api.test');
            throw new RuntimeException('Modified metadata was accepted.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'modified')) throw $exception;
        } finally {
            file_put_contents($schemaPath, $metadataJson);
        }

        $allocatePort = static function (): int {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($socket === false) throw new RuntimeException("Cannot allocate HTTP test port: {$error}");
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            return (int) substr(strrchr($address, ':'), 1);
        };
        $backendPort = $allocatePort();
        $vitePort = $allocatePort();
        while ($vitePort === $backendPort) $vitePort = $allocatePort();
        $write('public/index.php',
            "<?php\n\$loader = require " . var_export($argv[1], true) . ";\n"
            . "\$loader->addPsr4('Franciscovillaquiran\\\\LaravelRelationGenerator\\\\', " . var_export($packageRoot . DIRECTORY_SEPARATOR . 'src', true) . ");\n"
            . "\$loader->addClassMap(" . var_export($localModelClassMap, true) . ");\n"
            . "\$loader->addPsr4('App\\\\', __DIR__ . '/../app', true);\n"
            . "\$app = require __DIR__ . '/../bootstrap/app.php';\n"
            . "\$app->handleRequest(\\Illuminate\\Http\\Request::capture());\n"
        );
        $connector->apply($connector->prepare($frontendPath, "http://127.0.0.1:{$backendPort}"));
        $backendServer = new Process([PHP_BINARY, '-S', "127.0.0.1:{$backendPort}", 'public/index.php'], $projectRoot);
        $viteServer = new Process(['node', $frontendFixture . DIRECTORY_SEPARATOR . 'node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', (string) $vitePort, '--strictPort'], $frontendFixture);
        $backendServer->start();
        $viteServer->start();
        try {
            $response = false;
            $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
            for ($attempt = 0; $attempt < 50 && $backendServer->isRunning() && $viteServer->isRunning(); $attempt++) {
                $response = @file_get_contents("http://127.0.0.1:{$vitePort}/api/widgets", false, $context);
                $decoded = is_string($response) ? json_decode($response, true) : null;
                if (is_array($decoded) && array_is_list($decoded)) break;
                usleep(100000);
            }
            if (!is_array($decoded ?? null) || !array_is_list($decoded)) {
                throw new RuntimeException("Real Laravel/Vite proxy request failed: {$response}\n" . $backendServer->getErrorOutput() . $viteServer->getErrorOutput());
            }
            $handoffIndex = array_search('--browser-handoff', $argv, true);
            if ($handoffIndex !== false) {
                $handoff = realpath($argv[$handoffIndex + 1] ?? '');
                if ($handoff === false || !is_dir($handoff)) throw new RuntimeException('Pass an existing isolated directory after --browser-handoff.');
                $ready = $handoff . DIRECTORY_SEPARATOR . 'ready.json';
                $done = $handoff . DIRECTORY_SEPARATOR . 'done';
                if (file_exists($ready) || file_exists($done)) throw new RuntimeException('Browser handoff directory is not empty.');
                file_put_contents($ready, json_encode(['url' => "http://127.0.0.1:{$vitePort}/"], JSON_THROW_ON_ERROR));
                echo "Browser fixture ready at http://127.0.0.1:{$vitePort}/\n";
                $deadline = microtime(true) + 300;
                while (!is_file($done) && microtime(true) < $deadline && $backendServer->isRunning() && $viteServer->isRunning()) usleep(100000);
                if (!is_file($done)) throw new RuntimeException('Browser fixture timed out or a server stopped.');
            }
        } finally {
            $viteServer->stop();
            $backendServer->stop();
        }
        $connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'));
        fwrite(STDOUT, "PASS HTTP request through Vite proxy to Laravel\n");
        fwrite(STDOUT, "PASS frontend connection: ownership, URL validation, rollback, idempotency, Artisan and Vite build\n");
    }
    $metadataBootstrapPath = $projectRoot . DIRECTORY_SEPARATOR . 'bootstrap/app.php';
    $metadataBootstrap = file_get_contents($metadataBootstrapPath);
    $customBootstrap = str_replace(
        "api: __DIR__ . '/../routes/api.php',",
        "api: __DIR__ . '/../routes/api.php', apiPrefix: 'v1',",
        $metadataBootstrap
    );
    if ($customBootstrap === $metadataBootstrap) {
        throw new RuntimeException('Could not configure the custom-prefix fixture.');
    }
    try {
        file_put_contents($metadataBootstrapPath, $customBootstrap);
        $customMetadata = json_decode($metadataGenerator->json($projectRoot), true, 512, JSON_THROW_ON_ERROR);
        $customEntities = array_column($customMetadata['entities'], null, 'id');
        if ($customMetadata['api_prefix'] !== '/v1' || $customEntities['widgets']['operations']['list']['path'] !== '/widgets') {
            throw new RuntimeException('Metadata duplicated or lost the custom API prefix.');
        }
        if (isset($connector)) {
            $connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'));
            $customPublished = json_decode(file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json'), true, 512, JSON_THROW_ON_ERROR);
            if ($customPublished['api_prefix'] !== '/v1') throw new RuntimeException('Custom API prefix was not synchronized.');
            file_put_contents($metadataBootstrapPath, str_replace("apiPrefix: 'v1'", "apiPrefix: 'api/api'", $customBootstrap));
            try {
                $connector->prepare($frontendPath, 'http://lrg-api.test');
                throw new RuntimeException('Duplicated API prefix was accepted.');
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'prefix')) throw $exception;
            }
            file_put_contents($metadataBootstrapPath, str_replace("apiPrefix: 'v1'", "apiPrefix: ''", $customBootstrap));
            $frontendWrite('public/widgets', 'developer-owned asset');
            try {
                $connector->prepare($frontendPath, 'http://lrg-api.test');
                throw new RuntimeException('An unprefixed public asset collision was accepted.');
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'public asset')) throw $exception;
            } finally {
                unlink($frontendFixture . DIRECTORY_SEPARATOR . 'public/widgets');
            }
            $connector->apply($connector->prepare($frontendPath, "http://127.0.0.1:{$backendPort}"));
            $backendServer = new Process([PHP_BINARY, '-S', "127.0.0.1:{$backendPort}", 'public/index.php'], $projectRoot);
            $viteServer = new Process(['node', $frontendFixture . DIRECTORY_SEPARATOR . 'node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', (string) $vitePort, '--strictPort'], $frontendFixture);
            $backendServer->start();
            $viteServer->start();
            try {
                $response = false;
                for ($attempt = 0; $attempt < 50 && $backendServer->isRunning() && $viteServer->isRunning(); $attempt++) {
                    $response = @file_get_contents("http://127.0.0.1:{$vitePort}/widgets", false, $context);
                    $decoded = is_string($response) ? json_decode($response, true) : null;
                    if (is_array($decoded) && array_is_list($decoded)) break;
                    usleep(100000);
                }
                $module = @file_get_contents("http://127.0.0.1:{$vitePort}/src/main.jsx", false, $context);
                if (!is_array($decoded ?? null) || !array_is_list($decoded) || !is_string($module) || !str_contains($module, 'lrg-connected')) {
                    throw new RuntimeException("Unprefixed proxy interfered with Vite or Laravel: {$response}\n" . $backendServer->getErrorOutput() . $viteServer->getErrorOutput());
                }
            } finally {
                $viteServer->stop();
                $backendServer->stop();
            }
            fwrite(STDOUT, "PASS unprefixed API proxy and Vite module isolation\n");
        }
    } finally {
        file_put_contents($metadataBootstrapPath, $metadataBootstrap);
    }
    if (isset($connector)) $connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'));
    if (isset($connector)) {
        $postMigration = $projectRoot . DIRECTORY_SEPARATOR . 'database/migrations/2026_11_18_000005_create_fixture.php';
        $postOriginal = file_get_contents($postMigration);
        $indexForSync = $projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json';
        $indexForSyncOriginal = file_get_contents($indexForSync);
        $changedMigration = str_replace("\$table->string('title');", "\$table->string('title');\n            \$table->string('summary')->nullable();", $postOriginal);
        if ($changedMigration === $postOriginal) throw new RuntimeException('Cannot prepare relational model change fixture.');
        try {
            file_put_contents($postMigration, $changedMigration);
            (new MigrationReader())->analyze($projectRoot . DIRECTORY_SEPARATOR . 'database/migrations', $indexForSync);
            $connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'));
            $updated = json_decode(file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json'), true, 512, JSON_THROW_ON_ERROR);
            $updatedEntities = array_column($updated['entities'], null, 'id');
            $updatedFields = array_column($updatedEntities['posts']['fields'], null, 'name');
            if (($updatedFields['summary']['access'] ?? null) !== 'read_only') throw new RuntimeException('Changed relational model was not synchronized safely.');
        } finally {
            file_put_contents($postMigration, $postOriginal);
            file_put_contents($indexForSync, $indexForSyncOriginal);
            $connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'));
        }
    }
    $metadataRoutePath = $projectRoot . DIRECTORY_SEPARATOR . 'routes/api.php';
    $metadataRoutes = file_get_contents($metadataRoutePath);
    if (isset($connector)) {
        $before = file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json');
        try {
            file_put_contents($metadataRoutePath, "<?php\n");
            try {
                $connector->prepare($frontendPath, 'http://lrg-api.test');
                throw new RuntimeException('Backend without registered CRUD was accepted.');
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'Backend without registered CRUD was accepted.') throw $exception;
            }
            if (file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json') !== $before) {
                throw new RuntimeException('Missing CRUD changed frontend metadata.');
            }
        } finally {
            file_put_contents($metadataRoutePath, $metadataRoutes);
        }
    }
    $associationLine = "Route::put('/products/{product}/tags', [\\App\\Http\\Controllers\\Api\\ProductController::class, 'syncTag']);";
    if (!str_contains($metadataRoutes, $associationLine)) {
        throw new RuntimeException('Expected generated association route is missing from fixture.');
    }
    try {
        file_put_contents($metadataRoutePath, str_replace($associationLine . "\n", '', $metadataRoutes));
        $withoutAssociation = json_decode($metadataGenerator->json($projectRoot), true, 512, JSON_THROW_ON_ERROR);
        $withoutRelations = array_column($withoutAssociation['relations'], null, 'id');
        if (isset($withoutRelations['pivot:product_tag']['association_operations']['replace'])) {
            throw new RuntimeException('Metadata invented a missing association endpoint.');
        }
        if ($withoutRelations['pivot:product_tag']['association_operations']['create']['body_field'] !== 'related_ids') {
            throw new RuntimeException('Metadata omitted the verified CRUD association capability.');
        }
        if (isset($connector)) {
            $connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'));
            $synced = json_decode(file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json'), true, 512, JSON_THROW_ON_ERROR);
            $syncedRelations = array_column($synced['relations'], null, 'id');
            if (isset($syncedRelations['pivot:product_tag']['association_operations']['replace'])) {
                throw new RuntimeException('Resynchronization retained the removed association route.');
            }
        }
        $basicFixture = $projectRoot . DIRECTORY_SEPARATOR . 'basic-controller-fixture';
        (new CrudGenerator())->generateFromTable(
            $basicFixture,
            'App\\Models\\Product',
            $schema->table('products'),
            $schema
        );
        $basicController = file_get_contents($basicFixture . DIRECTORY_SEPARATOR . 'app/Http/Controllers/Api/ProductController.php');
        $productControllerPath = $projectRoot . DIRECTORY_SEPARATOR . 'app/Http/Controllers/Api/ProductController.php';
        $productController = file_get_contents($productControllerPath);
        try {
            file_put_contents($productControllerPath, $basicController);
            $readOnly = json_decode($metadataGenerator->json($projectRoot), true, 512, JSON_THROW_ON_ERROR);
            $readOnlyRelations = array_column($readOnly['relations'], null, 'id');
            if ($readOnlyRelations['pivot:product_tag']['association_operations'] !== []) {
                throw new RuntimeException('Metadata invented association operations for a basic controller.');
            }
        } finally {
            file_put_contents($productControllerPath, $productController);
        }
        $widgetResource = "Route::apiResource('widgets', \\App\\Http\\Controllers\\Api\\WidgetController::class);";
        $partialWidgetResource = "Route::apiResource('widgets', \\App\\Http\\Controllers\\Api\\WidgetController::class)->only(['index', 'show']);";
        if (!str_contains($metadataRoutes, $widgetResource)) {
            throw new RuntimeException('Expected generated widget resource is missing from fixture.');
        }
        file_put_contents($metadataRoutePath, str_replace($widgetResource, $partialWidgetResource, $metadataRoutes));
        $partial = json_decode($metadataGenerator->json($projectRoot), true, 512, JSON_THROW_ON_ERROR);
        $partialEntities = array_column($partial['entities'], null, 'id');
        $partialFields = array_column($partialEntities['widgets']['fields'], null, 'name');
        if (isset($partialEntities['widgets']['operations']['delete'])
            || $partialFields['title']['access'] !== 'read_only') {
            throw new RuntimeException('Metadata invented an unavailable CRUD operation.');
        }
        if (isset($connector)) {
            $connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'));
            $partialSynced = json_decode(file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json'), true, 512, JSON_THROW_ON_ERROR);
            $partialSyncedEntities = array_column($partialSynced['entities'], null, 'id');
            if (isset($partialSyncedEntities['widgets']['operations']['delete'])) {
                throw new RuntimeException('Resynchronization retained an unavailable CRUD operation.');
            }
            file_put_contents($metadataRoutePath, str_replace($widgetResource . "\n", '', $metadataRoutes));
            $connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'));
            $removed = json_decode(file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json'), true, 512, JSON_THROW_ON_ERROR);
            $removedEntities = array_column($removed['entities'], null, 'id');
            if (isset($removedEntities['widgets']) || !isset($removedEntities['vouchers'])) {
                throw new RuntimeException('Resynchronization did not remove the undeclared resource correctly.');
            }
        }
        file_put_contents($metadataRoutePath, $metadataRoutes . "\nRoute::delete('/categories/{category}', fn () => null);\n");
        try {
            $metadataGenerator->json($projectRoot);
            throw new RuntimeException('Expected a missing effective endpoint to be rejected.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'registered DELETE route')) {
                throw $exception;
            }
        }
        if (isset($connector)) {
            $before = file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json');
            try {
                $connector->prepare($frontendPath, 'http://lrg-api.test');
                throw new RuntimeException('Missing registered route was accepted by connect.');
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'registered DELETE route')) throw $exception;
            }
            if (file_get_contents($frontendFixture . DIRECTORY_SEPARATOR . 'src/generated/lrg-schema.json') !== $before) {
                throw new RuntimeException('Failed route verification modified frontend metadata.');
            }
        }
    } finally {
        file_put_contents($metadataRoutePath, $metadataRoutes);
    }
    if (isset($connector)) $connector->apply($connector->prepare($frontendPath, 'http://lrg-api.test'));
    $metadataMigration = $projectRoot . DIRECTORY_SEPARATOR . 'database/migrations/2026_11_18_000003_create_fixture.php';
    $metadataMigrationOriginal = file_get_contents($metadataMigration);
    try {
        file_put_contents($metadataMigration, $metadataMigrationOriginal . "\n");
        try {
            $metadataGenerator->json($projectRoot);
            throw new RuntimeException('Expected a stale structural index to be rejected.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Structural index is stale')) {
                throw $exception;
            }
        }
        if (isset($connector)) {
            try {
                $connector->prepare($frontendPath, 'http://lrg-api.test');
                throw new RuntimeException('Stale index was accepted by connect.');
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'Structural index is stale')) throw $exception;
            }
        }
    } finally {
        file_put_contents($metadataMigration, $metadataMigrationOriginal);
    }
    $sensitiveMigration = $projectRoot . DIRECTORY_SEPARATOR . 'database/migrations/2026_11_18_0000010_create_fixture.php';
    $sensitiveOriginal = file_get_contents($sensitiveMigration);
    $indexPath = $projectRoot . DIRECTORY_SEPARATOR . '.lrg/lrg-index.json';
    $indexOriginal = file_get_contents($indexPath);
    $requiredSensitive = str_replace("\$table->string('api_key')->nullable();", "\$table->string('api_key');", $sensitiveOriginal);
    if ($requiredSensitive === $sensitiveOriginal) {
        throw new RuntimeException('Could not configure the required-sensitive-field fixture.');
    }
    try {
        file_put_contents($sensitiveMigration, $requiredSensitive);
        (new MigrationReader())->analyze($projectRoot . DIRECTORY_SEPARATOR . 'database/migrations', $indexPath);
        try {
            $metadataGenerator->json($projectRoot);
            throw new RuntimeException('Expected an unrepresentable sensitive required field to be rejected.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'required column api_key cannot be supplied')) {
                throw $exception;
            }
        }
    } finally {
        if (is_dir($frontendFixture)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($frontendFixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
                else @unlink($item->getPathname());
            }
            @rmdir($frontendFixture);
        }
        file_put_contents($sensitiveMigration, $sensitiveOriginal);
        file_put_contents($indexPath, $indexOriginal);
    }
    fwrite(STDOUT, "PASS frontend metadata 2.0: schema, relations, routes, sensitivity, stale index and determinism\n");

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
    try {
        $metadataGenerator->json($projectRoot);
        throw new RuntimeException('Expected a modified controller to be rejected by metadata generation.');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'not an unchanged supported LRG-generated controller')) {
            throw $exception;
        }
    }
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

    fwrite(
        STDOUT,
        "PASS full relation:make CRUD/API flow, inline routes, N:M assignment, migrations, HTTP, "
        . "idempotency, and conflicts\n"
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
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
if ($testFailure !== null) {
    fwrite(STDERR, $testFailure . "\n");
    exit(1);
}
