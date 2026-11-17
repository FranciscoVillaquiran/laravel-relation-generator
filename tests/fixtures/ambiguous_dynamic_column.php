<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class {
    public function up(): void
    {
        Schema::create('dynamic_rows', function (Blueprint $table): void {
            $table->string($columnName);
        });
    }
};
