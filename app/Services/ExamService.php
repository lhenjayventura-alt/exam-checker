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

            $config = null;
            if ($data['type'] === 'multiple_choice') {
                $config = [
                    'multiple_select' => (bool) ($data['multiple_select'] ?? false),
                    'partial_credit' => $data['partial_credit'] ?? 'none',
                ];
            }

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
            } else {
                $correct = [strtolower($data['correct'][0])];
            }

            $question->answerKey()->create(['correct_values' => $correct]);

            return $question->load('options', 'answerKey');
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
            $questions = $version->questions()->with(['options', 'answerKey'])->get();

            $errors = [];
            if ($questions->isEmpty()) {
                $errors[] = 'Add at least one question before publishing.';
            }
            foreach ($questions as $q) {
                if (! in_array($q->type, Question::SUPPORTED_TYPES, true)) {
                    $errors[] = "Question {$q->number}: {$q->type} questions are not supported yet.";
                }
                $key = $q->answerKey?->correct_values ?? [];
                if ($key === []) {
                    $errors[] = "Question {$q->number} has no answer key.";
                } elseif ($q->type === 'multiple_choice' && array_diff($key, $q->options->pluck('label')->all()) !== []) {
                    $errors[] = "Question {$q->number}: the answer key refers to an option that does not exist.";
                }
                if ($q->points <= 0) {
                    $errors[] = "Question {$q->number} must be worth more than zero points.";
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
