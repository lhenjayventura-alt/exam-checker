<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles, academic structure, faculty assignments, student records and class rosters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Scopes a program-chair role to one program; null for global roles.
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'user_id', 'program_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
        });

        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 9); // e.g. 2026-2027
            $table->enum('term', ['first', 'second', 'summer']);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_current')->default(false)->index();
            $table->timestamps();
            $table->unique(['academic_year', 'term']);
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('title');
            $table->timestamps();
            $table->unique(['program_id', 'code']);
        });

        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->string('section', 30);
            $table->timestamps();
            $table->unique(['course_id', 'academic_term_id', 'section']);
        });

        Schema::create('teaching_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['course_offering_id', 'user_id']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_number', 30)->unique();
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
        });

        // Class roster: which students belong to a course offering.
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['course_offering_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('students');
        Schema::dropIfExists('teaching_assignments');
        Schema::dropIfExists('course_offerings');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('academic_terms');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('programs');
        Schema::dropIfExists('roles');
    }
};
