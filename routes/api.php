<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
| Versioned REST API (prefix /api/v1, configured in bootstrap/app.php).
| Authentication: Sanctum personal access tokens (Authorization: Bearer <token>).
*/

Route::post('/auth/login', [V1\AuthController::class, 'login'])->middleware('throttle:api-login');

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [V1\AuthController::class, 'logout']);
    Route::get('/me', [V1\AuthController::class, 'me']);
    Route::get('/dashboard', V1\DashboardController::class);

    Route::get('/offerings', [V1\OfferingController::class, 'index']);
    Route::get('/offerings/{offering}/students', [V1\OfferingController::class, 'students']);

    Route::get('/exams', [V1\ExamController::class, 'index']);
    Route::post('/offerings/{offering}/exams', [V1\ExamController::class, 'store']);
    Route::get('/exams/{exam}', [V1\ExamController::class, 'show']);
    Route::post('/exams/{exam}/questions', [V1\ExamController::class, 'storeQuestion']);
    Route::post('/exams/{exam}/publish', [V1\ExamController::class, 'publish']);
    Route::post('/exams/{exam}/versions', [V1\ExamController::class, 'newVersion']);

    Route::post('/exams/{exam}/submissions', [V1\SubmissionController::class, 'store']);
    Route::get('/submissions/{submission}', [V1\SubmissionController::class, 'show']);
    Route::get('/submissions/{submission}/status', [V1\SubmissionController::class, 'status']);
    Route::post('/submissions/{submission}/process', [V1\SubmissionController::class, 'process']);

    Route::get('/review-tasks', [V1\GradingController::class, 'reviewTasks']);
    Route::get('/grading-results/{gradingResult}', [V1\GradingController::class, 'show']);
    Route::patch('/grading-results/{gradingResult}', [V1\GradingController::class, 'update']);
    Route::post('/submissions/{submission}/finalize', [V1\GradingController::class, 'finalize']);
    Route::post('/exams/{exam}/release', [V1\GradingController::class, 'release']);

    Route::get('/exams/{exam}/analytics', [V1\AnalyticsController::class, 'summary']);
    Route::get('/exams/{exam}/item-analysis', [V1\AnalyticsController::class, 'items']);
});
