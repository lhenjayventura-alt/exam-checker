<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamVersion;
use App\Models\GradingResult;
use App\Models\RecognizedAnswer;
use Illuminate\Support\Collection;

/**
 * Score statistics and item analysis computed only from FINALIZED results.
 *
 * Definitions (also shown in the UI):
 *  - Item difficulty (p): mean of awarded/max points across examinees. For questions scored
 *    all-or-nothing this equals the proportion answering correctly. For questions with partial
 *    credit it is a normalized mean score and is labelled as such.
 *  - Discrimination (D): upper-lower group index. Examinees are ranked by total score; the top
 *    and bottom 27% (at least 1 each) form the groups; D = p(upper) - p(lower). Not computed
 *    below MIN_N_DISCRIMINATION examinees.
 *  - Distractor analysis: count and percentage of examinees selecting each option, from the
 *    effective (teacher-corrected if applicable) answer.
 *
 * Statistics are computed per exam version, because versions can differ in questions and points.
 */
class ExamAnalyticsService
{
    public const MIN_N_DISCRIMINATION = 10;

    /** Below this many examinees, statistics are flagged as unreliable. */
    public const SMALL_SAMPLE_N = 30;

    /** The version analysed by default: the newest version that has finalized results, else the current one. */
    public function defaultVersion(Exam $exam): ?ExamVersion
    {
        return $exam->versions()
            ->whereHas('submissions', fn ($q) => $q->where('status', 'finalized'))
            ->orderByDesc('version_number')->first()
            ?? $exam->currentVersion;
    }

    private function resultsFor(Exam $exam, ?ExamVersion $version, array $columns = ['*']): Collection
    {
        return $exam->results()
            ->when($version, fn ($q) => $q->whereHas('submission', fn ($s) => $s->where('exam_version_id', $version->id)))
            ->get($columns);
    }

    public function summary(Exam $exam, ?ExamVersion $version = null): array
    {
        $version ??= $this->defaultVersion($exam);
        $results = $this->resultsFor($exam, $version);
        $percentages = $results->pluck('percentage')->map(fn ($v) => (float) $v)->sort()->values();
        $scores = $results->pluck('total_score')->map(fn ($v) => (float) $v)->sort()->values();
        $n = $scores->count();

        $distribution = array_fill(0, 10, 0);
        foreach ($percentages as $p) {
            $distribution[min(9, intdiv((int) floor($p), 10))]++;
        }

        return [
            'n' => $n,
            'max_score' => $n ? (float) $results->first()->max_score : null,
            'mean' => $n ? round($scores->avg(), 2) : null,
            'median' => $n ? round($this->median($scores), 2) : null,
            'highest' => $n ? $scores->last() : null,
            'lowest' => $n ? $scores->first() : null,
            'std_dev' => $n > 1 ? round($this->sampleStdDev($scores), 2) : null,
            'passed' => $results->where('passed', true)->count(),
            'pass_rate' => $n ? round($results->where('passed', true)->count() / $n * 100, 2) : null,
            'distribution' => collect($distribution)->map(fn ($count, $i) => [
                'range' => ($i * 10).'–'.($i === 9 ? 100 : $i * 10 + 9).'%',
                'count' => $count,
            ])->all(),
            'small_sample' => $n < self::SMALL_SAMPLE_N,
            'version' => $version?->version_number,
        ];
    }

    public function itemAnalysis(Exam $exam, ?ExamVersion $version = null): array
    {
        $version ??= $this->defaultVersion($exam);
        $results = $this->resultsFor($exam, $version, ['submission_id', 'total_score']);
        $n = $results->count();
        $questions = $version ? $version->questions()->with(['options', 'answerKey'])->get() : collect();

        if ($n === 0 || $questions->isEmpty()) {
            return ['n' => $n, 'items' => [], 'small_sample' => true, 'discrimination_computed' => false, 'version' => $version?->version_number];
        }

        $submissionIds = $results->pluck('submission_id');
        $grades = GradingResult::whereIn('submission_id', $submissionIds)->get()->groupBy('question_id');
        $answers = RecognizedAnswer::whereIn('submission_id', $submissionIds)->get()->groupBy('question_id');

        // Rank examinees for the upper/lower groups.
        $ranked = $results->sortByDesc('total_score')->pluck('submission_id')->values();
        $k = max(1, (int) round($n * 0.27));
        $upper = $ranked->take($k)->all();
        $lower = $ranked->reverse()->take($k)->all();
        $computeD = $n >= self::MIN_N_DISCRIMINATION;

        $items = $questions->map(function ($q) use ($grades, $answers, $upper, $lower, $computeD, $n) {
            /** @var Collection $qGrades */
            // toBase(): Eloquent's only() matches model primary keys; here we need submission-id array keys.
            $qGrades = ($grades->get($q->id) ?? collect())->keyBy('submission_id')->toBase();
            $normalized = fn ($ids) => $qGrades->only($ids)
                ->map(fn ($g) => $g->max_points > 0 ? $g->awarded_score / $g->max_points : 0.0);

            $all = $normalized($qGrades->keys()->all());
            $partial = $q->allowsPartialCredit();

            $item = [
                'question_id' => $q->id,
                'number' => $q->number,
                'type' => $q->type,
                'points' => (float) $q->points,
                'n' => $all->count(),
                'difficulty' => $all->count() ? round($all->avg(), 3) : null,
                'difficulty_method' => $partial ? 'normalized mean score' : 'proportion correct',
                'discrimination' => $computeD
                    ? round($normalized($upper)->avg() - $normalized($lower)->avg(), 3)
                    : null,
                'distractors' => null,
            ];

            if ($q->type === 'multiple_choice') {
                $item['distractors'] = $this->distractors($q, $answers->get($q->id) ?? collect(), $n);
            }

            return $item;
        })->all();

        return [
            'n' => $n,
            'group_size' => $k,
            'items' => $items,
            'small_sample' => $n < self::SMALL_SAMPLE_N,
            'discrimination_computed' => $computeD,
            'version' => $version->version_number,
        ];
    }

    private function distractors($question, Collection $answers, int $n): array
    {
        $key = $question->answerKey?->correct_values ?? [];
        $counts = $question->options->mapWithKeys(fn ($o) => [$o->label => 0])->all();
        $blank = 0;
        $other = 0;

        foreach ($answers as $answer) {
            $value = $answer->effectiveValue();
            if ($value === null || $value === '') {
                $blank++;

                continue;
            }
            foreach (explode(',', $value) as $label) {
                array_key_exists($label, $counts) ? $counts[$label]++ : $other++;
            }
        }

        $rows = [];
        foreach ($counts as $label => $count) {
            $rows[] = [
                'label' => $label,
                'is_key' => in_array($label, $key, true),
                'count' => $count,
                'percent' => $n ? round($count / $n * 100, 1) : 0,
            ];
        }
        $rows[] = ['label' => 'Blank', 'is_key' => false, 'count' => $blank, 'percent' => $n ? round($blank / $n * 100, 1) : 0];
        if ($other > 0) {
            $rows[] = ['label' => 'Invalid', 'is_key' => false, 'count' => $other, 'percent' => round($other / $n * 100, 1)];
        }

        return $rows;
    }

    private function median(Collection $sorted): float
    {
        $n = $sorted->count();
        $mid = intdiv($n, 2);

        return $n % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }

    private function sampleStdDev(Collection $values): float
    {
        $mean = $values->avg();

        return sqrt($values->sum(fn ($v) => ($v - $mean) ** 2) / ($values->count() - 1));
    }
}
