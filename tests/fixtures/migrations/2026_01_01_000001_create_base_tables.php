<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->decimal('balance', 10, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('last_seen')->useCurrent();
            $table->string('legacy_note');
            $table->string('legacy_code', 50);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()
                ->constrained('users', 'id', 'profiles_user_foreign')
                ->cascadeOnDelete()
                ->restrictOnUpdate();
            $table->string('biography')->nullable();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('title');
            $table->boolean('published')->default(false);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('label')->unique();
        });

        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->string('country_code', 2);
            $table->string('region_code', 8);
            $table->unique(['country_code', 'region_code']);
        });

        Schema::create('localities', function (Blueprint $table): void {
            $table->id();
            $table->foreign(['country_code', 'region_code'], 'localities_region_fk')
                ->references(['country_code', 'region_code'])
                ->on('regions')
                ->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->string('region_code', 8);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('users');
    }
};
