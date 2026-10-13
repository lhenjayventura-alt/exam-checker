<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Examination lifecycle: draft → published → closed → archived.
 * Questions and answer keys may change only while the current version is unlocked.
 */
class ExamService
{
    public function create(CourseOffering $offering, array $data, User $teacher): Exam
    {
        return DB::transaction(function () use ($offering, $data, $teacher) {
            $exam = $offering->exams()->create([
                ...$data,
                'status' => 'draft',
                'created_by' => $teacher->id,
            ]);
            $exam->versions()->create(['version_number' => 1]);
            AuditLog::record('exam.created', $exam, [], ['title' => $exam->title]);

            return $exam;
        });
    }

    public function update(Exam $exam, array $data): Exam
    {
        $this->assertEditable($exam);
        $exam->update($data);

        return $exam;
    }

    /**
     * @param  array{number?: ?int, type: string, prompt?: ?string, points: float|int|string,
     *               options?: list<array{label: string, text?: ?string}>, correct: list<string>,
     *               multiple_select?: bool, partial_credit?: string}  $data  Already validated.
     */
    public function addQuestion(Exam $exam, array $data): Question
    {
        $this->assertEditable($exam);

        return DB::transaction(function () use ($exam, $data) {
            $version = $exam->currentVersion()->lockForUpdate()->firstOrFail();
            $number = $data['number'] ?? ((int) $version->questions()->max('number') + 1);
            if ($version->questions()->where('number', $number)->exists()) {
                throw ValidationException::withMessages(['number' => "Question {$number} already exists."]);
            }

            $config = match ($data['type']) {
                'multiple_choice' => [
                    'multiple_select' => (bool) ($data['multiple_select'] ?? false),
                    'partial_credit' => $data['partial_credit'] ?? 'none',
                ],
                'identification' => [
                    'case_sensitive' => (bool) ($data['case_sensitive'] ?? false),
                    'fuzzy' => (bool) ($data['fuzzy'] ?? false),
                ],
                'problem_solving' => [
                    'tolerance' => (float) ($data['tolerance'] ?? 0),
                    'tolerance_type' => $data['tolerance_type'] ?? 'absolute',
                    'units' => array_values($data['units'] ?? []),
                    'unit_required' => (bool) ($data['unit_required'] ?? false),
                    'answer_points' => (float) ($data['answer_points'] ?? $data['points']),
                ],
                'coding' => [
                    'language' => $data['language'],
                    'time_limit_ms' => (int) ($data['time_limit_ms'] ?? 2000),
                    'memory_mb' => (int) ($data['memory_mb'] ?? 128),
                    'comparison' => $data['comparison'] ?? 'trim',
                    'answer_points' => (float) collect($data['tests'])->sum(fn ($t) => (float) $t['points']),
                ],
                default => null,
            };

            $question = $version->questions()->create([
                'number' => $number,
                'type' => $data['type'],
                'prompt' => $data['prompt'] ?? null,
                'points' => $data['points'],
                'config' => $config,
            ]);

            if ($data['type'] === 'multiple_choice') {
                foreach (array_values($data['options']) as $i => $option) {
                    $question->options()->create([
                        'label' => strtoupper($option['label']),
                        'text' => $option['text'] ?? null,
                        'position' => $i + 1,
                    ]);
                }
                $correct = array_values(array_unique(array_map('strtoupper', $data['correct'])));
                sort($correct);
            } elseif ($data['type'] === 'true_false') {
                $correct = [strtolower($data['correct'][0])];
            } elseif ($data['type'] === 'identification') {
                // Display forms are stored; the grader normalizes when comparing.
                $correct = array_values(array_unique(array_map('trim', $data['accepted_answers'])));
            } elseif ($data['type'] === 'problem_solving') {
                $correct = [(string) (float) $data['expected']];
            } else {
                // Coding: the test cases are the key.
                $correct = null;
                foreach (array_values($data['tests']) as $i => $test) {
                    $question->testCases()->create([
                        'name' => $test['name'],
                        'input' => $test['input'] ?? '',
                        'expected_output' => $test['expected_output'] ?? '',
                        'points' => $test['points'],
                        'is_hidden' => (bool) ($test['hidden'] ?? false),
                        'position' => $i + 1,
                    ]);
                }
            }

            if (in_array($data['type'], ['problem_solving', 'coding'], true)) {
                foreach (array_values($data['criteria'] ?? []) as $i => $criterion) {
                    $question->rubricCriteria()->create([
                        'label' => $criterion['label'],
                        'description' => $criterion['description'] ?? null,
                        'max_points' => $criterion['max_points'],
                        'position' => $i + 1,
                    ]);
                }
            }

            if ($correct !== null) {
                $question->answerKey()->create(['correct_values' => $correct]);
            }

            return $question->load('options', 'answerKey', 'rubricCriteria', 'testCases');
        });
    }

