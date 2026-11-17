<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('nickname', 80)->nullable()->default(null);
            $table->string('name', 160)->change();
            $table->integer('legacy_code')->change();
            $table->string('temporary_value');
            $table->dropColumn('legacy_note');
            $table->dropColumn('temporary_value');
            $table->renameColumn('name', 'display_name');
            $table->unique(['display_name', 'nickname'], 'users_display_nickname_unique');
        });
    }

    public function down(): void
    {
        //
    }
};
