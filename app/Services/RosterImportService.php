<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Imports a class roster from CSV. All rows are validated first; nothing is written
 * unless every row is valid. Existing student numbers reuse the existing record (names in
 * the file are not used to overwrite it).
 *
 * Required header: student_number, last_name, first_name. Optional: middle_name.
 */
class RosterImportService
{
    public const MAX_ROWS = 1000;

    public const HEADERS = ['student_number', 'last_name', 'first_name', 'middle_name'];

    /**
     * @return array{ok: bool, errors: list<string>, created: int, linked: int, already: int, mismatched: list<string>}
     */
    public function import(CourseOffering $offering, string $path): array
    {
        $report = ['ok' => false, 'errors' => [], 'created' => 0, 'linked' => 0, 'already' => 0, 'mismatched' => []];

        [$rows, $error] = $this->read($path);
        if ($error) {
            $report['errors'][] = $error;

            return $report;
        }

        $seen = [];
        foreach ($rows as $line => $row) {
            $v = Validator::make($row, [
                'student_number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-]+$/'],
                'last_name' => ['required', 'string', 'max:100'],
                'first_name' => ['required', 'string', 'max:100'],
                'middle_name' => ['nullable', 'string', 'max:100'],
            ], ['student_number.regex' => 'student number may contain only letters, digits and hyphens']);

            foreach ($v->errors()->all() as $message) {
                $report['errors'][] = "Row {$line}: {$message}";
            }
            $number = strtoupper($row['student_number'] ?? '');
            if ($number !== '' && isset($seen[$number])) {
                $report['errors'][] = "Row {$line}: student number {$row['student_number']} also appears on row {$seen[$number]}.";
            }
            $seen[$number] = $line;
        }
        if ($report['errors'] !== []) {
            return $report;
        }

        DB::transaction(function () use ($offering, $rows, &$report) {
            // Student numbers are stored in canonical (uppercase) form.
            $numbers = array_map([Student::class, 'canonicalNumber'], array_column($rows, 'student_number'));
            $existing = Student::whereIn('student_number', $numbers)->get()->keyBy('student_number');
            $enrolled = $offering->students()->pluck('students.id')->flip();
            $attach = [];

            foreach ($rows as $row) {
                $student = $existing->get(Student::canonicalNumber($row['student_number']));
                if ($student === null) {
                    $student = Student::create([
                        'student_number' => $row['student_number'],
                        'last_name' => $row['last_name'],
                        'first_name' => $row['first_name'],
                        'middle_name' => $row['middle_name'] ?: null,
                        'program_id' => $offering->course->program_id,
                    ]);
                    $report['created']++;
                } elseif ($enrolled->has($student->id)) {
                    $report['already']++;

                    continue;
                } else {
                    $report['linked']++;
                    if (mb_strtolower($student->last_name) !== mb_strtolower($row['last_name'])) {
                        $report['mismatched'][] = "{$row['student_number']} is on file as {$student->fullName()}, not {$row['last_name']}, {$row['first_name']}.";
                    }
                }
                $attach[] = $student->id;
            }

            $offering->students()->syncWithoutDetaching($attach);
            AuditLog::record('roster.imported', $offering, [], [
                'created' => $report['created'], 'linked' => $report['linked'], 'already' => $report['already'],
            ]);
        });

        $report['ok'] = true;

        return $report;
    }

    /** @return array{0: array<int, array<string, string>>, 1: ?string} rows keyed by file line number, or an error */
    private function read(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [[], 'The file could not be read.'];
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return [[], 'The file is empty.'];
        }
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $missing = array_diff(['student_number', 'last_name', 'first_name'], $header);
        if ($missing !== []) {
            fclose($handle);

            return [[], 'Missing column(s): '.implode(', ', $missing).'. The first row must be a header: '.implode(',', self::HEADERS).'.'];
        }

        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if ($data === [null] || trim(implode('', $data)) === '') {
                continue; // blank line
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                return [[], 'The file has more than '.self::MAX_ROWS.' students; split it into smaller files.'];
            }
            $row = [];
            foreach ($header as $i => $column) {
                if (in_array($column, self::HEADERS, true)) {
                    $row[$column] = trim((string) ($data[$i] ?? ''));
                }
            }
            $row['middle_name'] ??= '';
            $rows[$line] = $row;
        }
        fclose($handle);

        if ($rows === []) {
            return [[], 'The file has a header but no student rows.'];
        }

        return [$rows, null];
    }
}
