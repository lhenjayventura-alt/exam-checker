<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: printable answer-sheet layouts, scan uploads, recognized sheets and stored files.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Geometry of the printed sheet for one locked exam version. Never changes once printed.
        Schema::create('answer_sheet_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_version_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('layout');
            $table->unsignedSmallInteger('page_count');
            $table->timestamps();
        });

        Schema::create('scan_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            $table->string('path');           // private disk; the original is never modified
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('status', 20)->default('pending')->index(); // pending, processing, completed, failed
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'sha256']); // the same file cannot be processed twice
        });

        // One physical answer sheet (one or more pages) found in an upload.
        Schema::create('scan_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_upload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_sheet_layout_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('first_page');
            $table->unsignedSmallInteger('page_count');
            // matched, needs_student, duplicate, unreadable, wrong_exam, assigned
            $table->string('status', 20)->index();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('qr_student_id')->nullable(); // as printed; verified before use
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->json('recognition')->nullable();  // answers keyed by question number
            $table->json('page_images')->nullable();  // aligned page image paths
            $table->timestamps();
        });

        Schema::create('submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_sheet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // aligned_page, answer_crop
            $table->unsignedSmallInteger('page')->nullable();
            $table->string('disk', 20);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->index(['submission_id', 'question_id']);
        });

        // Recognition details (bubble darkness, written-answer flags, crop reference).
        Schema::table('recognized_answers', function (Blueprint $table) {
            $table->json('recognition_meta')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('recognized_answers', fn (Blueprint $table) => $table->dropColumn('recognition_meta'));
        Schema::dropIfExists('submission_files');
        Schema::dropIfExists('scan_sheets');
        Schema::dropIfExists('scan_uploads');
        Schema::dropIfExists('answer_sheet_layouts');
    }
};
