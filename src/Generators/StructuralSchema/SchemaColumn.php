<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

class SchemaColumn
{
    public function __construct(
        public string $name,
        public string $type,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $nullable = false,
        public mixed $default = null,
        public bool $hasDefault = false,
        public bool $defaultIsExpression = false,
        public bool $autoIncrement = false,
        public bool $unsigned = false,
        public array $enumValues = [],
        public bool $change = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'length' => $this->length,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'has_default' => $this->hasDefault,
            'default_is_expression' => $this->defaultIsExpression,
            'auto_increment' => $this->autoIncrement,
            'unsigned' => $this->unsigned,
            'enum_values' => $this->enumValues,
        ];
    }
}
