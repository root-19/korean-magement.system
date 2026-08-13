<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regular or trial, as a choice made at enrolment and visible afterwards.
 *
 * The flag used to be one checkbox ticked by default, so a trial enrolled by an
 * instructor in a hurry was stored as regular and nothing downstream said
 * otherwise. It is now asked outright, and a trial student's name carries the
 * tag everywhere the instructor sees their day.
 */
class TrialStudentTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    /** A Wednesday. */
    private string $today = '2026-08-12';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow($this->today.' 09:00:00');
        Carbon::setTestNow($this->today.' 09:00:00');

        $this->instructor = User::factory()->instructor()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function enrol(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->instructor)->post(route('instructor.students.store'), array_merge([
            'name' => 'A700 Trial Taker',
            'teaching_method' => 'audio',
            'learning_time' => 25,
            'sessions_purchased' => 4,
            'schedule' => [3 => '09:30'],
        ], $overrides));
    }

    /** A student on today's roster, regular or trial. */
    private function rosteredStudent(string $name, bool $isRegular): User
    {
        $student = User::factory()->student()->create(['name' => $name]);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'is_regular' => $isRegular,
            'sessions_remaining' => 5,
        ]);

        StudentSchedule::create([
            'student_id' => $student->id,
            'day_of_week' => CarbonImmutable::parse($this->today)->dayOfWeekIso,
            'start_time' => '09:30:00',
        ]);

        return $student;
    }

    // ------------------------------------------------------------- the choice

    #[Test]
    public function the_enrol_form_asks_for_regular_or_trial(): void
    {
        $html = $this->actingAs($this->instructor)
            ->get(route('instructor.students.create'))
            ->assertOk()
            ->assertSee('Enrolment type')
            ->assertSee('Regular')
            ->assertSee('Trial')
            ->getContent();

        // Two radios, one per kind of enrolment — not one checkbox meaning both.
        $this->assertSame(2, substr_count($html, 'name="is_regular"'));
        $this->assertStringContainsString('value="1"', $html);
        $this->assertStringContainsString('value="0"', $html);
    }

    #[Test]
    public function neither_option_is_preselected(): void
    {
        // The old form ticked "Regular student" by default, which is how trials
        // were being saved as regulars.
        $html = $this->actingAs($this->instructor)
            ->get(route('instructor.students.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('value="1" required checked', $html);
        $this->assertStringNotContainsString('value="0" required checked', $html);
    }

    #[Test]
    public function enrolling_without_choosing_is_refused(): void
    {
        $this->enrol(['is_regular' => null])->assertSessionHasErrors('is_regular');

        $this->assertDatabaseMissing('users', ['name' => 'A700 Trial Taker']);
    }

    #[Test]
    public function choosing_trial_stores_a_trial(): void
    {
        $this->enrol(['is_regular' => 0])->assertSessionHasNoErrors();

        $student = User::where('name', 'A700 Trial Taker')->firstOrFail();

        $this->assertFalse(StudentProfile::where('user_id', $student->id)->firstOrFail()->is_regular);
    }

    #[Test]
    public function choosing_regular_stores_a_regular(): void
    {
        $this->enrol(['is_regular' => 1])->assertSessionHasNoErrors();

        $student = User::where('name', 'A700 Trial Taker')->firstOrFail();

        $this->assertTrue(StudentProfile::where('user_id', $student->id)->firstOrFail()->is_regular);
    }

    #[Test]
    public function a_trial_lands_on_the_trial_students_list(): void
    {
        $this->enrol(['is_regular' => 0])->assertSessionHasNoErrors();

        $profile = StudentProfile::firstOrFail();
        $profile->update(['enrollment_status' => EnrollmentStatus::Approved]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.trials.index'))
            ->assertOk()
            ->assertSee('A700 Trial Taker');
    }

    // ---------------------------------------------------------------- the tag

    #[Test]
    public function the_dashboard_tags_a_trial_students_name(): void
    {
        $this->rosteredStudent('A701 Trial On Roster', isRegular: false);

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('A701 Trial On Roster')
            ->assertSee('Trial');
    }

    #[Test]
    public function the_classes_page_tags_a_trial_students_name(): void
    {
        $this->rosteredStudent('A701 Trial On Roster', isRegular: false);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index'))
            ->assertOk()
            ->assertSee('A701 Trial On Roster')
            ->assertSee('Trial');
    }

    #[Test]
    public function a_regular_student_carries_no_tag(): void
    {
        $this->rosteredStudent('A702 Regular On Roster', isRegular: true);

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index'))
            ->assertOk()
            ->assertSee('A702 Regular On Roster')
            ->assertDontSee('>Trial<', false);
    }
}
