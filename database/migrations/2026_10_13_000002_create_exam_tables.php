<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Examinations, immutable versions, questions, options and answer keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->enum('exam_type', ['quiz', 'long_exam', 'prelim', 'midterm', 'prefinal', 'final', 'other']);
            $table->date('exam_date')->nullable();
            // Passing threshold as a percentage of total points.
            $table->decimal('passing_percentage', 5, 2)->default(60);
            $table->enum('status', ['draft', 'published', 'closed', 'archived'])->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('exam_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            // Once locked, questions, keys and points are immutable.
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['exam_id', 'version_number']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->enum('type', ['multiple_choice', 'true_false', 'identification', 'problem_solving', 'coding']);
            $table->text('prompt')->nullable();
            $table->decimal('points', 8, 2);
            // Validated type-specific settings, e.g. {"multiple_select": true, "partial_credit": "proportional"}.
            $table->json('config')->nullable();
            $table->timestamps();
            $table->unique(['exam_version_id', 'number']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('label', 5); // A, B, C...
            $table->text('text')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['question_id', 'label']);
        });

        Schema::create('answer_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->unique()->constrained()->cascadeOnDelete();
            // Normalized correct values: option labels for MC (["B"]), ["true"]/["false"] for TF.
            $table->json('correct_values');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_keys');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('exam_versions');
        Schema::dropIfExists('exams');
    }
};
