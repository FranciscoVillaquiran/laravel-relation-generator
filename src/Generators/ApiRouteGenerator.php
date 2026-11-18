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
     */
    public function generate(string $projectRoot, array $modelClasses): void
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
        $models = $this->normalizeModels($modelClasses);

        foreach ($models as $model) {
            $controllerPath = $controllersDirectory . DIRECTORY_SEPARATOR . $model['controller'] . '.php';
            $this->assertControllerActions($controllerPath, $model['controller']);
        }

        $apiSource = $this->readPhpFile($apiRoutesPath);
        $managedSource = is_file($managedRoutesPath)
            ? $this->readPhpFile($managedRoutesPath)
            : "<?php\n\n";

        $apiUpdated = $this->addManagedInclude($apiSource);
        $unmanagedRoutes = $this->withoutManagedBlock($managedSource, $managedRoutesPath);
        $resourceCalls = [];
        foreach ($models as $model) {
            $resourceCalls[$model['resource']] = $model['controller'];
        }

        $this->assertNoRouteConflicts($apiSource, $apiRoutesPath, $resourceCalls);
        $this->assertNoRouteConflicts($unmanagedRoutes, $managedRoutesPath, $resourceCalls);
        $managedUpdated = $this->replaceManagedBlock(
            $managedSource,
            $this->buildManagedBlock($models),
            $managedRoutesPath
        );
        $this->assertPhpSyntax($apiUpdated, $apiRoutesPath);
        $this->assertPhpSyntax($managedUpdated, $managedRoutesPath);

        $this->writeAtomically($managedRoutesPath, $managedUpdated);
        if ($apiUpdated !== $apiSource) {
            $this->writeAtomically($apiRoutesPath, $apiUpdated);
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
        if (preg_match_all('/withRouting\s*\(/', $source) !== 1
            || preg_match('/withRouting\s*\(\s*using\s*:/i', $source) === 1
            || preg_match(
                '/withRouting\s*\(\s*(?![A-Za-z_][A-Za-z0-9_]*\s*:)/',
                $source
            ) === 1
            || !preg_match(
                '/withRouting\s*\(\s*(api|web|pages|commands|channels|health|apiPrefix|then)\s*:/i',
                $source
            )) {
            return false;
        }

        return preg_match(
            '/\bapi\s*:\s*(?:(?:__DIR__|base_path\s*\([^)]*\))\s*\.\s*)?[\'"][^\'"]*routes[\\\\\/]+api\.php[\'"]/i',
            $source
        ) === 1;
    }

    private function assertControllerActions(string $controllerPath, string $controllerName): void
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
            ['index', 'store', 'show', 'update', 'destroy'],
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

    private function buildManagedBlock(array $models): string
    {
        $lines = [self::BEGIN_MARKER];
        foreach ($models as $model) {
            $lines[] = "Route::apiResource('{$model['resource']}', \\App\\Http\\Controllers\\Api\\{$model['controller']}::class);";
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

            $updated = substr_replace($source, $block, $begin, $end - $begin);
            return $this->ensureRouteImport($updated, $path);
        }

        if (!$this->hasRouteFacadeImport($source)) {
            $source = $this->ensureRouteImport($source, $path);
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

    private function addManagedInclude(string $source): string
    {
        $markerCount = substr_count($source, self::INCLUDE_MARKER);
        $includeLine = "require_once __DIR__ . '/lrg_api.php';";

        if ($markerCount > 1) {
            throw new RuntimeException('Ambiguous LRG API include markers in routes/api.php.');
        }
        if ($markerCount === 1) {
            if (substr_count($source, $includeLine) !== 1
                || strpos($source, self::INCLUDE_MARKER) > strpos($source, $includeLine)) {
                throw new RuntimeException('The existing LRG API route include in routes/api.php was modified.');
            }
            return $source;
        }
        if (str_contains($source, 'lrg_api.php')) {
            throw new RuntimeException(
                'routes/api.php already references lrg_api.php without an LRG marker; refusing to alter an ambiguous manual include.'
            );
        }

        return $this->insertBeforeClosingTag(
            $source,
            "\n\n" . self::INCLUDE_MARKER . "\n" . $includeLine . "\n"
        );
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
