<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\ExamResultController;
use App\Http\Controllers\GradingResultController;
use App\Http\Controllers\OfferingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Course offerings and class rosters
    Route::get('/offerings', [OfferingController::class, 'index'])->name('offerings.index');
    Route::get('/offerings/{offering}', [OfferingController::class, 'show'])->name('offerings.show');
    Route::post('/offerings/{offering}/roster', [RosterController::class, 'store'])->name('offerings.roster.store');
    Route::delete('/offerings/{offering}/roster/{student}', [RosterController::class, 'destroy'])->name('offerings.roster.destroy');

    // Examinations and questions
    Route::get('/offerings/{offering}/exams/create', [ExamController::class, 'create'])->name('exams.create');
    Route::post('/offerings/{offering}/exams', [ExamController::class, 'store'])->name('exams.store');
    Route::get('/exams/{exam}', [ExamController::class, 'show'])->name('exams.show');
    Route::patch('/exams/{exam}', [ExamController::class, 'update'])->name('exams.update');
    Route::post('/exams/{exam}/publish', [ExamController::class, 'publish'])->name('exams.publish');
    Route::post('/exams/{exam}/close', [ExamController::class, 'close'])->name('exams.close');
    Route::post('/exams/{exam}/questions', [QuestionController::class, 'store'])->name('exams.questions.store');
    Route::delete('/exams/{exam}/questions/{question}', [QuestionController::class, 'destroy'])->name('exams.questions.destroy');

    // Submissions and grading review
    Route::get('/exams/{exam}/submissions/create', [SubmissionController::class, 'create'])->name('exams.submissions.create');
    Route::post('/exams/{exam}/submissions', [SubmissionController::class, 'store'])->name('exams.submissions.store');
    Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');
    Route::post('/submissions/{submission}/reprocess', [SubmissionController::class, 'reprocess'])->name('submissions.reprocess');
    Route::patch('/grading-results/{gradingResult}', [GradingResultController::class, 'update'])->name('grading-results.update');
    Route::get('/review-queue', ReviewQueueController::class)->name('review-queue');

    // Finalization, release and analytics
    Route::post('/submissions/{submission}/finalize', [ExamResultController::class, 'finalize'])->name('submissions.finalize');
    Route::post('/exams/{exam}/finalize-all', [ExamResultController::class, 'finalizeAll'])->name('exams.finalize-all');
    Route::post('/exams/{exam}/release', [ExamResultController::class, 'release'])->name('exams.release');
    Route::get('/exams/{exam}/results', [ExamResultController::class, 'index'])->name('exams.results');
    Route::get('/exams/{exam}/results/export', [ExamResultController::class, 'export'])->name('exams.results.export');

    // Administration
    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', Admin\UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::get('academics', [Admin\AcademicController::class, 'index'])->name('academics.index');
        Route::post('programs', [Admin\AcademicController::class, 'storeProgram'])->name('programs.store');
        Route::post('terms', [Admin\AcademicController::class, 'storeTerm'])->name('terms.store');
        Route::post('courses', [Admin\AcademicController::class, 'storeCourse'])->name('courses.store');
        Route::post('offerings', [Admin\AcademicController::class, 'storeOffering'])->name('offerings.store');
        Route::put('offerings/{offering}/teachers', [Admin\AcademicController::class, 'assignTeachers'])->name('offerings.teachers');
        Route::get('audit-logs', Admin\AuditLogController::class)->name('audit-logs');
    });
});

require __DIR__.'/auth.php';
