# Exam Checker

AI-assisted examination checking, grading and analytics. Laravel 12 backend and web dashboard, with a versioned REST API for the (planned) Android scanning app.

> **Status: Phase 4.** Multiple-choice, true/false, identification, problem-solving (numeric + rubric) and **coding (C, Python)** questions are graded, reviewed, finalized, released and analyzed per exam version. Answers come from typed entry, source-file upload, or **printed answer sheets scanned or photographed** and read locally by an OpenCV worker. Student code runs only in throwaway, network-less Docker containers. The Android app comes next (see [Roadmap](#roadmap)).

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.2 |
| Database | MySQL 8 (InnoDB enforced); SQLite works for quick local trials |
| Web UI | Blade, Tailwind CSS, Alpine.js (Laravel Breeze) |
| API auth | Laravel Sanctum personal access tokens |
| Background work | Laravel queue (`database` locally, Redis recommended in production) |
| Answer-sheet scanner | Python 3.10+ worker in `scanner/` (OpenCV, NumPy, PyMuPDF, qrcode), run as a separate process from queue jobs |
| Code sandbox | Docker; image built from `runner/Dockerfile` (Debian slim, gcc, Python 3); containers started by queue jobs |

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

### Scanner setup

The scanner runs on this server only; scans are never sent to an outside service.

```bash
python -m venv scanner/.venv
scanner/.venv/Scripts/python -m pip install -r scanner/requirements.txt   # Windows
# scanner/.venv/bin/python -m pip install -r scanner/requirements.txt     # Linux/macOS
scanner/.venv/Scripts/python -m unittest discover -s scanner/tests        # recognition round-trip tests
```

If the virtualenv lives elsewhere, set `SCANNER_PYTHON` to its interpreter. The admin dashboard and the scans page show whether the scanner is ready. Scans are processed by the queue worker, which must be running.

### Code runner setup

Coding questions need Docker on the machine that runs the **queue worker** (or a remote Docker host via `DOCKER_HOST`, which keeps student code off the app server entirely).

1. Install Docker (on Windows: Docker Desktop with the WSL 2 backend) and start it.
2. Build the sandbox image:

   ```bash
   docker build -t exam-checker-runner:1 runner/
   ```

3. Check the admin dashboard ("Code sandbox: Ready"), and run `php artisan test --group=sandbox` to execute the hostile-program tests against the real sandbox.

`CODE_RUNNER_DOCKER` and `CODE_RUNNER_IMAGE` override the docker binary and image name.

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
* **Versions**: publishing locks a version. *New version* copies it (questions, keys, rubrics) into an editable draft; the exam stops accepting submissions until the new version is published. Earlier submissions stay graded against their own version, and statistics are computed per version.
* **Student numbers** are stored upper-case so lookups behave identically on every database collation.

### Automatic grading rules

| Type | Normalization | Scoring | Sent to review when |
|---|---|---|---|
| Multiple choice | `b`, `A, C`, `ac`, `a;c` → `B`, `A,C` | Single answer: all-or-nothing. Multiple-select: all-or-nothing, or *proportional* = max(0, correct picks − wrong picks) ÷ number of keyed options × points | Two marks on a single-answer item; a letter that is not an option |
| True / false | `T`, `True`, `Tama` → `true`; `F`, `False`, `Mali` → `false` | All-or-nothing | Response is not recognizable as true/false |
| Identification | Lower-cased (unless *case-sensitive*); punctuation and repeated spaces removed; `+` and `#` kept (C++ ≠ C) | Full points when it matches any accepted answer (synonyms are additional accepted answers) | With *flag near misses*: edit distance ≤ 20% of the accepted answer (min. 1). Proposed at full credit but **never awarded automatically** |
| Problem solving | Numbers such as `12.5`, `1,250`, `1.2e-3`, `4.7 x 10^3`, `3/4`, with a trailing unit (`4.7 kΩ`) | Final answer: all of *answer points* or none, within ± absolute or ± % tolerance. Remaining points come from **rubric criteria scored by the teacher** | Unreadable number; unit missing (when required) or not in the accepted list (units are **not** converted, e.g. mA ≠ A); always when the question has a rubric |
| Any | — | Blank = 0 points | Recognition confidence < `EXAM_RECOGNITION_CONFIDENCE_THRESHOLD` (default 0.85; applies to OCR input, not typed answers) |

**Rubric scoring** (problem solving): the teacher scores every criterion (0 to its maximum, with optional comments). The automatically checked final-answer score is pre-filled; changing it requires a reason. The awarded score is the sum of the criterion scores, stored per criterion in `grading_details` with the machine's original final-answer score kept as `auto_score`.

## Answer sheets and scanning

1. **Print** (exam → *Answer sheets & scans*): a blank sheet, or **named sheets** pre-filled with each student's name and number. Each page has four corner markers and a QR code `EC1|layout|student|page|signature`, signed with the app key so codes from other exams or damaged codes are rejected. Bubble questions are laid out in columns; identification and problem-solving questions get write-in boxes. Sheets are tied to the published version and its geometry is stored (`answer_sheet_layouts`), so printed sheets stay readable.
2. **Upload** a multi-page PDF from a document scanner or a phone photo (PDF/JPEG/PNG, 20 MB by default). The original is stored privately and never modified; the same file cannot be uploaded twice.
3. **Recognition** (queue job → `scanner/omr_cli.py`): finds the corner markers, corrects perspective and orientation (upside-down and sideways pages), decodes the QR, measures how dark each bubble is, and crops each write-in box.
4. **Matching:** named sheets become submissions automatically. Blank sheets, unknown QR codes and students not on the roster wait in *Sheets that need you*, where the teacher picks the student next to the page image. Sheets for a student who already has a submission, sheets printed for an older version, and incomplete multi-page sheets are flagged and never graded twice.

**How marks are read.** A bubble's *darkness* is the share of dark pixels inside it. Under 15% is empty and over 55% is filled; at 35% or more it counts as marked. Anything between 15% and 55% (light marks, erasures, ticks) lowers recognition confidence below the threshold, so the answer goes to review with the cropped image and the measured darkness of every bubble. Two marks on a single-answer item also go to review.

**Handwriting is not read automatically.** Write-in boxes are cropped. An empty box is detected and scored as blank; otherwise the answer is sent to review as *Handwritten answer: transcribe it from the scanned image*, and the teacher's transcription is graded by the normal identification or problem-solving rules.

Files are stored on the private disk (`storage/app/private/scans/...`) and streamed only through authorized routes with `Cache-Control: no-store`.

## Coding questions

**Setup per question:** language (C11 with gcc, or Python 3), time limit per test (default 2 s), memory limit (default 128 MB), output comparison (ignore trailing whitespace, ignore all whitespace, or exact), test cases (stdin input, expected stdout, points, *hidden* flag), and optional code-quality rubric criteria. Test points plus rubric points must equal the question's points.

**Grading:** the program is compiled once, then run once per test. Each test whose output matches earns its points. A compile error, crash, timeout, memory or output overrun scores 0 on that test and is recorded (compiler output, exit code, time, stdout/stderr excerpts) for the teacher. With a rubric, the test score is pre-filled and the teacher scores the criteria (*Automated tests* + criteria = awarded score).

**Isolation (`DockerCodeRunner`):** every compile and every test runs in a new container with `--network none`, memory = swap limit (no swap), 1 CPU, 64 PIDs, read-only root filesystem, a 16 MB `noexec` `/tmp`, user 65534 (nobody), all capabilities dropped, `no-new-privileges`, file-size and open-file limits, no log retention, and a `timeout` wrapper. The source directory is mounted read-only for test runs. Output is read as it arrives and the container is killed past 64 KB. Only test *inputs* enter the sandbox; expected outputs, including hidden tests, stay in the app and are compared in PHP, so a program cannot fake a pass by printing structured data.

**Infrastructure vs. academic errors:** a compile error is the student's result. If Docker is down, the image is missing or the daemon errors, the grading job throws `CodeRunnerUnavailable`, retries, and finally marks the submission *failed* with the reason. It is never scored 0. *Grade again* re-runs it once the sandbox is back.

**Submitting code:** paste it in the answer form or upload a `.c`/`.py` file (UTF-8 text, 64 KB). Code is stored verbatim; whitespace is preserved end to end. Paper answer sheets leave coding questions out (the sheet says so); for scanned submissions the teacher adds the student's code under *Provide or replace the code*. Code is never executed in a web request; the tests re-run on the queue.

**Not yet supported:** Verilog/HDL (simulator + testbench), multi-file projects, interactive programs, and floating-point tolerance in output comparison.

## Rosters

Add students one at a time or import a CSV (`student_number, last_name, first_name[, middle_name]`, up to 1,000 rows / 1 MB; template on the class page). Every row is validated first and **nothing is imported if any row has an error**; errors are reported by row. Existing student numbers reuse the existing record. If the file's name differs from the record on file, the record is kept and a warning is shown.

## Statistics (results page and API)

Computed from **finalized** results of **one exam version** (default: the newest version with results; choose another with the version selector or `?version=N`). Sample size is always shown and n < 30 is flagged as indicative only.

* **Mean, median, highest, lowest, sample standard deviation, pass count/rate** (pass = percentage ≥ the exam's passing score).
* **Item difficulty (p)**: mean of awarded ÷ maximum points. For all-or-nothing items this is the proportion answering correctly; for partial-credit items (proportional multiple-select, problem solving) it is labelled *normalized mean score*.
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
| POST | `/exams/{id}/questions` · `/exams/{id}/publish` · `/exams/{id}/versions` | Question fields per type are documented in `StoreQuestionRequest`; `versions` clones the locked version into a draft |
| POST | `/exams/{id}/submissions` | `{student_id \| student_number, answers: {"1": "B", "5": "<source code>"}}` (or multipart `answer_files[5]`) → 202; 409 on duplicate |
| GET | `/submissions/{id}` · `/submissions/{id}/status` | Status is a light polling endpoint |
| POST | `/submissions/{id}/process` · `/submissions/{id}/finalize` | |
| GET | `/review-tasks` · `/grading-results/{id}` | |
| PATCH | `/grading-results/{id}` | `action`: `correct_answer` (`answer`, or `code` for coding questions), `override` (`score`, `reason`, `feedback`), `confirm`, `score_rubric` (`criteria: {id: {score, comment}}`, optional `answer_score` + `reason`); responses include compile/test `executions` |
| POST | `/exams/{id}/release` | Releases all finalized results of the exam |
| POST | `/exams/{id}/scans` | Multipart `scan` (PDF/JPEG/PNG) → 202; poll `GET /scan-uploads/{id}` for sheet statuses |
| POST | `/scan-sheets/{id}/assign` | `{student_id}` for sheets that could not be matched |
| GET | `/submission-files/{id}` | Scanned page or answer crop (authorized, not cached) |
| GET | `/exams/{id}/analytics` · `/exams/{id}/item-analysis` | Optional `?version=N` |

## Tests

```bash
php artisan test
```

157 PHP tests cover grader rules (including identification normalization, fuzzy review, numeric parsing, tolerance and units), the web and API workflows, rubric scoring, versioning, roster import, exports, scanning (matching, assignment, duplicates, forged QR codes, multi-page grouping, version mismatch, upload validation), authorization (cross-teacher, chair, admin, deactivated users), grading idempotency, recognition-confidence review routing, job failure handling, and hand-computed item-analysis values. `ScannerIntegrationTest` prints sheets through the app, fills them in (including a skewed, noisy phone photo), uploads them and checks the graded result with the **real** Python worker; it is skipped when the scanner is not installed. `DockerCodeRunnerTest` checks every container invocation for each isolation flag and the exit-code mapping without Docker; `DockerSandboxIntegrationTest` (group `sandbox`) runs real and deliberately hostile programs (network access, filesystem writes, fork bomb, memory hog, output flood, infinite loop) and is skipped until Docker is available.

The scanner's own tests (`scanner/tests`) render sheets, mark them, distort them and read them back.

The suite passes on SQLite (in-memory) and MySQL 8. To run against MySQL, point `DB_*` at an empty test database.

## Roadmap

1. ~~**Phase 2:** identification, problem solving with rubrics, exam versioning, CSV roster import, Excel export.~~ Done. Known limits: no unit conversion, no symbolic math checking, rubric is per question (not reusable across questions).
2. ~~**Phase 3:** printable answer sheets, scan upload, local OpenCV recognition behind a replaceable `SheetRecognizer` interface, answer crops, review with images.~~ Done. Known limits: recognition thresholds were tuned on synthetic marks and **should be calibrated with real pencil/pen scans from your printers**; handwriting is transcribed by teachers (an OCR/handwriting engine can be added as another `SheetRecognizer`); no student-number bubble grid (use named sheets or assign manually).
3. ~~**Phase 4:** coding questions (C, Python) in an isolated Docker sandbox.~~ Done; Verilog via simulator + testbench is still open.
4. **Phase 5:** Android app (Kotlin, CameraX, Retrofit, encrypted token storage, upload queue with retry).
5. Post-release amendment workflow, notifications, chair-level authorized override permission, job monitoring screen.
