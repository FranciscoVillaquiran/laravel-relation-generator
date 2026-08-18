<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Relations;

class RelationDefinition
{
    public function __construct(
        public string $modelA,
        public string $modelB,
        public string $cardinality,
        public ?string $foreignTable = null,
        public ?string $pivotTable = null,
    ) {
    }
}