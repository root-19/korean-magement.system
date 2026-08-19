<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Models\ClassSession;
use App\Models\SessionReport;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The class report form.
 *
 * Grammar, pronunciation and vocabulary are repeatable rows stored as JSON in
 * one TEXT column each — the format the legacy feedback page wrote and the
 * importer copies verbatim. The form used to render those columns in a plain
 * textarea, which showed imported reports as raw JSON and replaced the structure
 * with free text on save. These pin the round-trip down.
 */
class SessionReportFormTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    private string $date = '2026-08-03';

    protected function setUp(): void
    {
        parent::setUp();

        // The report form marks attendance on $date, and a class is only markable
        // on the day it happened, so the clock sits on that day.
        Carbon::setTestNow($this->date.' 10:00:00');
        CarbonImmutable::setTestNow($this->date.' 10:00:00');

        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create(['name' => 'A194 Report Student']);

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => $this->student->id,
            'class_date' => $this->date,
            'today_lesson' => 'Can You Believe it 1 story 8 p35 (4)',
            'next_lesson' => 'Can You Believe it 1 story 9 p38',
            'grammar' => [
                ['yourSentence' => 'I am very tiring', 'betterSay' => 'I am very tired'],
            ],
            'pronunciation' => [
                ['word' => 'ruler', 'comment' => 'Careful with your L and R sounds'],
            ],
            'vocabulary' => [
                ['vocab' => 'set in stone', 'example' => 'Nothing is set in stone yet.'],
            ],
            'listening_score' => 4,
            'speaking_score' => 4,
            'pronunciation_score' => 3,
            'vocabulary_score' => 5,
            'grammar_score' => 4,
            'teacher_comments' => 'Thank you for your time today! Happy midweek!',
        ], $overrides);
    }

    #[Test]
    public function rows_are_stored_in_the_legacy_json_shape(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.reports.store'), $this->payload())
            ->assertRedirect(route('instructor.reports.index'))
            ->assertSessionHasNoErrors();

        $report = SessionReport::firstOrFail();

        // Exactly the keys legacy wrote, because imported rows already use them.
        $this->assertSame(
            [['yourSentence' => 'I am very tiring', 'betterSay' => 'I am very tired']],
            json_decode($report->grammar_section, true),
        );
        $this->assertSame(
            [['word' => 'ruler', 'comment' => 'Careful with your L and R sounds']],
            json_decode($report->pronunciation_section, true),
        );
        $this->assertSame(
            [['vocab' => 'set in stone', 'example' => 'Nothing is set in stone yet.']],
            json_decode($report->vocab_section, true),
        );
    }

    #[Test]
    public function many_rows_per_section_are_kept_in_order(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.reports.store'), $this->payload([
                'grammar' => [
                    ['yourSentence' => 'First wrong', 'betterSay' => 'First right'],
                    ['yourSentence' => 'Second wrong', 'betterSay' => 'Second right'],
                    ['yourSentence' => 'Third wrong', 'betterSay' => 'Third right'],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $rows = SessionReport::firstOrFail()->rows('grammar_section');

        $this->assertCount(3, $rows);
        $this->assertSame('Second wrong', $rows[1]['yourSentence']);
        $this->assertSame('Third right', $rows[2]['betterSay']);
    }

    #[Test]
    public function blank_rows_are_dropped_and_an_empty_section_stays_null(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.reports.store'), $this->payload([
                'grammar' => [
                    ['yourSentence' => 'Kept', 'betterSay' => ''],
                    ['yourSentence' => '', 'betterSay' => ''],
                    ['yourSentence' => '  ', 'betterSay' => '  '],
                ],
                'pronunciation' => [['word' => '', 'comment' => '']],
            ]))
            ->assertSessionHasNoErrors();

        $report = SessionReport::firstOrFail();

        $this->assertCount(1, $report->rows('grammar_section'));

        // Null, not "[]": indistinguishable from a report filed before the
        // section existed, which is what the earnings history relies on.
        $this->assertNull($report->pronunciation_section);
        $this->assertSame([], $report->rows('pronunciation_section'));
    }

    #[Test]
    public function the_form_shows_saved_rows_in_their_fields(): void
    {
        $this->actingAs($this->instructor)->post(route('instructor.reports.store'), $this->payload());

        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.create', ['student_id' => $this->student->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee('I am very tiring')
            ->assertSee('Careful with your L and R sounds')
            // …and not as the raw column, which is what the old textarea showed.
            ->assertDontSee('yourSentence&quot;:&quot;I am very tiring', false);
    }

    #[Test]
    public function free_text_left_by_the_old_textarea_is_not_thrown_away(): void
    {
        // Reports saved while this form rendered a plain textarea hold prose, not
        // JSON. It surfaces in the first field rather than vanishing.
        $report = SessionReport::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->date,
            'grammar_section' => 'He go to school every day -> He goes to school every day',
        ]);

        $rows = $report->rows('grammar_section');

        $this->assertCount(1, $rows);
        $this->assertSame('He go to school every day -> He goes to school every day', $rows[0]['yourSentence']);

        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.create', ['student_id' => $this->student->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee('He go to school every day');
    }

    #[Test]
    public function the_evaluation_offers_the_five_point_scale(): void
    {
        $html = $this->actingAs($this->instructor)
            ->get(route('instructor.reports.create', ['student_id' => $this->student->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee('Class Evaluation')
            ->assertSee('all scores are required')
            ->getContent();

        foreach (SessionReport::SCORE_FIELDS as $field => $label) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }

        $this->assertSame(5, SessionReport::SCORE_MAX);
    }

    #[Test]
    public function a_score_saved_on_the_old_ten_point_scale_survives_an_edit(): void
    {
        SessionReport::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'class_date' => $this->date,
            'listening_score' => 9,
        ]);

        // Still offered by the select, so re-saving cannot blank it…
        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.create', ['student_id' => $this->student->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee('<option value="9"', false);

        // …and validation still accepts it.
        $this->actingAs($this->instructor)
            ->post(route('instructor.reports.store'), $this->payload(['listening_score' => 9]))
            ->assertSessionHasNoErrors();

        $this->assertSame(9, SessionReport::firstOrFail()->listening_score);
    }

    #[Test]
    public function saving_twice_edits_the_report_rather_than_duplicating_it(): void
    {
        $this->actingAs($this->instructor)->post(route('instructor.reports.store'), $this->payload());

        $this->actingAs($this->instructor)
            ->post(route('instructor.reports.store'), $this->payload([
                'grammar' => [['yourSentence' => 'Replaced', 'betterSay' => 'Replaced better']],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SessionReport::count());
        $this->assertSame('Replaced', SessionReport::firstOrFail()->rows('grammar_section')[0]['yourSentence']);
    }

    #[Test]
    public function the_plan_progress_is_on_the_page_and_in_the_copied_text(): void
    {
        // 15 on the plan, 5 taught, 10 left — reads as "5/15" wherever it appears.
        StudentProfile::where('user_id', $this->student->id)->update([
            'sessions_attended' => 5,
            'sessions_remaining' => 10,
            'sessions_deducted' => 0,
        ]);

        $html = $this->actingAs($this->instructor)
            ->get(route('instructor.reports.create', ['student_id' => $this->student->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee('5/15')
            ->assertSee('10 remaining')
            ->getContent();

        // Copy All builds its text from this state, so the figures must reach it.
        // Serialised the same way the view does, rather than guessing at @js()'s
        // attribute escaping.
        $this->assertStringContainsString(
            (string) Js::from([
                'attended' => 5,
                'purchased' => 15,
                'remaining' => 10,
                'student_absent' => 0,
                'teacher_absent' => 0,
                'student_postponed' => 0,
                'teacher_postponed' => 0,
            ]),
            $html,
        );
        $this->assertStringContainsString('this.progress.attended', $html);
    }

    #[Test]
    public function absences_and_postponements_are_split_by_who_is_responsible(): void
    {
        StudentProfile::where('user_id', $this->student->id)->update([
            'sessions_attended' => 5,
            'sessions_remaining' => 10,
            'sessions_deducted' => 0,
        ]);

        ClassSession::factory()->for($this->instructor, 'instructor')->for($this->student, 'student')
            ->studentAbsent()->on('2026-07-01')->create();
        ClassSession::factory()->for($this->instructor, 'instructor')->for($this->student, 'student')
            ->teacherAbsent()->on('2026-07-02')->create();
        ClassSession::factory()->for($this->instructor, 'instructor')->for($this->student, 'student')
            ->postponed()->on('2026-07-03')->create();
        ClassSession::factory()->for($this->instructor, 'instructor')->for($this->student, 'student')
            ->postponed()->state(['postponed_by' => Party::Teacher])->on('2026-07-04')->create();

        $html = $this->actingAs($this->instructor)
            ->get(route('instructor.reports.create', ['student_id' => $this->student->id, 'date' => $this->date]))
            ->assertOk()
            ->getContent();

        // purchased = attended(5) + student_absent(1) + remaining(10) + deducted(0)
        $this->assertStringContainsString(
            (string) Js::from([
                'attended' => 5,
                'purchased' => 16,
                'remaining' => 10,
                'student_absent' => 1,
                'teacher_absent' => 1,
                'student_postponed' => 1,
                'teacher_postponed' => 1,
            ]),
            $html,
        );
    }

    #[Test]
    public function a_student_absent_session_still_counts_against_the_plan_total(): void
    {
        // It burns a prepaid session while counting as neither attended nor
        // remaining, so leaving it out would under-report the plan size.
        StudentProfile::where('user_id', $this->student->id)->update([
            'sessions_attended' => 5,
            'sessions_remaining' => 9,
            'sessions_deducted' => 0,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->date,
                'status' => 'absent',
                'party' => 'student',
            ])
            ->assertSessionHasNoErrors();

        // attended 5 + student-absent 1 + remaining 8 + deducted 0 = 14
        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.create', ['student_id' => $this->student->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee('5/14');
    }

    #[Test]
    public function the_form_carries_the_legacy_section_headings_and_controls(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.reports.create', ['student_id' => $this->student->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee('Lesson Information')
            ->assertSee("(Please check and correct the student's grammar in this section)")
            ->assertSee('+ Add Grammar Row')
            ->assertSee('+ Add Pronunciation Row')
            ->assertSee('+ Add Vocabulary Row')
            // Literal markup, so the apostrophe is not HTML-escaped here the way
            // it is in the {{ }}-rendered hint above.
            ->assertSee("Teacher's Comments", false)
            ->assertSee('Copy All')
            ->assertSee('Save Feedback');
    }
}
