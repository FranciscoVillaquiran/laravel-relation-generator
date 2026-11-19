<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('course_student', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->foreignId('course_id')->constrained('courses');
            $table->foreignId('student_id')->constrained('students');
        });

        Schema::create('enrollment_audit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses');
            $table->foreignId('student_id')->constrained('students');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_student');
        Schema::dropIfExists('students');
        Schema::dropIfExists('courses');
    }
};
