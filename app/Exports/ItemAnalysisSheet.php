<?php

namespace App\Exports;

use App\Models\Question;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ItemAnalysisSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly int $versionNumber, private readonly array $analysis) {}

    public function title(): string
    {
        return 'Items v'.$this->versionNumber;
    }

    public function headings(): array
    {
        return ['Item', 'Type', 'Points', 'n', 'Difficulty', 'Difficulty method', 'Discrimination (D)', 'Response distribution'];
    }

    public function array(): array
    {
        $rows = array_map(fn ($item) => [
            $item['number'],
            Question::TYPES[$item['type']] ?? $item['type'],
            $item['points'],
            $item['n'],
            $item['difficulty'],
            $item['difficulty_method'],
            $item['discrimination'] ?? 'not computed',
            $item['distractors']
                ? collect($item['distractors'])->map(fn ($d) => "{$d['label']}: {$d['count']} ({$d['percent']}%)".($d['is_key'] ? ' [key]' : ''))->join('; ')
                : '',
        ], $this->analysis['items']);

        $rows[] = [];
        $rows[] = ['Examinees (finalized)', $this->analysis['n']];
        $rows[] = ['Upper/lower group size', $this->analysis['group_size'] ?? ''];
        if ($this->analysis['small_sample']) {
            $rows[] = ['Note', 'Small sample: treat these figures as indicative, not definitive.'];
        }

        return $rows;
    }
}
