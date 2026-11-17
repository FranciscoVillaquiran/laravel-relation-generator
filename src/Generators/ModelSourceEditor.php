<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use ParseError;
use RuntimeException;

class ModelSourceEditor
{
    /**
     * @param list<string> $methods
     */
    public function addMethods(string $modelPath, array $methods, string $expectedClass): bool
    {
        $updatedSource = $this->prepareMethods($modelPath, $methods, $expectedClass);
        if ($updatedSource === null) {
            return false;
        }

        $this->writeAtomically($modelPath, $updatedSource);
        return true;
    }

    /**
     * Return null when all methods already exist exactly as generated.
     *
     * @param list<string> $methods
     */
    public function prepareMethods(string $modelPath, array $methods, string $expectedClass): ?string
    {
        $source = file_get_contents($modelPath);
        if ($source === false) {
            throw new RuntimeException("Could not read model: {$modelPath}");
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $exception) {
            throw new RuntimeException("Could not parse model {$modelPath}: {$exception->getMessage()}", 0, $exception);
        }

        $class = $this->classBody($tokens, $expectedClass, $modelPath);
        $existing = $this->classMethods($tokens, $class['open'], $class['close']);
        $insertions = [];

        foreach ($methods as $method) {
            $expected = $this->methodDeclaration($method, $modelPath);
            $name = $expected['name'];
            if (isset($existing[$name])) {
                if ($existing[$name]['normalized'] === $expected['normalized']) {
                    continue;
                }

                throw new RuntimeException(
                    "Model {$modelPath} already defines a conflicting {$name}() method."
                );
            }

            $insertions[] = $method;
        }

        if ($insertions === []) {
            return null;
        }

        $offset = $this->offsetAtToken($tokens, $class['close'], strlen($source));
        $updatedSource = substr_replace($source, "\n" . implode("\n", $insertions) . "\n", $offset, 0);
        try {
            token_get_all($updatedSource, TOKEN_PARSE);
        } catch (ParseError $exception) {
            throw new RuntimeException("Generated relationship methods are invalid PHP for {$modelPath}.", 0, $exception);
        }

        return $updatedSource;
    }

    public function writePrepared(string $modelPath, string $source): void
    {
        $this->writeAtomically($modelPath, $source);
    }

    /**
     * Add structurally admissible columns while preserving literal manual entries.
     *
     * @param list<string> $columns
     */
    public function updateFillable(string $modelPath, array $columns, string $expectedClass): bool
    {
        $source = file_get_contents($modelPath);
        if ($source === false) {
            throw new RuntimeException("Could not read model: {$modelPath}");
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $exception) {
            throw new RuntimeException("Could not parse model {$modelPath}: {$exception->getMessage()}", 0, $exception);
        }

        $class = $this->classBody($tokens, $expectedClass, $modelPath);
        $depth = 1;
        $fillable = [];
        $guardedFound = false;
        for ($index = $class['open'] + 1; $index < $class['close']; $index++) {
            $token = $tokens[$index];
            if ($depth === 1 && is_array($token) && $token[0] === T_VARIABLE) {
                if ($token[1] === '$guarded') {
                    $guardedFound = true;
                }
                if ($token[1] === '$fillable') {
                    $end = $this->findTokenValue($tokens, $index + 1, ';', $class['close']);
                    if ($end === null) {
                        throw new RuntimeException("Could not read the \$fillable property in {$modelPath}.");
                    }
                    $fillable[] = $this->fillableProperty($tokens, $index, $end, $source, $modelPath);
                }
            }

            $value = $this->tokenValue($token);
            if ($value === '{') {
                $depth++;
            } elseif ($value === '}') {
                $depth--;
            }
        }

        if ($guardedFound) {
            throw new RuntimeException(
                "Model {$modelPath} declares \$guarded; LRG cannot safely reconcile it with generated \$fillable."
            );
        }
        if (count($fillable) > 1) {
            throw new RuntimeException("Model {$modelPath} has multiple \$fillable declarations.");
        }

        if ($fillable === []) {
            $offset = $this->offsetAtToken($tokens, $class['close'], strlen($source));
            $indent = $this->indentationAt($source, $offset);
            $indent = $indent === '' ? '    ' : $indent;
            $property = $this->formatFillable($columns, $indent);
            $updatedSource = substr_replace($source, "\n" . $property . "\n", $offset, 0);
        } else {
            [, , $manualColumns, $duplicates] = $fillable[0];
            if ($duplicates !== []) {
                throw new RuntimeException(
                    "Model {$modelPath} contains duplicate entries in its manual \$fillable property."
                );
            }
            $missing = array_values(array_diff($columns, $manualColumns));
            if ($missing !== []) {
                throw new RuntimeException(
                    "Model {$modelPath} has a manual \$fillable allowlist missing structural columns: "
                    . implode(', ', $missing)
                    . '. Review and update it explicitly.'
                );
            }

            return false;
        }

        try {
            token_get_all($updatedSource, TOKEN_PARSE);
        } catch (ParseError $exception) {
            throw new RuntimeException("Generated \$fillable is invalid PHP for {$modelPath}.", 0, $exception);
        }

        $this->writeAtomically($modelPath, $updatedSource);
        return true;
    }

