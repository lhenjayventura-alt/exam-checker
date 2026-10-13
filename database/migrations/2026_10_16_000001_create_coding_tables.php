<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: coding questions with test cases, and recorded execution results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coding_test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('input')->nullable();        // stdin
            $table->text('expected_output');
            $table->decimal('points', 8, 2);
            $table->boolean('is_hidden')->default(false); // never shown outside grading staff
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['question_id', 'position']);
        });

        // One row for the compile step and one per test case, for every grading run.
        Schema::create('code_execution_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coding_test_case_id')->nullable()->constrained()->nullOnDelete();
            $table->string('step', 10); // compile, test
            // passed, failed, compile_error, runtime_error, timeout, memory_limit, output_limit, skipped
            $table->string('status', 20);
            $table->decimal('points', 8, 2)->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('stdout')->nullable();  // truncated
            $table->text('stderr')->nullable();  // truncated; compiler output for the compile step
            $table->timestamps();
            $table->index(['grading_result_id', 'step']);
        });

        // Teacher-provided code (e.g. for a scanned sheet) is stored as a correction; it can be long.
        Schema::table('recognized_answers', function (Blueprint $table) {
            $table->text('corrected_value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('recognized_answers', function (Blueprint $table) {
            $table->string('corrected_value')->nullable()->change();
        });
        Schema::dropIfExists('code_execution_results');
        Schema::dropIfExists('coding_test_cases');
    }
};
