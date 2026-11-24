<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use RuntimeException;
use Symfony\Component\Process\Process;

class RegisteredApiRouteInspector
{
    /**
     * Boot a fresh Artisan process so the result reflects Laravel's actual route
     * collection, including any route cache, rather than the current command's
     * already-booted router.
     */
    public function inspect(string $projectRoot): array
    {
        $artisan = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'artisan';
        if (!is_file($artisan)) {
            throw new RuntimeException("Cannot inspect registered routes: artisan not found at {$artisan}.");
        }
        $process = new Process([PHP_BINARY, $artisan, 'route:list', '--json'], $projectRoot);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Could not inspect registered Laravel routes: '
                . trim($process->getErrorOutput() . "\n" . $process->getOutput())
            );
        }
        try {
            $routes = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Laravel route:list did not return valid JSON.', 0, $exception);
        }
        if (!is_array($routes) || !array_is_list($routes)) {
            throw new RuntimeException('Laravel route:list returned an unexpected route collection.');
        }
        return $this->matchDeclaredRoutes($projectRoot, $routes);
    }

    /**
     * @param list<array<string, mixed>> $routes JSON output from Laravel route:list
     * @return array{api_prefix: string, entities: array, associations: array}
     */
    public function matchDeclaredRoutes(string $projectRoot, array $routes): array
    {
        $declared = (new ApiRouteGenerator())->declarations($projectRoot);
        $prefix = null;
        $entities = [];
        $operations = [
            'index' => ['GET', false],
            'store' => ['POST', false],
            'show' => ['GET', true],
            'update' => ['PUT', true],
            'destroy' => ['DELETE', true],
        ];
        foreach ($declared['resources'] as $model) {
            $controller = 'App\\Http\\Controllers\\Api\\' . $model['controller'];
            $entity = ['model' => $model['class'], 'resource' => $model['resource'], 'operations' => []];
            foreach ($operations as $operation => [$method, $individual]) {
                if (!in_array($operation, $model['operations'], true)) {
                    continue;
                }
                $suffix = preg_quote($model['resource'], '~')
                    . ($individual ? '/\{[^/{}]+\}' : '');
                $route = $this->findRoute($routes, $controller . '@' . $operation, $method, $suffix, $prefix);
                if ($operation === 'update' && !in_array('PATCH', $route['methods'], true)) {
                    throw new RuntimeException("Registered update route for {$controller} does not accept PATCH.");
                }
                $entity['operations'][$operation] = $route;
            }
            $entities[] = $entity;
        }
        $associations = [];
        foreach ($declared['associations'] as $association) {
            $controller = 'App\\Http\\Controllers\\Api\\' . $association['controller'];
            $route = $this->findRoute(
                $routes,
                $controller . '@' . $association['action'],
                strtoupper($association['method']),
                preg_quote(ltrim($association['uri'], '/'), '~'),
                $prefix
            );
            $associations[] = $route;
        }
        if ($prefix === null) {
            throw new RuntimeException('No LRG-managed API resources were found for route verification.');
        }
        return ['api_prefix' => $prefix, 'entities' => $entities, 'associations' => $associations];
    }

    private function findRoute(array $routes, string $action, string $method, string $suffix, ?string &$prefix): array
    {
        $matches = [];
        foreach ($routes as $route) {
            if (!is_array($route) || !isset($route['action'], $route['method'], $route['uri'])
                || !is_string($route['action']) || !is_string($route['method']) || !is_string($route['uri'])
                || ltrim($route['action'], '\\') !== $action) {
                continue;
            }
            $methods = explode('|', $route['method']);
            if (!in_array($method, $methods, true)
                || preg_match('~^(?:(.*)/)?' . $suffix . '$~D', ltrim($route['uri'], '/'), $parts) !== 1) {
                continue;
            }
            $matches[] = [$route, $methods, $parts[1] ?? ''];
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(
                "Expected exactly one registered {$method} route for {$action}; found " . count($matches)
                . '. Check route registration, conflicts, and route cache (php artisan route:clear).'
            );
        }
        [$route, $methods, $routePrefix] = $matches[0];
        if (str_contains($routePrefix, '{') || ($prefix !== null && $prefix !== $routePrefix)) {
            throw new RuntimeException("Registered LRG routes have an ambiguous or inconsistent API prefix: {$routePrefix}.");
        }
        $prefix = $routePrefix;
        preg_match_all('/\{([^}]+)\}/', $route['uri'], $parameters);
        return [
            'methods' => $methods,
            'uri' => $route['uri'],
            'parameters' => $parameters[1],
            'name' => $route['name'] ?? null,
            'action' => $action,
            'domain' => $route['domain'] ?? null,
        ];
    }
}
