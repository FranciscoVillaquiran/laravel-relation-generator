<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class {
    public function up(): void
    {
        if (getenv('CREATE_OPTIONAL_TABLE')) {
            Schema::create('optional_rows', function (Blueprint $table): void {
                $table->id();
            });
        }
    }
};
