<?php

use Franciscovillaquiran\LaravelRelationGenerator\Generators\ApiRouteGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RegisteredApiRouteInspector;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

if (!isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Pass an autoloader that provides laravel/framework.');
}

$loader = require $argv[1];
$root = dirname(__DIR__);
require_once $root . '/src/Generators/ApiRouteGenerator.php';
require_once $root . '/src/Generators/FileChangeTransaction.php';
require_once $root . '/src/Generators/RegisteredApiRouteInspector.php';

$projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lrg-api-integration-' . bin2hex(random_bytes(6));
$routesPath = $projectRoot . DIRECTORY_SEPARATOR . 'routes';
$bootstrapPath = $projectRoot . DIRECTORY_SEPARATOR . 'bootstrap';
$appPath = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
    . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api';
foreach ([$routesPath, $bootstrapPath, $appPath, $projectRoot . DIRECTORY_SEPARATOR . 'storage'] as $directory) {
    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create Laravel integration fixture directory: {$directory}");
    }
}
mkdir($bootstrapPath . DIRECTORY_SEPARATOR . 'cache', 0777, true);

$models = ['User', 'BlogPost'];
$controllerTemplate = <<<'PHP'
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

class %sController
{
    public function index() { return response()->json(['action' => 'index']); }
    public function store(Request $request) { return response()->json(['action' => 'store'], 201); }
    public function show(string $id) { return response()->json(['action' => 'show', 'id' => $id]); }
    public function update(Request $request, string $id) { return response()->json(['action' => 'update', 'id' => $id]); }
    public function destroy(string $id) { return response()->json(null, 204); }
    public function syncBlogPost(string $id) { return response()->json(['action' => 'sync', 'id' => $id]); }
}
PHP;

