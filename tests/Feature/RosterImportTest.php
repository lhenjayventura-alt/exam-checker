<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsExamFixtures;
use Tests\TestCase;

class RosterImportTest extends TestCase
{
    use BuildsExamFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('roster.csv', $content);
    }

    public function test_imports_new_students_and_links_existing_ones(): void
    {
        [$offering, $teacher, $roster] = $this->offeringWithTeacher(1);
        $existing = Student::factory()->create(['student_number' => 'TEST-9001', 'last_name' => 'Santos', 'first_name' => 'Ana']);
        $onRoster = $roster[0];

        // BOM, extra column, reordered columns, blank line, and a case-different existing number.
        $file = "\xEF\xBB\xBFlast_name,first_name,student_number,section\n"
            ."Reyes,Juan,TEST-9100,X\n"
            ."\n"
            ."Santos,Ana,test-9001,X\n"
            ."{$onRoster->last_name},{$onRoster->first_name},{$onRoster->student_number},X\n"
            ."Garcia,Maria,TEST-9101,X\n";

        $this->actingAs($teacher)->post(route('offerings.roster.import', $offering), ['roster_file' => $this->csv($file)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Roster imported: 2 new student(s) created, 1 existing student(s) added, 1 already on the roster.');

        $this->assertSame(4, $offering->students()->count());
        $this->assertTrue($offering->students()->whereKey($existing->id)->exists());
        $this->assertSame(1, Student::where('student_number', 'TEST-9001')->count());
        $this->assertSame($offering->course->program_id, Student::where('student_number', 'TEST-9100')->value('program_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'roster.imported']);
    }

    public function test_any_invalid_row_imports_nothing(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher(0);
        $file = "student_number,last_name,first_name\n"
            ."TEST-1,Reyes,Juan\n"
            ."TEST 2,Cruz,Jose\n"   // invalid number
            .",Lim,Ana\n"            // missing number
            ."TEST-1,Reyes,Juan\n";  // duplicate in file

        $response = $this->actingAs($teacher)->post(route('offerings.roster.import', $offering), ['roster_file' => $this->csv($file)]);

        $response->assertSessionHasErrors('roster_file');
        $errors = session('errors')->get('roster_file');
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_starts_with($e, 'Row 3:')));
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_starts_with($e, 'Row 4:')));
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, 'also appears on row 2')));
        $this->assertSame(0, Student::count());
        $this->assertSame(0, $offering->students()->count());
    }

    public function test_file_level_problems_are_reported(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher(0);
        $this->actingAs($teacher);

        $this->post(route('offerings.roster.import', $offering), ['roster_file' => $this->csv("id,name\n1,Ana\n")])
            ->assertSessionHasErrors(['roster_file' => 'Missing column(s): student_number, last_name, first_name. The first row must be a header: student_number,last_name,first_name,middle_name.']);
        $this->post(route('offerings.roster.import', $offering), ['roster_file' => $this->csv("student_number,last_name,first_name\n")])
            ->assertSessionHasErrors('roster_file');
        $this->post(route('offerings.roster.import', $offering), ['roster_file' => UploadedFile::fake()->image('roster.png')])
            ->assertSessionHasErrors('roster_file');
    }

    public function test_name_mismatch_on_existing_record_is_flagged(): void
    {
        [$offering, $teacher] = $this->offeringWithTeacher(0);
        Student::factory()->create(['student_number' => 'TEST-77', 'last_name' => 'Santos', 'first_name' => 'Ana']);

        $this->actingAs($teacher)->post(route('offerings.roster.import', $offering), [
            'roster_file' => $this->csv("student_number,last_name,first_name\nTEST-77,Bautista,Ana\n"),
        ])->assertSessionHas('import_warnings', fn ($w) => str_contains($w[0], 'TEST-77 is on file as Santos, Ana'));

        $this->assertSame('Santos', Student::where('student_number', 'TEST-77')->value('last_name'));
    }

    public function test_only_roster_managers_can_import_and_template_downloads(): void
    {
        [$offering] = $this->offeringWithTeacher(0);
        $this->actingAs($this->userWithRole(Role::TEACHER))
            ->post(route('offerings.roster.import', $offering), ['roster_file' => $this->csv("student_number,last_name,first_name\nX-1,A,B\n")])
            ->assertForbidden();

        $template = $this->get(route('roster.template'))->assertOk()->streamedContent();
        $this->assertSame('student_number,last_name,first_name,middle_name', trim($template));
    }
}
