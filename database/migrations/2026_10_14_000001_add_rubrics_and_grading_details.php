<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: rubric criteria for problem-solving questions and criterion-level scores.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One rubric per question, so criteria hang directly off the question.
        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('label', 150);
            $table->text('description')->nullable();
            $table->decimal('max_points', 8, 2);
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['question_id', 'position']);
        });

        // Per-criterion scores. rubric_criterion_id is null for the automatically checked final answer.
        Schema::create('grading_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rubric_criterion_id')->nullable()->constrained('rubric_criteria')->cascadeOnDelete();
            $table->string('label', 150);
            $table->decimal('max_points', 8, 2);
            $table->decimal('auto_score', 8, 2)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['grading_result_id', 'rubric_criterion_id']);
        });

        // New event types (e.g. rubric_scored) without enum migrations each time.
        Schema::table('grading_events', function (Blueprint $table) {
            $table->string('event', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('grading_events', function (Blueprint $table) {
            $table->enum('event', ['auto_graded', 'answer_corrected', 'score_overridden', 'review_confirmed'])->change();
        });
        Schema::dropIfExists('grading_details');
        Schema::dropIfExists('rubric_criteria');
    }
};