    /**
     * @param list<array|string> $tokens
     * @return array{0: int, 1: int, 2: list<string>, 3: list<string>}
     */
    private function fillableProperty(array $tokens, int $variable, int $semicolon, string $source, string $modelPath): array
    {
        $assignment = null;
        for ($index = $variable + 1; $index < $semicolon; $index++) {
            if ($this->tokenValue($tokens[$index]) === '=') {
                $assignment = $index;
                break;
            }
        }
        if ($assignment === null) {
            throw new RuntimeException("Model {$modelPath} has a \$fillable property without a static array value.");
        }

        $declarationStart = $variable;
        $boundary = -1;
        for ($index = $variable - 1; $index >= 0; $index--) {
            $value = $this->tokenValue($tokens[$index]);
            if ($value === ';' || $value === '{' || $value === '}') {
                $boundary = $index;
                break;
            }
            $declarationStart = $index;
        }
        for ($index = $boundary + 1; $index < $variable; $index++) {
            if (!is_array($tokens[$index]) || !in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $declarationStart = $index;
                break;
            }
        }

        $modifiers = [];
        for ($index = $declarationStart; $index < $variable; $index++) {
            if (is_array($tokens[$index]) && !in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $modifiers[] = $tokens[$index][0];
            }
        }
        if ($modifiers !== [T_PROTECTED]) {
            throw new RuntimeException(
                "Model {$modelPath} has a non-standard \$fillable declaration that LRG cannot safely modify."
            );
        }

        $valueTokens = [];
        for ($index = $assignment + 1; $index < $semicolon; $index++) {
            if (is_array($tokens[$index]) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $valueTokens[] = $tokens[$index];
        }
        if (
            count($valueTokens) < 2
            || $this->tokenValue($valueTokens[0]) !== '['
            || $this->tokenValue($valueTokens[count($valueTokens) - 1]) !== ']'
        ) {
            throw new RuntimeException(
                "Model {$modelPath} has a computed \$fillable value; LRG only merges a literal string array."
            );
        }

        $manual = [];
        for ($index = 1, $count = count($valueTokens) - 1; $index < $count; $index++) {
            $token = $valueTokens[$index];
            if ($this->tokenValue($token) === ',') {
                continue;
            }
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                throw new RuntimeException(
                    "Model {$modelPath} has a non-literal entry in \$fillable; LRG cannot safely merge it."
                );
            }
            $manual[] = $this->decodePhpString($token[1]);
        }

        $propertyOffset = $this->offsetAtToken($tokens, $declarationStart, strlen($source));
        $indent = $this->indentationAt($source, $propertyOffset);
        $startOffset = $propertyOffset - strlen($indent);
        $endOffset = $this->offsetAtToken($tokens, $semicolon + 1, strlen($source));
        $uniqueManual = array_values(array_unique($manual));
        $duplicates = array_values(array_diff_assoc($manual, $uniqueManual));

        return [$startOffset, $endOffset, $uniqueManual, $duplicates];
    }

