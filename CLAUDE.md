# CLAUDE.md: Exam Checker

Guidance for Claude working in this repository. User-facing documentation (setup, features, API, statistics) is in `README.md`; read it first. This file records the decisions, conventions and open work that the README does not.

## What this is

AI-assisted examination checking for the University of Saint Louis Tuguegarao (Computer Engineering). Laravel 12 backend + Blade/Tailwind/Alpine dashboard + `/api/v1` Sanctum API for a planned Android app. Built from a long development brief: phased vertical slices, each committed separately (see `git log`).

| Phase | Commit | Scope |
|---|---|---|
| 1 | `a63eba1` | Roles, academic setup, exams + immutable versions, MC/TF grading, review, finalize/release, analytics, API |
| 2 | `018f9fe` | Identification, problem solving + rubrics, exam versioning after publish, CSV roster import, Excel export |
| 3 | `fbc410f` | Printable answer sheets + local OMR scanning (`scanner/`, Python/OpenCV) |
| 4 | `2147c51` | Coding questions (C, Python) in a Docker sandbox (`runner/`) |

## Decisions already made by the user (do not re-ask)

- **Standalone app**, not a module of the user's other project (the CLO/portfolio system in `Documents/Laravel/claude-code`). The two share no code or database.
- **Scanning = bubble sheets read locally** (OpenCV). No cloud OCR; scans never leave the server. Handwriting is cropped and **transcribed by teachers**, not machine-read.
- **Code execution = Docker**, installed by the user. Never run student code in PHP, in a web request, or on the host without the container sandbox.
- MySQL is the target database; SQLite works for local trials. Tests run on both.
- Commits are made only when the user asks (they have approved one per phase).

## Status / open work

1. **Docker sandbox not yet verified for real.** It was built without Docker available. After Docker Desktop is installed: `docker build -t exam-checker-runner:1 runner/`, then `php artisan test --group=sandbox` (hostile-program tests). Treat isolation as unverified until these pass.
2. **Scanner thresholds were tuned on synthetic marks.** Calibrate with real pencil/pen scans from the institution's printers before production use (`scanner/omr/read.py`: `EMPTY_MAX`, `FILLED_MIN`, `MARK_AT`).
3. **Android app (Phase 5)** not started. Kotlin, CameraX, Retrofit, encrypted token storage, upload queue with retry. The API it needs exists (`routes/api.php`, README API table). It needs Android Studio/JDK to build.
4. Smaller open items: post-release amendment workflow, notifications, job-monitoring screen, Verilog via simulator + testbench, student-number bubble grid on sheets, unit conversion in problem solving, a program-chair override permission.

## Architecture in one screen

- **Grading pipeline:** `SubmissionService` (digital/scan/file upload) → `GradeSubmission` job → `GradingOrchestrator` → per-type graders in `app/Services/Grading` (registered in `AppServiceProvider` via `GraderRegistry`). `AnswerAwareGrader` (coding) receives the whole `RecognizedAnswer`. Slow graders run **before** the DB transaction so no lock is held while containers run.
- **Teacher decisions:** `ReviewService` (`correct_answer`, `override` with mandatory reason, `confirm`, `score_rubric`). Every change writes `grading_events` + `audit_logs`. Results with status `reviewed`/`overridden` are never overwritten by re-grading. Finalized submissions are immutable (`ExamResultService`).
- **Two confidences kept apart:** recognition confidence (`RecognitionConfidenceService`, OCR/OMR reading) vs. grading outcome (`GradeOutcome.needsReview`). Low recognition confidence, handwritten boxes and pending rubrics all route to `review_tasks`.
- **Replaceable engines:** `SheetRecognizer` (scanning; `PythonOmrRecognizer` runs `scanner/omr_cli.py` with JSON over stdin/stdout) and `CodeRunner` (`DockerCodeRunner`). Tests use `tests/Fakes/*`.
- **Authorization:** policies in `app/Policies`. Only teachers assigned to the offering can grade; program chairs and admins can view but cannot change scores or see answer keys/hidden tests (including via grading explanations, which reveal keys). Admin routes use `can:admin`.
- **Statistics** are computed from finalized results **per exam version** (`ExamAnalyticsService`), with small-sample flags.

## Conventions and pitfalls (learned the hard way here)

- **Eloquent `Collection::only()` filters by model primary key, not array key.** Use `->toBase()->only()` or explicit lookups. This caused wrong exam totals once.
- **Browsers send textarea content with `\r\n`.** Code, test inputs and expected outputs are normalized to `\n`. `TrimStrings` is disabled for `answers.*`, `code`, `tests.*.input` and `tests.*.expected_output` in `bootstrap/app.php`, because whitespace is significant there.
- **Student numbers are stored upper-case** (`Student::canonicalNumber`); always look up with `Student::withNumber()`. SQLite is case-sensitive, MySQL is not.
- **Identification normalization** removes apostrophes ("Ohm's" = "Ohms") and keeps `+`/`#` (C++ ≠ C).
- **Web tests must assert the redirect** (`->assertSessionHasNoErrors()->assertRedirect()`); `assertSessionHasNoErrors()` alone passes on a 500.
- **Writing PHP from Python helper scripts:** backslashes in `App\Models\...` break Python string literals (`\N`, `\U`). Prefer the Edit tool for PHP edits.
- MySQL connection forces InnoDB (`config/database.php`); some servers default to MyISAM, which has no foreign keys or transactions.
- Keep business logic in services; controllers authorize, validate (Form Requests shared by web and API) and delegate.
- Never commit secrets, real student data, `.env`, `scanner/.venv`, or files under `storage/app/private`. Test data must be synthetic (`DemoSeeder`, factories with `TEST-` numbers).

## Verifying changes

```bash
php artisan test                                   # SQLite in-memory
DB_CONNECTION=mysql DB_DATABASE=exam_checker_test ./vendor/bin/phpunit   # against an empty MySQL test DB
scanner/.venv/Scripts/python -m unittest discover -s scanner/tests       # scanner round-trip
php artisan test --group=sandbox                   # real Docker sandbox (needs Docker)
./vendor/bin/pint                                  # code style
```

Grading runs on the queue: run `php artisan queue:work` (or `composer dev`) when checking the app in a browser. `php artisan db:seed --class=DemoSeeder` creates synthetic demo accounts (listed in that file) for local use only.
