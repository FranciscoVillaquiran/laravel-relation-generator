<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

use RuntimeException;

class MigrationAnalysisException extends RuntimeException
{
    public function __construct(
        public string $migrationFile,
        public int $line,
        public string $structure,
        public string $unknownData,
    ) {
        parent::__construct(sprintf(
            'Cannot analyze migration %s:%d (%s): unable to determine %s.',
            $migrationFile,
            $line,
            $structure,
            $unknownData
        ));
    }
}
