<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use Illuminate\Support\Str;
use RuntimeException;

class ApiRouteGenerator
{
    private const BEGIN_MARKER = '// LRG API RESOURCES BEGIN';
    private const END_MARKER = '// LRG API RESOURCES END';
    private const INCLUDE_MARKER = '// LRG API ROUTES INCLUDE';

    /**
     * Register CRUD API resources for model classes in an existing Laravel app.
     *
     * @param list<string> $modelClasses
     * @param list<array{parentModelClass: string, relatedModelClass: string, relation: string}> $associations
     */
    public function generate(string $projectRoot, array $modelClasses, array $associations = []): void
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $routesDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'routes';
        $apiRoutesPath = $routesDirectory . DIRECTORY_SEPARATOR . 'api.php';
        $managedRoutesPath = $routesDirectory . DIRECTORY_SEPARATOR . 'lrg_api.php';
        $controllersDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers'
            . DIRECTORY_SEPARATOR . 'Api';

        if (!is_file($apiRoutesPath)) {
            throw new RuntimeException(
                "API routes are not enabled: expected {$apiRoutesPath}. Enable/register Laravel API routes before generating CRUD routes."
            );
        }

        $this->assertApiRoutesRegistered($projectRoot, $apiRoutesPath);
        $apiSource = $this->readPhpFile($apiRoutesPath);
        $legacySource = is_file($managedRoutesPath)
            ? $this->readPhpFile($managedRoutesPath)
            : null;
        $models = $this->mergeManagedModels(
            array_merge(
                $legacySource === null ? [] : $this->managedModels($legacySource, $managedRoutesPath),
                $this->managedModels($apiSource, $apiRoutesPath)
            ),
            $this->normalizeModels($modelClasses)
        );

        $existingAssociationRoutes = array_merge(
            $legacySource === null ? [] : $this->managedAssociationRoutes($legacySource, $managedRoutesPath),
            $this->managedAssociationRoutes($apiSource, $apiRoutesPath)
        );
        $requestedAssociationRoutes = $this->normalizeAssociations($associations, $models);
        $existingAssociationRoutes = $this->removeSupersededAssociationRoutes(
            $existingAssociationRoutes,
            $requestedAssociationRoutes
        );
        $associationRoutes = array_merge($existingAssociationRoutes, $requestedAssociationRoutes);
        $associationRoutes = $this->uniqueAssociationRoutes($associationRoutes);
        $requiredActions = [];
        foreach ($associationRoutes as $association) {
            $requiredActions[$association['controller']][] = $association['action'];
        }

        foreach ($models as $model) {
            $controllerPath = $controllersDirectory . DIRECTORY_SEPARATOR . $model['controller'] . '.php';
            $this->assertControllerActions(
                $controllerPath,
                $model['controller'],
                array_values(array_unique($requiredActions[$model['controller']] ?? []))
            );
        }

        [$apiUpdated, $legacyIncluded] = $this->removeLegacyInclude(
            $apiSource,
            $apiRoutesPath,
            $legacySource !== null
        );
        $legacyManual = $legacySource === null || !$legacyIncluded
            ? ['imports' => [], 'body' => '']
            : $this->legacyManualContent($legacySource, $managedRoutesPath);
        if ($legacyIncluded) {
            $apiUpdated = $this->addImports($apiUpdated, $legacyManual['imports'], $apiRoutesPath);
            if ($legacyManual['body'] !== '') {
                $apiUpdated = $this->insertBeforeClosingTag(
                    $apiUpdated,
                    "\n\n" . $legacyManual['body'] . "\n"
                );
            }
        }
        $apiUpdated = $this->ensureRouteImport($apiUpdated, $apiRoutesPath);
        $resourceCalls = [];
        foreach ($models as $model) {
            $resourceCalls[$model['resource']] = $model['controller'];
        }

        $this->assertNoRouteConflicts($apiUpdated, $apiRoutesPath, $resourceCalls);
        $this->assertNoAssociationRouteConflicts($apiUpdated, $apiRoutesPath, $associationRoutes);
        $apiUpdated = $this->replaceManagedBlock(
            $apiUpdated,
            $this->buildManagedBlock($models, $associationRoutes),
            $apiRoutesPath
        );
        $this->assertPhpSyntax($apiUpdated, $apiRoutesPath);

