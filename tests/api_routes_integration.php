<?php

use Franciscovillaquiran\LaravelRelationGenerator\Generators\ApiRouteGenerator;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

if (!isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Pass an autoloader that provides laravel/framework.');
}

$loader = require $argv[1];
$root = dirname(__DIR__);
require_once $root . '/src/Generators/ApiRouteGenerator.php';

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
    ->withRouting(api: __DIR__ . '/../routes/api.php')
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
        file_put_contents(
            $appPath . DIRECTORY_SEPARATOR . $model . 'Controller.php',
            sprintf($controllerTemplate, $model)
        );
    }

    (new ApiRouteGenerator())->generate(
        $projectRoot,
        ['App\\Models\\User', 'App\\Models\\BlogPost']
    );
    $loader->addPsr4('App\\', $projectRoot . DIRECTORY_SEPARATOR . 'app');

    $process = new Process(
        [PHP_BINARY, $projectRoot . DIRECTORY_SEPARATOR . 'artisan', 'route:list', '--path=api'],
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
        'api/users',
        'api/users/{user}',
        'api/blog_posts',
        'api/blog_posts/{blog_post}',
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
        ['GET', '/api/users', 200, 'index'],
        ['POST', '/api/users', 201, 'store'],
        ['GET', '/api/users/42', 200, 'show'],
        ['PATCH', '/api/users/42', 200, 'update'],
        ['DELETE', '/api/users/42', 204, null],
        ['GET', '/api/blog_posts/9', 200, 'show'],
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