try {
    file_put_contents($projectRoot . DIRECTORY_SEPARATOR . '.env', "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n");
    file_put_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'composer.json',
        json_encode(
            ['name' => 'tests/lrg-api-app', 'autoload' => ['psr-4' => ['App\\' => 'app/']]],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . "\n"
    );
    file_put_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'artisan',
        "<?php\n\$loader = require " . var_export($argv[1], true) . ";\n"
            . "\$loader->addPsr4('App\\\\', __DIR__ . '/app');\n"
            . "\$app = require __DIR__ . '/bootstrap/app.php';\n"
            . "exit(\$app->handleCommand(new \\Symfony\\Component\\Console\\Input\\ArgvInput()));\n"
    );
    file_put_contents(
        $bootstrapPath . DIRECTORY_SEPARATOR . 'app.php',
        <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(apiPrefix: 'v2')
    ->withMiddleware()
    ->withExceptions()
    ->create();
PHP
    );
    file_put_contents(
        $routesPath . DIRECTORY_SEPARATOR . 'api.php',
        "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/health', fn () => response()->json(['ok' => true]));\n"
    );

    foreach ($models as $model) {
        $controller = sprintf($controllerTemplate, $model);
        if ($model === 'BlogPost') {
            $controller = str_replace('public function destroy(', 'private function destroy(', $controller);
        }
        file_put_contents(
            $appPath . DIRECTORY_SEPARATOR . $model . 'Controller.php',
            $controller
        );
    }

    $generator = new ApiRouteGenerator();
    $associations = [[
        'parentModelClass' => 'App\\Models\\User',
        'relatedModelClass' => 'App\\Models\\BlogPost',
        'relation' => 'blogPosts',
    ]];
    $generator->generate(
        $projectRoot,
        ['App\\Models\\User', 'App\\Models\\BlogPost'],
        $associations
    );
    $bootstrapBeforeRepeat = file_get_contents($bootstrapPath . DIRECTORY_SEPARATOR . 'app.php');
    $apiBeforeRepeat = file_get_contents($routesPath . DIRECTORY_SEPARATOR . 'api.php');
    $generator->generate($projectRoot, ['App\\Models\\User', 'App\\Models\\BlogPost'], $associations);
    if (file_get_contents($bootstrapPath . DIRECTORY_SEPARATOR . 'app.php') !== $bootstrapBeforeRepeat
        || file_get_contents($routesPath . DIRECTORY_SEPARATOR . 'api.php') !== $apiBeforeRepeat) {
        throw new RuntimeException('Repeating route generation changed the prepared application.');
    }
    $inspection = (new RegisteredApiRouteInspector())->inspect($projectRoot);
    if ($inspection['api_prefix'] !== 'v2'
        || $inspection['entities'][0]['operations']['show']['parameters'] !== ['user']
        || isset($inspection['entities'][1]['operations']['destroy'])
        || $inspection['associations'][0]['uri'] !== 'v2/users/{user}/blog_posts'
        || $inspection['associations'][0]['methods'] !== ['PUT']) {
        throw new RuntimeException('Unexpected registered route inventory: ' . var_export($inspection, true));
    }
    $loader->addPsr4('App\\', $projectRoot . DIRECTORY_SEPARATOR . 'app');

    $process = new Process(
        [PHP_BINARY, $projectRoot . DIRECTORY_SEPARATOR . 'artisan', 'route:list', '--path=v2'],
        $projectRoot
    );
    $process->run();
    if (!$process->isSuccessful()) {
        throw new RuntimeException(
            "php artisan route:list failed:\n" . $process->getOutput() . $process->getErrorOutput()
        );
    }
    $routeList = $process->getOutput();

    $app = require $bootstrapPath . DIRECTORY_SEPARATOR . 'app.php';

    foreach ([
        'GET|HEAD',
        'POST',
        'PUT|PATCH',
        'DELETE',
        'v2/users',
        'v2/users/{user}',
        'v2/blog_posts',
        'v2/blog_posts/{blog_post}',
        'v2/health',
        'v2/users/{user}/blog_posts',
        'UserController@index',
        'UserController@store',
        'UserController@show',
        'UserController@update',
        'UserController@destroy',
    ] as $expected) {
        if (!str_contains($routeList, $expected)) {
            throw new RuntimeException("route:list output omitted '{$expected}':\n{$routeList}");
        }
    }

    $httpKernel = $app->make(HttpKernel::class);
    $responses = [
        ['GET', '/v2/users', 200, 'index'],
        ['POST', '/v2/users', 201, 'store'],
        ['GET', '/v2/users/42', 200, 'show'],
        ['PATCH', '/v2/users/42', 200, 'update'],
        ['DELETE', '/v2/users/42', 204, null],
        ['GET', '/v2/blog_posts/9', 200, 'show'],
        ['PUT', '/v2/users/42/blog_posts', 200, 'sync'],
    ];
    foreach ($responses as [$method, $uri, $expectedStatus, $expectedAction]) {
        $response = $httpKernel->handle(Request::create($uri, $method));
        if ($response->getStatusCode() !== $expectedStatus) {
            throw new RuntimeException(
                "{$method} {$uri} returned {$response->getStatusCode()}, expected {$expectedStatus}."
            );
        }
        if ($expectedAction !== null && ($response->getData(true)['action'] ?? null) !== $expectedAction) {
            throw new RuntimeException("{$method} {$uri} reached the wrong controller action.");
        }
        $httpKernel->terminate(Request::create($uri, $method), $response);
    }

    fwrite(STDOUT, "PASS Laravel route:list and HTTP requests for generated API resources\n");
} finally {
    foreach (glob($appPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $controller) {
        unlink($controller);
    }
    $bootstrapCache = $bootstrapPath . DIRECTORY_SEPARATOR . 'cache';
    foreach (glob($bootstrapCache . DIRECTORY_SEPARATOR . '*') ?: [] as $cacheFile) {
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }
    }
    $storageLogs = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    foreach (glob($storageLogs . DIRECTORY_SEPARATOR . '*') ?: [] as $logFile) {
        if (is_file($logFile)) {
            unlink($logFile);
        }
    }

    foreach ([
        $routesPath . DIRECTORY_SEPARATOR . 'api.php',
        $routesPath . DIRECTORY_SEPARATOR . 'lrg_api.php',
        $bootstrapPath . DIRECTORY_SEPARATOR . 'app.php',
        $projectRoot . DIRECTORY_SEPARATOR . 'artisan',
        $projectRoot . DIRECTORY_SEPARATOR . '.env',
        $projectRoot . DIRECTORY_SEPARATOR . 'composer.json',
    ] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([
        $appPath,
        dirname($appPath),
        dirname(dirname($appPath)),
        dirname(dirname(dirname($appPath))),
        $routesPath,
        $bootstrapPath . DIRECTORY_SEPARATOR . 'cache',
        $bootstrapPath,
        $storageLogs,
        $projectRoot . DIRECTORY_SEPARATOR . 'storage',
        $projectRoot,
    ] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