        $this->writeAtomically($apiRoutesPath, $apiUpdated);
        if ($legacyIncluded && $legacySource !== null && !@unlink($managedRoutesPath)) {
            throw new RuntimeException("Could not remove obsolete API routes file: {$managedRoutesPath}");
        }
    }

    /**
     * @param list<string> $modelClasses
     * @return list<array{class: string, controller: string, resource: string}>
     */
    private function normalizeModels(array $modelClasses): array
    {
        if ($modelClasses === []) {
            throw new RuntimeException('At least one model class is required to generate API routes.');
        }

        $models = [];
        $resources = [];
        foreach ($modelClasses as $modelClass) {
            if (!is_string($modelClass)) {
                throw new RuntimeException('Model classes must be strings.');
            }

            $modelClass = ltrim($modelClass, '\\');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $modelClass) !== 1) {
                throw new RuntimeException("Invalid model class name: {$modelClass}");
            }

            $separator = strrpos($modelClass, '\\');
            $modelName = $separator === false ? $modelClass : substr($modelClass, $separator + 1);
            $resource = Str::plural(Str::snake($modelName));
            if (isset($resources[$resource])) {
                throw new RuntimeException(
                    "Model classes resolve to the same API resource '{$resource}'."
                );
            }

            $resources[$resource] = true;
            $models[] = [
                'class' => $modelClass,
                'controller' => $modelName . 'Controller',
                'resource' => $resource,
            ];
        }

        return $models;
    }

    /**
     * @return list<array{class: string, controller: string, resource: string}>
     */
    private function managedModels(string $source, string $path): array
    {
        $models = [];
        $resources = [];
        foreach ($this->managedRouteLines($source, $path) as $line) {
            if ($this->parseManagedAssociationRoute($line) !== null) {
                continue;
            }
            if (!preg_match(
                '/^\s*Route::apiResource\([\'"]([A-Za-z0-9_-]+)[\'"],\s*\\\\App\\\\Http\\\\Controllers\\\\Api\\\\([A-Za-z_][A-Za-z0-9_]*)::class\);\s*$/',
                $line,
                $matches
            ) || !str_ends_with($matches[2], 'Controller')) {
                throw new RuntimeException(
                    "The LRG-managed route block in {$path} contains an unsupported manual edit."
                );
            }

            $resource = $matches[1];
            $controller = $matches[2];
            if (isset($resources[$resource])) {
                throw new RuntimeException("The LRG-managed route block duplicates API resource '{$resource}'.");
            }
            $resources[$resource] = $controller;
            $models[] = [
                'class' => 'App\\Models\\' . substr($controller, 0, -strlen('Controller')),
                'controller' => $controller,
                'resource' => $resource,
            ];
        }

        return $models;
    }

    /**
     * @return list<string>
     */
    private function managedRouteLines(string $source, string $path): array
    {
        $beginCount = substr_count($source, self::BEGIN_MARKER);
        $endCount = substr_count($source, self::END_MARKER);
        if ($beginCount !== $endCount || $beginCount > 1) {
            throw new RuntimeException("Ambiguous LRG-managed route markers in {$path}.");
        }
        if ($beginCount === 0) {
            return [];
        }

        $begin = strpos($source, self::BEGIN_MARKER);
        $end = strpos($source, self::END_MARKER, $begin);
        if ($end === false) {
            throw new RuntimeException("Incomplete LRG-managed route block in {$path}.");
        }
        $body = trim(substr($source, $begin + strlen(self::BEGIN_MARKER), $end - $begin - strlen(self::BEGIN_MARKER)));

        return $body === '' ? [] : preg_split('/\R/', $body);
    }

    /**
     * @return list<array{method: string, uri: string, controller: string, action: string, line: string, legacyUris?: list<string>}>
     */
    private function managedAssociationRoutes(string $source, string $path): array
    {
        $routes = [];
        foreach ($this->managedRouteLines($source, $path) as $line) {
            $route = $this->parseManagedAssociationRoute($line);
            if ($route !== null) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    private function parseManagedAssociationRoute(string $line): ?array
    {
        if (!preg_match(
            '/^\s*Route::(put|post|delete)\([\'"]([^\'"]+)[\'"],\s*\[\\\\App\\\\Http\\\\Controllers\\\\Api\\\\([A-Za-z_][A-Za-z0-9_]*)::class,\s*[\'"]((?:sync|attach|detach)[A-Za-z_][A-Za-z0-9_]*)[\'"]\]\);\s*$/',
            $line,
            $matches
        )) {
            return null;
        }

        return [
            'method' => strtolower($matches[1]),
            'uri' => $matches[2],
            'controller' => $matches[3],
            'action' => $matches[4],
            'line' => trim($line),
        ];
    }

    /**
     * @param list<array{parentModelClass: string, relatedModelClass: string, relation: string}> $associations
     * @param list<array{class: string, controller: string, resource: string}> $models
     * @return list<array{method: string, uri: string, controller: string, action: string, line: string, legacyUris?: list<string>}>
     */
    private function normalizeAssociations(array $associations, array $models): array
    {
        $modelClasses = array_column($models, 'class');
        $routes = [];
        foreach ($associations as $association) {
            if (!is_array($association)) {
                throw new RuntimeException('Each association must define its model classes and relation method.');
            }
            $parentClass = $this->normalizeModelClass($association['parentModelClass'] ?? null);
            $relatedClass = $this->normalizeModelClass($association['relatedModelClass'] ?? null);
            $relation = $association['relation'] ?? null;
            if (!is_string($relation) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $relation) !== 1) {
                throw new RuntimeException('Association relationship names must be valid PHP identifiers.');
            }
            if (!in_array($parentClass, $modelClasses, true) || !in_array($relatedClass, $modelClasses, true)) {
                throw new RuntimeException('Association models must also have generated API resources.');
            }

            $parentName = $this->modelBasename($parentClass);
            $relatedName = $this->modelBasename($relatedClass);
            $parentResource = Str::plural(Str::snake($parentName));
            $relatedResource = Str::plural(Str::snake($relatedName));
            $parentParameter = Str::camel(Str::singular($parentResource));
            $relatedParameter = Str::camel(Str::singular($relatedResource));
            $controller = $parentName . 'Controller';
            $methodSuffix = Str::studly($relatedName);

            $uri = "/{$parentResource}/{{$parentParameter}}/{$relatedResource}";
            $action = 'sync' . $methodSuffix;
            $line = "Route::put('{$uri}', [\\App\\Http\\Controllers\\Api\\{$controller}::class, '{$action}']);";
            $routes[] = [
                'method' => 'put',
                'uri' => $uri,
                'controller' => $controller,
                'action' => $action,
                'line' => $line,
                'legacyUris' => [
                    $uri . "/{{$relatedParameter}}",
                    "/{$relatedResource}/{{$relatedParameter}}/{$parentResource}/{{$parentParameter}}",
                ],
            ];
        }

        return $routes;
    }

    /**
     * @param list<array<string, mixed>> $existingRoutes
     * @param list<array<string, mixed>> $requestedRoutes
     * @return list<array<string, mixed>>
     */
    private function removeSupersededAssociationRoutes(array $existingRoutes, array $requestedRoutes): array
    {
        $legacyUris = [];
        foreach ($requestedRoutes as $route) {
            foreach ($route['legacyUris'] as $uri) {
                $legacyUris[$uri] = true;
            }
        }

        return array_values(array_filter(
            $existingRoutes,
            static fn (array $route): bool => !in_array($route['method'], ['post', 'delete'], true)
                || !isset($legacyUris[$route['uri']])
        ));
    }

    private function normalizeModelClass(mixed $modelClass): string
    {
        if (!is_string($modelClass)) {
            throw new RuntimeException('Association model classes must be strings.');
        }
        $modelClass = ltrim($modelClass, '\\');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $modelClass) !== 1) {
            throw new RuntimeException("Invalid association model class name: {$modelClass}");
        }

        return $modelClass;
    }

    private function modelBasename(string $modelClass): string
    {
        $separator = strrpos($modelClass, '\\');
        return $separator === false ? $modelClass : substr($modelClass, $separator + 1);
    }

    /**
     * @param list<array{method: string, uri: string, controller: string, action: string, line: string}> $routes
     * @return list<array{method: string, uri: string, controller: string, action: string, line: string}>
     */
    private function uniqueAssociationRoutes(array $routes): array
    {
        $unique = [];
        foreach ($routes as $route) {
            $unique[$route['line']] = $route;
        }

        return array_values($unique);
    }

    /**
     * @param list<array{class: string, controller: string, resource: string}> $existing
     * @param list<array{class: string, controller: string, resource: string}> $requested
     * @return list<array{class: string, controller: string, resource: string}>
     */
    private function mergeManagedModels(array $existing, array $requested): array
    {
        $models = [];
        foreach (array_merge($existing, $requested) as $model) {
            $resource = $model['resource'];
            if (isset($models[$resource])) {
                if ($models[$resource]['controller'] !== $model['controller']) {
                    throw new RuntimeException(
                        "Multiple models resolve to the managed API resource '{$resource}'."
                    );
                }
                continue;
            }
            $models[$resource] = $model;
        }

        return array_values($models);
    }

    private function assertApiRoutesRegistered(string $projectRoot, string $apiRoutesPath): void
    {
        $bootstrapPath = $projectRoot . DIRECTORY_SEPARATOR . 'bootstrap'
            . DIRECTORY_SEPARATOR . 'app.php';
        $providerPath = $projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'Providers' . DIRECTORY_SEPARATOR . 'RouteServiceProvider.php';

        if (is_file($bootstrapPath)) {
            $source = $this->codeWithoutComments($this->readPhpFile($bootstrapPath));
            if ($this->hasLaravel11ApiRouteRegistration($source)) {
                return;
            }
        }

        if (is_file($providerPath)) {
            $source = $this->codeWithoutComments($this->readPhpFile($providerPath));
            if (
                preg_match('/routes[\\\\\/]+api\.php/i', $source) === 1
                && preg_match('/middleware\s*\(\s*[\'"]api[\'"]\s*\)/i', $source) === 1
                && preg_match('/prefix\s*\(\s*[\'"]api[\'"]\s*\)/i', $source) === 1
            ) {
                return;
            }
        }

        throw new RuntimeException(
            "Could not verify that {$apiRoutesPath} is registered by Laravel. "
            . 'Register it in bootstrap/app.php (Laravel 11+) or app/Providers/RouteServiceProvider.php (Laravel 10) before generating API routes.'
        );
    }

    private function hasLaravel11ApiRouteRegistration(string $source): bool
    {
        if (
            preg_match_all('/withRouting\s*\(/', $source) !== 1
            || !preg_match(
                '/withRouting\s*\(\s*(api|web|pages|commands|channels|health|apiPrefix|then)\s*:/i',
                $source
            )
            || preg_match('/withRouting\s*\(\s*using\s*:/i', $source) === 1
            || !preg_match('/withRouting\s*\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*:/', $source, $firstArgument)
            || strtolower($firstArgument[1]) === 'using'
        ) {
            return false;
        }

        return preg_match(
            '/\bapi\s*:\s*(?:(?:__DIR__|base_path\s*\([^)]*\))\s*\.\s*)?[\'"][^\'"]*routes[\\\\\/]+api\.php[\'"]/i',
            $source
        ) === 1;
    }

    private function assertControllerActions(
        string $controllerPath,
        string $controllerName,
        array $additionalActions = []
    ): void
    {
        if (!is_file($controllerPath)) {
            throw new RuntimeException("CRUD controller not found: {$controllerPath}");
        }

        $source = file_get_contents($controllerPath);
        if ($source === false) {
            throw new RuntimeException("Could not read CRUD controller: {$controllerPath}");
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new RuntimeException(
                "CRUD controller has invalid PHP syntax: {$controllerPath}",
                previous: $exception
            );
        }

        $classFound = false;
        $classOpen = null;
        $namespace = '';
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
                    if ($tokens[$cursor] === ';' || $tokens[$cursor] === '{') {
                        break;
                    }
                    if (is_array($tokens[$cursor]) && in_array(
                        $tokens[$cursor][0],
                        [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR],
                        true
                    )) {
                        $namespace .= $tokens[$cursor][1];
                    }
                }
                break;
            }
        }

        if ($namespace !== 'App\\Http\\Controllers\\Api') {
            throw new RuntimeException(
                "CRUD controller {$controllerPath} must use namespace App\\Http\\Controllers\\Api."
            );
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            $next = $this->nextSignificantToken($tokens, $index + 1);
            if ($next === null || !is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING
                || $tokens[$next][1] !== $controllerName) {
                continue;
            }

            $classFound = true;
            for ($cursor = $next + 1; $cursor < count($tokens); $cursor++) {
                if ($tokens[$cursor] === '{') {
                    $classOpen = $cursor;
                    break;
                }
            }
            break;
        }

        if (!$classFound || $classOpen === null) {
            throw new RuntimeException(
                "Expected class {$controllerName} in CRUD controller {$controllerPath}."
            );
        }

        $methods = [];
        $depth = 1;
        for ($index = $classOpen + 1; $index < count($tokens) && $depth > 0; $index++) {
            $token = $tokens[$index];
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }

            if ($depth !== 1 || !is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $methodNameIndex = $this->nextSignificantToken($tokens, $index + 1);
            if ($methodNameIndex !== null && $tokens[$methodNameIndex] === '&') {
                $methodNameIndex = $this->nextSignificantToken($tokens, $methodNameIndex + 1);
            }
            if ($methodNameIndex === null || !is_array($tokens[$methodNameIndex])
                || $tokens[$methodNameIndex][0] !== T_STRING) {
                continue;
            }

            if ($this->isPublicMethod($tokens, $index)) {
                $methods[$tokens[$methodNameIndex][1]] = true;
            }
        }

        $missing = array_values(array_diff(
            array_merge(['index', 'store', 'show', 'update', 'destroy'], $additionalActions),
            array_keys($methods)
        ));
        if ($missing !== []) {
            throw new RuntimeException(
                "CRUD controller {$controllerPath} is missing public actions: " . implode(', ', $missing)
            );
        }
    }

    private function isPublicMethod(array $tokens, int $functionIndex): bool
    {
        for ($index = $functionIndex - 1, $checked = 0; $index >= 0 && $checked < 12; $index--) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT], true)) {
                continue;
            }

            if (is_array($token) && $token[0] === T_PUBLIC) {
                return true;
            }
            if (is_array($token) && in_array($token[0], [T_PRIVATE, T_PROTECTED], true)) {
                return false;
            }
            if ($token === ';' || $token === '}' || $token === '{') {
                return false;
            }
            $checked++;
        }

        return false;
    }

    private function buildManagedBlock(array $models, array $associationRoutes): string
    {
        $lines = [self::BEGIN_MARKER];
        foreach ($models as $model) {
            $lines[] = "Route::apiResource('{$model['resource']}', \\App\\Http\\Controllers\\Api\\{$model['controller']}::class);";
        }
        foreach ($associationRoutes as $route) {
            $lines[] = $route['line'];
        }
        $lines[] = self::END_MARKER;

        return implode("\n", $lines);
    }

    private function replaceManagedBlock(string $source, string $block, string $path): string
    {
        $beginCount = substr_count($source, self::BEGIN_MARKER);
        $endCount = substr_count($source, self::END_MARKER);
        if ($beginCount !== $endCount || $beginCount > 1) {
            throw new RuntimeException("Ambiguous LRG-managed route markers in {$path}.");
        }

        if ($beginCount === 1) {
            $begin = strpos($source, self::BEGIN_MARKER);
            $end = strpos($source, self::END_MARKER, $begin);
            if ($begin === false || $end === false) {
                throw new RuntimeException("Incomplete LRG-managed route block in {$path}.");
            }
            $end += strlen(self::END_MARKER);

            return substr_replace($source, $block, $begin, $end - $begin);
        }

        return $this->insertBeforeClosingTag($source, "\n\n{$block}\n");
    }

    private function ensureRouteImport(string $source, string $path): string
    {
        if ($this->hasRouteFacadeImport($source)) {
            return $source;
        }

        if (preg_match('/^\s*use\s+[^;]*\bRoute\s*;/mi', $source) === 1) {
            throw new RuntimeException("A conflicting Route import prevents API route generation in {$path}.");
        }

        $tokens = token_get_all($source, TOKEN_PARSE);
        $insertAt = null;
        $namespaceEnd = null;
        $declareEnd = null;
        $offset = 0;
        $namespaceSeen = false;
        $declareSeen = false;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespaceSeen = true;
            } elseif (is_array($token) && $token[0] === T_DECLARE) {
                $declareSeen = true;
            }

            $offset += strlen($text);
            if (($namespaceSeen || $declareSeen) && $text === ';') {
                if ($namespaceSeen) {
                    $namespaceEnd = $offset;
                    $namespaceSeen = false;
                }
                if ($declareSeen) {
                    $declareEnd = $offset;
                    $declareSeen = false;
                }
            }
        }

        $insertAt = $namespaceEnd ?? $declareEnd;
        if ($insertAt === null) {
            $openTag = strpos($source, '<?php');
            if ($openTag === false) {
                throw new RuntimeException("Could not find PHP opening tag in {$path}.");
            }
            $insertAt = $openTag + strlen('<?php');
        }

        return substr_replace($source, "\nuse Illuminate\\Support\\Facades\\Route;\n", $insertAt, 0);
    }

    private function hasRouteFacadeImport(string $source): bool
    {
        return preg_match(
            '/^\s*use\s+Illuminate\\\\Support\\\\Facades\\\\Route(?:\s+as\s+Route)?\s*;/mi',
            $source
        ) === 1;
    }

    private function removeLegacyInclude(string $source, string $path, bool $legacyExists): array
    {
        $markerCount = substr_count($source, self::INCLUDE_MARKER);
        $includeLine = "require_once __DIR__ . '/lrg_api.php';";
        if ($markerCount > 1) {
            throw new RuntimeException("Ambiguous LRG API include markers in {$path}.");
        }
        if ($markerCount === 1) {
            $markerOffset = strpos($source, self::INCLUDE_MARKER);
            $includeOffset = strpos($source, $includeLine);
            if (substr_count($source, $includeLine) !== 1
                || $markerOffset === false
                || $includeOffset === false
                || $markerOffset > $includeOffset) {
                throw new RuntimeException("The existing LRG API route include in {$path} was modified.");
            }

            $start = strrpos(substr($source, 0, $markerOffset), "\n");
            $start = $start === false ? 0 : $start + 1;
            $end = strpos($source, "\n", $includeOffset);
            $end = $end === false ? strlen($source) : $end + 1;

            return [substr_replace($source, '', $start, $end - $start), $legacyExists];
        }
        if (str_contains($source, 'lrg_api.php')) {
            throw new RuntimeException(
                'routes/api.php references lrg_api.php without an LRG marker; refusing to alter a manual include.'
            );
        }

        return [$source, false];
    }

    /**
     * @return array{imports: list<string>, body: string}
     */
    private function legacyManualContent(string $source, string $path): array
    {
        $source = $this->withoutManagedBlock($source, $path);
        $imports = [];
        $source = preg_replace_callback(
            '/^[ \t]*use\s+[^;]+;[ \t]*(?:\r?\n|$)/mi',
            static function (array $matches) use (&$imports): string {
                $imports[] = trim($matches[0]);
                return '';
            },
            $source
        );
        $source = preg_replace('/<\?php|<\?=|\?>/i', '', $source);
        if (preg_match('/\bnamespace\b|\bdeclare\s*\(/', $source) === 1) {
            throw new RuntimeException(
                "The legacy route file {$path} contains declarations that cannot be moved into routes/api.php."
            );
        }

        return [
            'imports' => array_values(array_unique($imports)),
            'body' => trim((string) $source),
        ];
    }

    /**
     * @param list<string> $imports
     */
    private function addImports(string $source, array $imports, string $path): string
    {
        $additions = [];
        foreach ($imports as $import) {
            if (preg_match('/^\s*use\s+Illuminate\\\\Support\\\\Facades\\\\Route(?:\s+as\s+Route)?\s*;/i', $import)) {
                continue;
            }
            if (!str_contains($source, trim($import))) {
                $additions[] = trim($import);
            }
        }
        if ($additions === []) {
            return $source;
        }

        $tokens = token_get_all($source, TOKEN_PARSE);
        $offset = 0;
        $insertAt = null;
        $header = true;
        $inUse = false;
        $inDeclare = false;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array(
                $token[0],
                [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                true
            )) {
                $offset += strlen($text);
                continue;
            }
            if ($header && is_array($token) && $token[0] === T_DECLARE) {
                $inDeclare = true;
                $offset += strlen($text);
                continue;
            }
            if ($inDeclare) {
                $offset += strlen($text);
                if ($text === ';') {
                    $insertAt = $offset;
                    $inDeclare = false;
                }
                continue;
            }
            if ($header && is_array($token) && $token[0] === T_USE) {
                $inUse = true;
                $offset += strlen($text);
                continue;
            }
            if ($inUse) {
                $offset += strlen($text);
                if ($text === ';') {
                    $insertAt = $offset;
                    $inUse = false;
                }
                continue;
            }
            if ($header) {
                $header = false;
            }
            $offset += strlen($text);
        }
        if ($insertAt === null) {
            $openTag = strpos($source, '<?php');
            if ($openTag === false) {
                throw new RuntimeException("Could not find PHP opening tag in {$path}.");
            }
            $insertAt = $openTag + strlen('<?php');
        }

        return substr_replace($source, "\n" . implode("\n", $additions) . "\n", $insertAt, 0);
    }

    private function assertNoRouteConflicts(string $source, string $path, array $resources): void
    {
        $source = $this->withoutManagedBlock($source, $path);
        $code = $this->codeWithoutComments($source);
        $tokens = token_get_all($code, TOKEN_PARSE);
        $routeAliases = ['route'];
        if (preg_match_all(
            '/\buse\s+Illuminate\\\\Support\\\\Facades\\\\Route(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/i',
            $code,
            $imports,
            PREG_SET_ORDER
        ) > 0) {
            foreach ($imports as $import) {
                $routeAliases[] = strtolower($import[1] ?? 'Route');
            }
        }
        $routeMethods = [
            'apiResource' => true,
            'resource' => true,
            'get' => true,
            'head' => true,
            'post' => true,
            'put' => true,
            'patch' => true,
            'delete' => true,
            'match' => true,
            'any' => true,
        ];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array(
                $token[0],
                [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED],
                true
            )) {
                continue;
            }
            $routeClass = strtolower(ltrim($token[1], '\\'));
            if (!in_array($routeClass, $routeAliases, true)
                && $routeClass !== 'illuminate\\support\\facades\\route') {
                continue;
            }

            $scope = $this->nextSignificantToken($tokens, $index + 1);
            if ($scope === null || !is_array($tokens[$scope]) || $tokens[$scope][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $methodIndex = $this->nextSignificantToken($tokens, $scope + 1);
            if ($methodIndex === null || !is_array($tokens[$methodIndex])
                || !isset($routeMethods[$tokens[$methodIndex][1]])) {
                continue;
            }

            $openParen = $this->nextSignificantToken($tokens, $methodIndex + 1);
            if ($openParen === null || $tokens[$openParen] !== '(') {
                continue;
            }
            $arguments = $this->splitCallArguments($tokens, $openParen);
            $method = strtolower($tokens[$methodIndex][1]);
            $uriArgumentIndex = $method === 'match' ? 1 : 0;
            $uri = $this->literalStringArgument($arguments[$uriArgumentIndex] ?? []);
            if ($uri === null) {
                throw new RuntimeException(
                    "Cannot safely determine whether a dynamic route in {$path} conflicts with generated API resources."
                );
            }

            $methods = $method === 'match'
                ? $this->matchRouteMethods($arguments[0] ?? [])
                : [];
            foreach ($resources as $resource => $controller) {
                if (!$this->routeCallConflicts($method, $uri, $resource, $methods)) {
                    continue;
                }

                throw new RuntimeException(
                    "Route conflict in {$path}: an existing {$method} route can overlap API resource '{$resource}' ({$controller})."
                );
            }
        }

        foreach ($resources as $resource => $_controller) {
            if (preg_match(
                '/[\'"]' . preg_quote($resource, '/') . '\.(?:index|store|show|update|destroy)[\'"]/i',
                $code
            ) === 1) {
                throw new RuntimeException(
                    "Route name conflict in {$path} for API resource '{$resource}'."
                );
            }
        }
    }

    /**
     * @param list<array{method: string, uri: string, controller: string, action: string, line: string}> $associationRoutes
     */
    private function assertNoAssociationRouteConflicts(
        string $source,
        string $path,
        array $associationRoutes
    ): void {
        if ($associationRoutes === []) {
            return;
        }

        $source = $this->codeWithoutComments($this->withoutManagedBlock($source, $path));
        $tokens = token_get_all($source, TOKEN_PARSE);
        $routeAliases = ['route'];
        if (preg_match_all(
            '/\buse\s+Illuminate\\\\Support\\\\Facades\\\\Route(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/i',
            $source,
            $imports,
            PREG_SET_ORDER
        ) > 0) {
            foreach ($imports as $import) {
                $routeAliases[] = strtolower($import[1] ?? 'Route');
            }
        }

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array(
                $token[0],
                [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED],
                true
            )) {
                continue;
            }
            $routeClass = strtolower(ltrim($token[1], '\\'));
            if (!in_array($routeClass, $routeAliases, true)
                && $routeClass !== 'illuminate\\support\\facades\\route') {
                continue;
            }
            $scope = $this->nextSignificantToken($tokens, $index + 1);
            if ($scope === null || !is_array($tokens[$scope]) || $tokens[$scope][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $methodIndex = $this->nextSignificantToken($tokens, $scope + 1);
            if ($methodIndex === null || !is_array($tokens[$methodIndex])
                || !in_array(strtolower($tokens[$methodIndex][1]), ['put', 'post', 'delete', 'match', 'any'], true)) {
                continue;
            }
            $method = strtolower($tokens[$methodIndex][1]);
            $openParen = $this->nextSignificantToken($tokens, $methodIndex + 1);
            if ($openParen === null || $tokens[$openParen] !== '(') {
                continue;
            }
            $arguments = $this->splitCallArguments($tokens, $openParen);
            $uriIndex = $method === 'match' ? 1 : 0;
            $uri = $this->literalStringArgument($arguments[$uriIndex] ?? []);
            if ($uri === null) {
                throw new RuntimeException(
                    "Cannot safely determine whether a dynamic route in {$path} conflicts with generated associations."
                );
            }
            $methods = $method === 'match'
                ? $this->matchRouteMethods($arguments[0] ?? [])
                : [];
            foreach ($associationRoutes as $association) {
                if (!$this->routeSignatureConflicts(
                    $method,
                    $uri,
                    $methods,
                    $association['method'],
                    $association['uri']
                )) {
                    continue;
                }
                throw new RuntimeException(
                    "Route conflict in {$path}: an existing {$method} route overlaps generated association "
                    . "{$association['controller']}::{$association['action']}."
                );
            }
        }
    }

    private function routeSignatureConflicts(
        string $method,
        string $uri,
        array $matchedMethods,
        string $targetMethod,
        string $targetUri
    ): bool {
        $methods = match (strtolower($method)) {
            'any' => ['get', 'head', 'post', 'put', 'patch', 'delete'],
            'match' => array_map('strtolower', $matchedMethods),
            default => [strtolower($method)],
        };
        if (!in_array(strtolower($targetMethod), $methods, true)) {
            return false;
        }

        $sourceSegments = explode('/', trim($uri, '/'));
        $targetSegments = explode('/', trim($targetUri, '/'));
        if (count($sourceSegments) !== count($targetSegments)) {
            return false;
        }
        foreach ($sourceSegments as $index => $sourceSegment) {
            $targetSegment = $targetSegments[$index];
            $sourceParameter = str_starts_with($sourceSegment, '{') && str_ends_with($sourceSegment, '}');
            $targetParameter = str_starts_with($targetSegment, '{') && str_ends_with($targetSegment, '}');
            if (!$sourceParameter && !$targetParameter && $sourceSegment !== $targetSegment) {
                return false;
            }
        }

        return true;
    }

    private function routeCallConflicts(
        string $method,
        string $uri,
        string $resource,
        array $matchedMethods = []
    ): bool
    {
        $uri = trim($uri, '/');
        $segments = $uri === '' ? [] : explode('/', $uri);
        $resourceSegments = explode('/', $resource);
        $collectionRoute = count($segments) === count($resourceSegments);
        $memberRoute = count($segments) === count($resourceSegments) + 1;
        if ($collectionRoute) {
            foreach ($segments as $index => $segment) {
                if ($segment !== $resourceSegments[$index]) {
                    $collectionRoute = false;
                    break;
                }
            }
        }
        if ($memberRoute) {
            foreach ($resourceSegments as $index => $segment) {
                if ($segments[$index] !== $segment) {
                    $memberRoute = false;
                    break;
                }
            }
            $parameter = $segments[count($segments) - 1];
            $memberRoute = $memberRoute
                && str_starts_with($parameter, '{')
                && str_ends_with($parameter, '}');
        }

        if (in_array($method, ['apiResource', 'resource'], true)) {
            return $collectionRoute || $memberRoute;
        }
        if ($method === 'match' || $method === 'any') {
            $expectedMethods = $collectionRoute
                ? ['GET', 'HEAD', 'POST']
                : ($memberRoute ? ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] : []);
            $methods = $method === 'any'
                ? ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']
                : $matchedMethods;
            return array_intersect($expectedMethods, $methods) !== [];
        }
        if ($collectionRoute) {
            return in_array(strtoupper($method), ['GET', 'HEAD', 'POST'], true);
        }
        if ($memberRoute) {
            return in_array(strtoupper($method), ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'], true);
        }
        return false;
    }

    private function splitCallArguments(array $tokens, int $openParenthesis): array
    {
        $arguments = [];
        $current = [];
        $depth = 0;
        for ($index = $openParenthesis + 1; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($token === ')' && $depth === 0) {
                $arguments[] = $current;
                break;
            }
            if ($token === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = [];
                continue;
            }
            if (in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                $depth--;
            }
            $current[] = $token;
        }

        return $arguments;
    }

    private function literalStringArgument(array $argument): ?string
    {
        foreach ($argument as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                return $this->decodeLiteral($token[1]);
            }
            return null;
        }

        return null;
    }

    private function matchRouteMethods(array $argument): array
    {
        $methods = [];
        foreach ($argument as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $methods[] = strtoupper($this->decodeLiteral($token[1]));
            }
        }

        return $methods === []
            ? ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']
            : $methods;
    }

    private function withoutManagedBlock(string $source, string $path): string
    {
        $beginCount = substr_count($source, self::BEGIN_MARKER);
        $endCount = substr_count($source, self::END_MARKER);
        if ($beginCount !== $endCount || $beginCount > 1) {
            throw new RuntimeException("Ambiguous LRG-managed route markers in {$path}.");
        }
        if ($beginCount === 0) {
            return $source;
        }

        $begin = strpos($source, self::BEGIN_MARKER);
        $end = strpos($source, self::END_MARKER, $begin);
        if ($begin === false || $end === false) {
            throw new RuntimeException("Incomplete LRG-managed route block in {$path}.");
        }
        $end += strlen(self::END_MARKER);

        return substr_replace($source, '', $begin, $end - $begin);
    }

    private function insertBeforeClosingTag(string $source, string $addition): string
    {
        $tokens = token_get_all($source, TOKEN_PARSE);
        $offset = 0;
        $closingTagOffset = null;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                $closingTagOffset = $offset;
                break;
            }
            $offset += strlen($text);
        }

        if ($closingTagOffset !== null) {
            return substr_replace($source, $addition, $closingTagOffset, 0);
        }

        return rtrim($source) . $addition;
    }

    private function readPhpFile(string $path): string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException("Could not read PHP file: {$path}");
        }

        $this->assertPhpSyntax($source, $path);

        return $source;
    }

    private function assertPhpSyntax(string $source, string $path): void
    {
        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new RuntimeException("Invalid PHP syntax in {$path}.", previous: $exception);
        }
    }

    private function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source, TOKEN_PARSE) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function nextSignificantToken(array $tokens, int $index): ?int
    {
        for (; $index < count($tokens); $index++) {
            if (is_array($tokens[$index])
                && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $index;
        }

        return null;
    }

    private function decodeLiteral(string $literal): string
    {
        $quote = $literal[0];
        $contents = substr($literal, 1, -1);
        return $quote === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $contents) : stripcslashes($contents);
    }

    private function writeAtomically(string $path, string $contents): void
    {
        $directory = dirname($path);
        $temporaryPath = tempnam($directory, '.lrg-routes-');
        if ($temporaryPath === false) {
            throw new RuntimeException("Could not create temporary route file in {$directory}.");
        }

        try {
            $permissions = is_file($path) ? fileperms($path) : false;
            $permissions = $permissions === false ? 0644 : ($permissions & 0777);
            if (!chmod($temporaryPath, $permissions)) {
                throw new RuntimeException("Could not set permissions for generated route file: {$path}");
            }
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException("Could not write generated route file: {$path}");
            }
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException("Could not install generated route file: {$path}");
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
