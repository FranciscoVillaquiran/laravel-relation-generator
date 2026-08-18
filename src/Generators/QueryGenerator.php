<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

class QueryGenerator
{
    public function generate(
        string $controllerPath,
        string $modelA,
        string $modelB,
        string $relationA,
        string $relationB
    ): void {
        if (!file_exists($controllerPath)) {
            $this->createController($controllerPath);
        }

        $content = file_get_contents($controllerPath);

        $content = $this->addImport(
            $content,
            $modelA
        );

        $content = $this->addImport(
            $content,
            $modelB
        );

        $methodA = $this->buildMethod(
            $modelA,
            $modelB,
            $relationA
        );

        $methodB = $this->buildMethod(
            $modelB,
            $modelA,
            $relationB
        );

        $content = $this->addMethod(
            $content,
            $methodA
        );

        $content = $this->addMethod(
            $content,
            $methodB
        );

        file_put_contents(
            $controllerPath,
            $content
        );
    }

    private function buildMethod(
        string $model,
        string $relatedModel,
        string $relation
    ): string {
        $methodName = "consultas{$model}{$relatedModel}";
        $variable = lcfirst($model);

        return <<<PHP

    public function {$methodName}()
    {
        \${$variable} = {$model}::with(['{$relation}'])->get();

        return \${$variable};
    }

PHP;
    }

    private function addImport(
        string $content,
        string $model
    ): string {
        $import = "use App\\Models\\{$model};";

        if (str_contains($content, $import)) {
            return $content;
        }

        $namespacePosition = strpos(
            $content,
            'namespace'
        );

        if ($namespacePosition === false) {
            throw new \RuntimeException(
                "Could not find namespace in controller."
            );
        }

        $lineEnd = strpos(
            $content,
            "\n",
            $namespacePosition
        );

        if ($lineEnd === false) {
            throw new \RuntimeException(
                "Could not find the end of namespace declaration."
            );
        }

        $insertPosition = $lineEnd + 1;

        return substr_replace(
            $content,
            "{$import}\n",
            $insertPosition,
            0
        );
    }

    private function addMethod(
        string $content,
        string $method
    ): string {
        if (str_contains($content, trim($method))) {
            return $content;
        }

        $position = strrpos(
            $content,
            '}'
        );

        if ($position === false) {
            throw new \RuntimeException(
                "Could not find the end of controller."
            );
        }

        return substr_replace(
            $content,
            $method,
            $position,
            0
        );
    }

    private function createController(
        string $controllerPath
    ): void {
        $directory = dirname(
            $controllerPath
        );

        if (!is_dir($directory)) {
            mkdir(
                $directory,
                0755,
                true
            );
        }

        $content = <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ConsultasController extends Controller
{
}
PHP;

        file_put_contents(
            $controllerPath,
            $content
        );
    }
}