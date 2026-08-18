<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

class RouteGenerator
{
    public function generate(
        string $routesPath,
        string $modelA,
        string $modelB
    ): void {
        if (!file_exists($routesPath)) {
            throw new \RuntimeException(
                "Routes file not found: {$routesPath}"
            );
        }

        $content = file_get_contents($routesPath);

        $routeA = $this->buildRoute(
            $modelA,
            $modelB
        );

        $routeB = $this->buildRoute(
            $modelB,
            $modelA
        );

        $content = $this->addImport(
            $content
        );

        $content = $this->addRoute(
            $content,
            $routeA
        );

        $content = $this->addRoute(
            $content,
            $routeB
        );

        file_put_contents(
            $routesPath,
            $content
        );
    }

    private function buildRoute(
        string $modelA,
        string $modelB
    ): string {
        $methodName = "consultas{$modelA}{$modelB}";

        $routeModelA = strtolower(
            preg_replace(
                '/(?<!^)[A-Z]/',
                '-$0',
                $modelA
            )
        );

        $routeModelB = strtolower(
            preg_replace(
                '/(?<!^)[A-Z]/',
                '-$0',
                $modelB
            )
        );

        return <<<PHP

Route::get(
    '/consultas/{$routeModelA}-{$routeModelB}',
    [ConsultasController::class, '{$methodName}']
);

PHP;
    }

    private function addImport(
        string $content
    ): string {
        $import = 'use App\Http\Controllers\ConsultasController;';

        if (str_contains($content, $import)) {
            return $content;
        }

        $position = strpos(
            $content,
            "\n",
            strpos($content, '<?php')
        );

        if ($position === false) {
            throw new \RuntimeException(
                "Could not find PHP opening tag in routes file."
            );
        }

        return substr_replace(
            $content,
            "\n{$import}",
            $position + 1,
            0
        );
    }

    private function addRoute(
        string $content,
        string $route
    ): string {
        if (str_contains($content, trim($route))) {
            return $content;
        }

        return rtrim($content) . $route;
    }
}