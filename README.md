# Exam Checker

AI-assisted examination checking, grading and analytics. Laravel 12 backend and web dashboard, with a versioned REST API for the (planned) Android scanning app.

> **Status: Phase 1 vertical slice.** Multiple-choice and true/false exams entered digitally are graded automatically, reviewed by teachers, finalized, released and analyzed. Scanning/OCR, other question types, code execution and the Android app come in later phases (see [Roadmap](#roadmap)).

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.2 |
| Database | MySQL 8 (InnoDB enforced); SQLite works for quick local trials |
| Web UI | Blade, Tailwind CSS, Alpine.js (Laravel Breeze) |
| API auth | Laravel Sanctum personal access tokens |
| Background work | Laravel queue (`database` locally, Redis recommended in production) |

## Setup

```bash
composer install
npm install && npm run build
cp .env.example .env        # then set DB_* for your MySQL database
php artisan key:generate
php artisan migrate
php artisan db:seed         # roles only
php artisan exam:create-admin admin@school.edu "Admin Name"
```

Run the app with a queue worker (grading runs on the queue):

```bash
composer dev                # serve + queue:listen + logs + vite
# or separately:
php artisan serve
php artisan queue:work
```

### Demo data (local only)

`php artisan db:seed --class=DemoSeeder` creates a synthetic program, class and 12 fictitious students, plus three demo accounts described in `database/seeders/DemoSeeder.php`. It refuses to run in production.

## Roles

| Role | Can |
|---|---|
| Administrator | Manage users and roles, programs, terms, courses, sections and teaching assignments; view audit log and job failures. **Cannot change scores.** |
| Program Chair (scoped to program[s]) | View classes, exams, grading completion and results in their program. **Cannot see answer keys or change scores.** |
| Teacher | For assigned sections only: manage roster, build/publish exams, enter submissions, review, override, finalize and release results. |

All rules are enforced server-side (`app/Policies`, `can:admin` middleware). Public self-registration is disabled; accounts are created by an administrator. Deactivated accounts cannot sign in and their API tokens are revoked.

## Grading workflow

```
Draft exam ──publish (locks version)──▶ Published ──▶ Submission (digital answers)
   ──queue──▶ GradingOrchestrator ──▶ per-question GradingResult
        ├─ deterministic & certain ─▶ awarded automatically
        └─ ambiguous / low recognition confidence ─▶ ReviewTask (teacher)
   ──all reviewed──▶ Finalize (transaction, recalculated total) ──▶ Release
```

* **Original answers are never overwritten.** `recognized_answers` keeps the raw input, normalized value, recognition confidence, and a separate teacher correction.
* **Every score change is recorded** in `grading_events` (who, when, previous → new score/answer, reason) and in `audit_logs`. Overrides require a reason.
* **Teacher decisions are never overwritten** by automatic re-grading. Re-grading is idempotent (one result per submission/question).
* **Finalized/released results are locked.** A post-release amendment workflow is not yet implemented.
* **Duplicate prevention**: one submission per student per exam (DB unique constraint); the API returns `409` with the existing submission on retries.

### Automatic grading rules

| Type | Normalization | Scoring | Sent to review when |
|---|---|---|---|
| Multiple choice | `b`, `A, C`, `ac`, `a;c` → `B`, `A,C` | Single answer: all-or-nothing. Multiple-select: all-or-nothing, or *proportional* = max(0, correct picks − wrong picks) ÷ number of keyed options × points | Two marks on a single-answer item; a letter that is not an option |
| True / false | `T`, `True`, `Tama` → `true`; `F`, `False`, `Mali` → `false` | All-or-nothing | Response is not recognizable as true/false |
| Any | — | Blank = 0 points | Recognition confidence < `EXAM_RECOGNITION_CONFIDENCE_THRESHOLD` (default 0.85; applies to OCR input, not typed answers) |

## Statistics (results page and API)

Computed from **finalized** results only; sample size is always shown and n < 30 is flagged as indicative only.

* **Mean, median, highest, lowest, sample standard deviation, pass count/rate** (pass = percentage ≥ the exam's passing score).
* **Item difficulty (p)**: mean of awarded ÷ maximum points. For all-or-nothing items this is the proportion answering correctly; for proportional-credit items it is labelled *normalized mean score*.
* **Discrimination (D)**: upper-lower index using the top and bottom 27% of examinees ranked by total score: D = p(upper) − p(lower). Not computed below 10 examinees.
* **Distractor analysis**: count and percentage selecting each option (and blanks), from the effective (corrected) answer.

## REST API (`/api/v1`)

Bearer token from `POST /auth/login` (`email`, `password`, `device_name`). Tokens expire after `EXAM_API_TOKEN_DAYS` (default 30). Errors are JSON; validation errors return 422.

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/login`, `/auth/logout` · GET `/me` | |
| GET | `/dashboard` | Counts for the current user |
| GET | `/offerings` · `/offerings/{id}/students?q=` | Visible classes; roster search for student selection |
| GET/POST | `/exams` · `/offerings/{id}/exams` · `/exams/{id}` | Answer keys included only for graders |
| POST | `/exams/{id}/questions` · `/exams/{id}/publish` | |
| POST | `/exams/{id}/submissions` | `{student_id \| student_number, answers: {"1": "B"}}` → 202; 409 on duplicate |
| GET | `/submissions/{id}` · `/submissions/{id}/status` | Status is a light polling endpoint |
| POST | `/submissions/{id}/process` · `/submissions/{id}/finalize` | |
| GET | `/review-tasks` · `/grading-results/{id}` | |
| PATCH | `/grading-results/{id}` | `action`: `correct_answer` (`answer`), `override` (`score`, `reason`, `feedback`), `confirm` |
| POST | `/exams/{id}/release` | Releases all finalized results of the exam |
| GET | `/exams/{id}/analytics` · `/exams/{id}/item-analysis` | |

## Tests

```bash
php artisan test
```

76 tests cover grader rules, the full web workflow, API workflow, authorization (cross-teacher, chair, admin, deactivated users), grading idempotency, OCR-confidence review routing, job failure handling, and hand-computed item-analysis values. The suite passes on SQLite (in-memory) and MySQL 8. To run against MySQL, point `DB_*` at an empty test database.

## Roadmap

1. **Phase 2:** identification (accepted answers, normalization, fuzzy match → review) and problem solving (numeric tolerance, units, rubric partial credit); rubrics and exam versioning after publish; CSV student import; Excel export.
2. **Phase 3:** scan upload (PDF/JPEG/PNG) with private storage, OpenCV page alignment, replaceable OCR adapter interface, answer-region extraction, uncertain-recognition review with image crops.
3. **Phase 4:** isolated code-execution worker (container, no network, CPU/memory/time limits) for C and Python, then Verilog via simulator + testbench.
4. **Phase 5:** Android app (Kotlin, CameraX, Retrofit, encrypted token storage, upload queue with retry).
5. Post-release amendment workflow, notifications, chair-level authorized override permission, job monitoring screen.
