<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\ClassSession;
use App\Models\SessionReport;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Listing and re-opening a filed report.
 *
 * The report list is history, so it outlives the enrolment it describes: a
 * student can be archived or handed to another instructor after the report was
 * written. Both used to break the page — the list rendered a null student, and
 * the edit link re-derived authorisation from the student's current assignment
 * rather than from the report itself.
 */
class SessionReportEditTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    private string $date = '2026-08-03';

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create(['name' => 'A194 Report Student']);

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
        ]);
    }

    private function report(array $overrides = []): SessionReport
    {
        return SessionReport::create(array_merge([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->date,
            'today_lesson' => 'Can You Believe it 1 story 8 p35 (4)',
            'next_lesson' => 'Can You Believe it 1 story 9 p38',
            'grammar_section' => json_encode([
                ['yourSentence' => 'I am very tiring', 'betterSay' => 'I am very tired'],
            ]),
            'listening_score' => 4,
            'teacher_comments' => 'Great work today!',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'today_lesson' => 'Edited lesson',
            'next_lesson' => 'Edited next lesson',
            'grammar' => [['yourSentence' => 'Edited wrong', 'betterSay' => 'Edited right']],
            'pronunciation' => [['word' => 'ruler', 'comment' => 'Careful with L and R']],
            'vocabulary' => [['vocab' => 'set in stone', 'example' => 'Nothing is set in stone yet.']],
            'listening_score' => 5,
            'speaking_score' => 4,
            'pronunciation_score' => 3,
            'vocabulary_score' => 5,
            'grammar_score' => 4,
            'teacher_comments' => 'Edited comments.',
        ], $overrides);
    }

    #[Test]
    public function the_list_renders_a_report_whose_student_has_been_archived(): void
    {
        $report = $this->report();
        $this->student->delete();

        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.index'))
            ->assertOk()
            ->assertSee('A194 Report Student')
            ->assertSee('Archived')
            // The student page is bound to a live student, so linking there would
            // be a 404. The report stays editable either way.
            ->assertDontSee(route('instructor.students.show', $this->student->id), false)
            ->assertSee(route('instructor.reports.edit', $report), false);
    }

    #[Test]
    public function a_live_student_is_still_linked_from_the_list(): void
    {
        $this->report();

        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.index'))
            ->assertOk()
            ->assertSee(route('instructor.students.show', $this->student->id), false)
            ->assertDontSee('Archived');
    }

    #[Test]
    public function the_edit_page_opens_a_filed_report(): void
    {
        $report = $this->report();

        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.edit', $report))
            ->assertOk()
            ->assertSee('Edit class report')
            ->assertSee('I am very tiring')
            ->assertSee('Update Feedback')
            ->assertSee('action="'.route('instructor.reports.update', $report).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            // The natural key is not resubmitted, so an edit cannot carry a
            // different student or date than the one it was opened for.
            ->assertDontSee('name="student_id"', false)
            ->assertDontSee('name="class_date"', false);
    }

    #[Test]
    public function the_edit_page_opens_when_the_student_has_been_archived(): void
    {
        $report = $this->report();
        $this->student->delete();

        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.edit', $report))
            ->assertOk()
            ->assertSee('I am very tiring');
    }

    #[Test]
    public function the_edit_page_opens_after_the_student_was_reassigned(): void
    {
        $report = $this->report();

        StudentProfile::where('user_id', $this->student->id)
            ->update(['instructor_id' => User::factory()->instructor()->create()->id]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.edit', $report))
            ->assertOk()
            ->assertSee('I am very tiring');
    }

    #[Test]
    public function an_edit_updates_the_report_in_place(): void
    {
        $report = $this->report();

        $this->actingAs($this->instructor)
            ->put(route('instructor.reports.update', $report), $this->payload())
            ->assertRedirect(route('instructor.reports.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SessionReport::count());

        $report->refresh();

        $this->assertSame('Edited lesson', $report->today_lesson);
        $this->assertSame('Edited comments.', $report->teacher_comments);
        $this->assertSame(5, $report->listening_score);
        $this->assertSame('Edited wrong', $report->rows('grammar_section')[0]['yourSentence']);

        // The natural key is what earnings match on, so an edit must not move it.
        $this->assertSame($this->student->id, $report->student_id);
        $this->assertSame($this->date, $report->class_date->toDateString());
    }

    #[Test]
    public function an_edit_can_clear_a_section(): void
    {
        $report = $this->report();

        $this->actingAs($this->instructor)
            ->put(route('instructor.reports.update', $report), $this->payload(['grammar' => []]))
            ->assertSessionHasNoErrors();

        $this->assertNull($report->refresh()->grammar_section);
    }

    #[Test]
    public function another_instructors_report_cannot_be_opened_or_edited(): void
    {
        $report = $this->report();
        $other = User::factory()->instructor()->create();

        $this->actingAs($other)
            ->get(route('instructor.reports.edit', $report))
            ->assertForbidden();

        $this->actingAs($other)
            ->put(route('instructor.reports.update', $report), $this->payload())
            ->assertForbidden();

        $this->assertSame('Can You Believe it 1 story 8 p35 (4)', $report->refresh()->today_lesson);
    }

    #[Test]
    public function the_history_row_for_a_filed_report_opens_it_for_editing(): void
    {
        $session = ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->date,
        ]);

        $report = $this->report(['class_session_id' => $session->id]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.history.index'))
            ->assertOk()
            ->assertSee('Edit report')
            ->assertSee(route('instructor.reports.edit', $report), false)
            ->assertDontSee('>File<', false);
    }

    #[Test]
    public function a_history_row_with_no_report_still_offers_filing_one(): void
    {
        ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->date,
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.history.index'))
            ->assertOk()
            ->assertSee('>File<', false)
            ->assertDontSee('Edit report');
    }

    #[Test]
    public function the_list_links_each_report_to_its_edit_page(): void
    {
        $report = $this->report();

        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.index'))
            ->assertOk()
            ->assertSee(route('instructor.reports.edit', $report), false);
    }
}
