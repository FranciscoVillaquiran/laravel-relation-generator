<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema as DbSchema};

return new class {
    public function up(): void
    {
        DbSchema::create('aliased_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 40);
        });
    }

    public function down(): void
    {
        DbSchema::dropIfExists('aliased_rows');
    }
};