    public function deleteQuestion(Exam $exam, Question $question): void
    {
        $this->assertEditable($exam);
        if ($question->exam_version_id !== $exam->currentVersion->id) {
            throw ValidationException::withMessages(['question' => 'That question does not belong to the editable version.']);
        }
        $question->delete();
    }

    /** Validate completeness, lock the version so it is immutable, and open the exam for submissions. */
    public function publish(Exam $exam, User $teacher): Exam
    {
        $this->assertEditable($exam);

        return DB::transaction(function () use ($exam, $teacher) {
            $version = $exam->currentVersion()->lockForUpdate()->firstOrFail();
            $questions = $version->questions()->with(['options', 'answerKey', 'rubricCriteria', 'testCases'])->get();

            $errors = [];
            if ($questions->isEmpty()) {
                $errors[] = 'Add at least one question before publishing.';
            }
            foreach ($questions as $q) {
                if (! in_array($q->type, Question::SUPPORTED_TYPES, true)) {
                    $errors[] = "Question {$q->number}: {$q->type} questions are not supported yet.";
                }
                $key = $q->answerKey?->correct_values ?? [];
                if ($q->type === 'coding') {
                    if ($q->testCases->isEmpty()) {
                        $errors[] = "Question {$q->number} needs at least one test case.";
                    }
                    if (abs($q->testCases->sum('points') + $q->rubricCriteria->sum('max_points') - $q->points) > 0.001) {
                        $errors[] = "Question {$q->number}: test points plus rubric points must equal the question's points.";
                    }
                } elseif ($key === []) {
                    $errors[] = "Question {$q->number} has no answer key.";
                } elseif ($q->type === 'multiple_choice' && array_diff($key, $q->options->pluck('label')->all()) !== []) {
                    $errors[] = "Question {$q->number}: the answer key refers to an option that does not exist.";
                }
                if ($q->points <= 0) {
                    $errors[] = "Question {$q->number} must be worth more than zero points.";
                }
                if ($q->type === 'problem_solving' && abs($q->answerPoints() + $q->rubricCriteria->sum('max_points') - $q->points) > 0.001) {
                    $errors[] = "Question {$q->number}: final-answer points plus rubric points must equal the question's points.";
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['exam' => $errors]);
            }

            $version->update(['locked_at' => now(), 'locked_by' => $teacher->id]);
            $exam->update(['status' => 'published']);
            AuditLog::record('exam.published', $exam, [], [
                'version' => $version->version_number,
                'total_points' => $version->totalPoints(),
            ]);

            return $exam->fresh();
        });
    }

    /**
     * Start a new draft version from the current locked one (questions, options, keys and
     * rubrics are copied). Existing submissions stay graded against their original version.
     * The exam returns to draft and stops accepting submissions until it is published again.
     */
    public function newVersion(Exam $exam, User $teacher): Exam
    {
        $current = $exam->currentVersion;
        if (! $current?->isLocked() || ! in_array($exam->status, ['published', 'closed'], true)) {
            throw ValidationException::withMessages(['exam' => 'A new version can be created only from a published or closed exam.']);
        }

        return DB::transaction(function () use ($exam, $current, $teacher) {
            $version = $exam->versions()->create(['version_number' => $current->version_number + 1]);

            foreach ($current->questions()->with(['options', 'answerKey', 'rubricCriteria', 'testCases'])->get() as $q) {
                $copy = $version->questions()->create($q->only(['number', 'type', 'prompt', 'points', 'config']));
                foreach ($q->options as $o) {
                    $copy->options()->create($o->only(['label', 'text', 'position']));
                }
                foreach ($q->testCases as $t) {
                    $copy->testCases()->create($t->only(['name', 'input', 'expected_output', 'points', 'is_hidden', 'position']));
                }
                foreach ($q->rubricCriteria as $c) {
                    $copy->rubricCriteria()->create($c->only(['label', 'description', 'max_points', 'position']));
                }
                if ($q->answerKey) {
                    $copy->answerKey()->create(['correct_values' => $q->answerKey->correct_values]);
                }
            }

            $exam->update(['status' => 'draft']);
            AuditLog::record('exam.version_created', $exam, ['version' => $current->version_number], [
                'version' => $version->version_number, 'by' => $teacher->id,
            ]);

            return $exam->fresh('currentVersion');
        });
    }

    public function close(Exam $exam): Exam
    {
        if ($exam->status !== 'published') {
            throw ValidationException::withMessages(['exam' => 'Only a published exam can be closed.']);
        }
        $exam->update(['status' => 'closed']);
        AuditLog::record('exam.closed', $exam);

        return $exam;
    }

    private function assertEditable(Exam $exam): void
    {
        if (! $exam->isDraft() || $exam->currentVersion?->isLocked()) {
            throw ValidationException::withMessages([
                'exam' => 'This exam has been published; its questions and answer key are locked.',
            ]);
        }
    }
}