    private function decodePhpString(string $literal): string
    {
        if ($literal[0] === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], substr($literal, 1, -1));
        }

        $decoded = json_decode($literal, true);
        if (!is_string($decoded)) {
            throw new RuntimeException('Encountered an unsupported quoted string in a model property.');
        }

        return $decoded;
    }

    /**
     * @param list<string> $columns
     */
    private function formatFillable(array $columns, string $indent): string
    {
        $lines = ['protected $fillable = ['];
        foreach ($columns as $column) {
            $lines[] = '    ' . var_export($column, true) . ',';
        }
        $lines[] = '];';

        return $indent . implode("\n" . $indent, $lines);
    }

    private function indentationAt(string $source, int $offset): string
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $prefix = substr($source, $lineStart, $offset - $lineStart);

        return preg_match('/^[ \t]*/', $prefix, $matches) === 1 ? $matches[0] : '';
    }

    /**
     * @param list<array|string> $tokens
     * @return array{open: int, close: int}
     */
    private function classBody(array $tokens, string $expectedClass, string $modelPath): array
    {
        $matches = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            $nameIndex = $this->nextSignificantToken($tokens, $index + 1);
            if (
                $nameIndex === null
                || !is_array($tokens[$nameIndex])
                || $tokens[$nameIndex][0] !== T_STRING
            ) {
                continue;
            }

            if (strcasecmp($tokens[$nameIndex][1], $expectedClass) !== 0) {
                continue;
            }

            $open = $this->findTokenValue($tokens, $nameIndex + 1, '{');
            $close = $open === null ? null : $this->matchingBrace($tokens, $open);
            if ($open !== null && $close !== null) {
                $matches[] = ['open' => $open, 'close' => $close];
            }
        }

        if (count($matches) !== 1) {
            throw new RuntimeException(
                "Could not uniquely locate class {$expectedClass} in model {$modelPath}."
            );
        }

        return $matches[0];
    }

    /**
     * @param list<array|string> $tokens
     * @return array<string, array{normalized: string}>
     */
    private function classMethods(array $tokens, int $open, int $close): array
    {
        $methods = [];
        $depth = 1;

        for ($index = $open + 1; $index < $close; $index++) {
            $value = $this->tokenValue($tokens[$index]);
            if ($depth === 1 && is_array($tokens[$index]) && $tokens[$index][0] === T_FUNCTION) {
                $method = $this->methodDeclarationAt($tokens, $index, $close);
                $methods[$method['name']] = ['normalized' => $method['normalized']];
                $index = $method['end'];
                continue;
            }

            if ($value === '{') {
                $depth++;
            } elseif ($value === '}') {
                $depth--;
            }
        }

        return $methods;
    }

    private function methodDeclaration(string $source, string $modelPath): array
    {
        try {
            $tokens = token_get_all("<?php class __LrgModel { {$source} }", TOKEN_PARSE);
        } catch (ParseError $exception) {
            throw new RuntimeException("Could not parse generated relationship method for {$modelPath}.", 0, $exception);
        }

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_FUNCTION) {
                return $this->methodDeclarationAt($tokens, $index, count($tokens) - 1);
            }
        }

        throw new RuntimeException("Could not parse generated relationship method for {$modelPath}.");
    }

    /**
     * @param list<array|string> $tokens
     * @return array{name: string, normalized: string, end: int}
     */
    private function methodDeclarationAt(array $tokens, int $functionIndex, int $limit): array
    {
        $nameIndex = $this->nextSignificantToken($tokens, $functionIndex + 1);
        if ($nameIndex !== null && $this->tokenValue($tokens[$nameIndex]) === '&') {
            $nameIndex = $this->nextSignificantToken($tokens, $nameIndex + 1);
        }

        if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
            throw new RuntimeException('Encountered an unsupported anonymous or dynamic method declaration.');
        }

        $open = $this->findTokenValue($tokens, $nameIndex + 1, '{', $limit);
        if ($open === null) {
            throw new RuntimeException("Could not find the body for model method {$tokens[$nameIndex][1]}().");
        }
        $end = $this->matchingBrace($tokens, $open, $limit);
        if ($end === null) {
            throw new RuntimeException("Could not find the end of model method {$tokens[$nameIndex][1]}().");
        }

        $start = $functionIndex;
        for ($index = $functionIndex - 1; $index >= 0; $index--) {
            $value = $this->tokenValue($tokens[$index]);
            if ($value === ';' || $value === '{' || $value === '}') {
                $start = $index + 1;
                break;
            }
        }

        return [
            'name' => $tokens[$nameIndex][1],
            'normalized' => $this->normalizeTokens(array_slice($tokens, $start, $end - $start + 1)),
            'end' => $end,
        ];
    }

    /**
     * @param list<array|string> $tokens
     */
    private function matchingBrace(array $tokens, int $open, ?int $limit = null): ?int
    {
        $limit ??= count($tokens);
        $depth = 0;
        for ($index = $open; $index < $limit; $index++) {
            $value = $this->tokenValue($tokens[$index]);
            if ($value === '{') {
                $depth++;
            } elseif ($value === '}' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array|string> $tokens
     */
    private function findTokenValue(array $tokens, int $start, string $value, ?int $limit = null): ?int
    {
        $limit ??= count($tokens);
        for ($index = $start; $index < $limit; $index++) {
            if ($this->tokenValue($tokens[$index]) === $value) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array|string> $tokens
     */
    private function nextSignificantToken(array $tokens, int $start): ?int
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            if (!is_array($tokens[$index]) || !in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array|string> $tokens
     */
    private function normalizeTokens(array $tokens): string
    {
        $normalized = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $normalized .= $this->tokenValue($token);
        }

        return $normalized;
    }

    private function tokenValue(array|string $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    /**
     * @param list<array|string> $tokens
     */
    private function offsetAtToken(array $tokens, int $index, int $sourceLength): int
    {
        $offset = 0;
        for ($tokenIndex = 0; $tokenIndex < $index; $tokenIndex++) {
            $offset += strlen($this->tokenValue($tokens[$tokenIndex]));
        }

        return min($offset, $sourceLength);
    }

    private function writeAtomically(string $path, string $contents): void
    {
        $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $bytes = file_put_contents($temporaryPath, $contents, LOCK_EX);
        if ($bytes !== strlen($contents)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Could not write model file: {$path}");
        }

        if (is_file($path)) {
            $permissions = fileperms($path);
            if ($permissions !== false) {
                @chmod($temporaryPath, $permissions & 0777);
            }
        }

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Could not atomically update model file: {$path}");
        }
    }
}
