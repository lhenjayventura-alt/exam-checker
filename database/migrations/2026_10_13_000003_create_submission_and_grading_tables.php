<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Submissions, recognized answers, per-question grading, review tasks,
 * score-change history and final examination results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('exam_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->enum('source', ['digital', 'scan', 'upload']);
            $table->enum('status', ['pending', 'processing', 'graded', 'needs_review', 'finalized', 'failed'])
                ->default('pending')->index();
            $table->text('failure_reason')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            // One submission per student per exam prevents duplicate grades.
            $table->unique(['exam_id', 'student_id']);
        });

        Schema::create('recognized_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->enum('source', ['digital', 'ocr', 'manual']);
            // Original input exactly as received (or as recognized by OCR). Never overwritten.
            $table->text('raw_value')->nullable();
            $table->string('normalized_value')->nullable();
            // Recognition confidence 0..1; null for typed digital answers.
            $table->decimal('confidence', 5, 4)->nullable();
            // Teacher correction kept separately from the original.
            $table->string('corrected_value')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();
            $table->unique(['submission_id', 'question_id']);
        });

        Schema::create('grading_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->string('grader', 60);
            $table->decimal('max_points', 8, 2);
            // Score proposed by the automatic grader.
            $table->decimal('proposed_score', 8, 2)->nullable();
            // Authoritative score; null while review is pending.
            $table->decimal('awarded_score', 8, 2)->nullable();
            $table->boolean('is_correct')->nullable();
            $table->text('explanation')->nullable();
            $table->enum('status', ['auto', 'needs_review', 'reviewed', 'overridden'])->index();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->unique(['submission_id', 'question_id']);
        });

        Schema::create('review_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_result_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->enum('status', ['open', 'resolved'])->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // Append-only history of every grading change.
        Schema::create('grading_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('event', ['auto_graded', 'answer_corrected', 'score_overridden', 'review_confirmed']);
            $table->decimal('previous_score', 8, 2)->nullable();
            $table->decimal('new_score', 8, 2)->nullable();
            $table->string('previous_answer')->nullable();
            $table->string('new_answer')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->decimal('total_score', 8, 2);
            $table->decimal('max_score', 8, 2);
            $table->decimal('percentage', 5, 2);
            $table->boolean('passed');
            $table->foreignId('finalized_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'student_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80)->index();
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('grading_events');
        Schema::dropIfExists('review_tasks');
        Schema::dropIfExists('grading_results');
        Schema::dropIfExists('recognized_answers');
        Schema::dropIfExists('submissions');
    }
};
