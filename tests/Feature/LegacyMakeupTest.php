<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Makeup classes stored the way the legacy app stored them — which is to say,
 * the way almost all of them are stored.
 *
 * PostponeTest covers the shape this codebase writes: `rescheduled_date` on the
 * postponed row. But the import copied 2,400+ legacy makeups across untouched,
 * and those use the opposite shape — a second, unmarked row ON the makeup date,
 * with the agreed hour in `makeup_time` and the original date in
 * `postpone_reason`. Nothing understood that shape, so a real makeup showed up
 * with no time and no explanation, and the class list did not show it at all.
 *
 * The student here is timetabled Tuesday only, so the Thursday makeup can be
 * reached by nothing but the makeup itself.
 */
class LegacyMakeupTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    /** The class that was postponed. A Tuesday, the student's only slot. */
    private string $originalDate = '2026-08-04';

    /** Where it comes back — a Thursday, off the student's timetable. */
    private string $makeupDate = '2026-08-06';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow($this->makeupDate.' 09:00:00');
        Carbon::setTestNow($this->makeupDate.' 09:00:00');

        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create(['name' => 'A443 Makeup Kid']);

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'sessions_remaining' => 3,
        ]);

        StudentSchedule::create([
            'student_id' => $this->student->id,
            'day_of_week' => 2,
            'start_time' => '18:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The imported pair: the postponed original, and the makeup row itself. */
    private function importLegacyMakeup(): ClassSession
    {
        ClassSession::factory()->postponed()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->originalDate,
            'postpone_reason' => 'NOT FEELING WELL',
        ]);

        return ClassSession::factory()
            ->makeupFor($this->originalDate, '12:00:00')
            ->create([
                'instructor_id' => $this->instructor->id,
                'student_id' => $this->student->id,
                'scheduled_date' => $this->makeupDate,
            ]);
    }

    // ------------------------------------------------------------ it shows up

    #[Test]
    public function the_dashboard_shows_the_makeup_with_its_time_and_the_class_it_replaces(): void
    {
        $this->importLegacyMakeup();

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('A443 Makeup Kid')
            // The agreed hour lives in makeup_time; reading only scheduled_time
            // left the column showing an em dash.
            ->assertSee('12:00 PM')
            ->assertSee('Makeup for Aug 4')
            ->assertDontSee('Off timetable');
    }

    #[Test]
    public function the_class_list_shows_the_makeup_too(): void
    {
        // The list was built from the weekly timetable alone, so a makeup on a
        // day the student has no slot on was missing entirely — the instructor's
        // report was that postponed classes "land" on the dashboard only.
        $this->importLegacyMakeup();

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index', ['date' => $this->makeupDate]))
            ->assertOk()
            ->assertSee('A443 Makeup Kid')
            ->assertSee('12:00 PM')
            ->assertSee('Makeup for Aug 4');
    }

    #[Test]
    public function the_makeup_can_be_marked_on_the_day_it_lands(): void
    {
        $this->importLegacyMakeup();

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('Present');

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->makeupDate,
                'status' => 'present',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            SessionStatus::Present,
            ClassSession::where('scheduled_date', $this->makeupDate)->firstOrFail()->status,
        );
    }

    // -------------------------------------------------- and it stays a makeup

    #[Test]
    public function marking_the_makeup_present_does_not_erase_what_it_was_a_makeup_for(): void
    {
        // The "Present" button posts no reason, and the reason was written
        // straight through — so teaching the class silently deleted the only
        // record that it replaced an earlier one.
        $makeup = $this->importLegacyMakeup();

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->makeupDate,
                'status' => 'present',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Rescheduled from '.$this->originalDate,
            $makeup->fresh()->postpone_reason,
        );

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('Makeup for Aug 4');
    }

    #[Test]
    public function an_ordinary_postpone_reason_is_still_cleared_when_the_class_is_re_marked(): void
    {
        // Only the makeup marker is protected. A note about why a class moved is
        // no longer true once the class is marked present, and keeping it would
        // leave stale text on the row.
        $session = ClassSession::factory()->postponed()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->makeupDate,
            'postpone_reason' => 'Typhoon',
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->makeupDate,
                'status' => 'present',
            ]);

        $this->assertNull($session->fresh()->postpone_reason);
    }

    #[Test]
    public function free_text_that_merely_starts_like_the_marker_is_not_read_as_a_date(): void
    {
        $session = ClassSession::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->makeupDate,
            'postpone_reason' => 'Rescheduled from her old teacher',
        ]);

        $this->assertNull($session->makeupOrigin());
    }

    // ---------------------------------------- the shape this codebase writes

    #[Test]
    public function a_postponed_class_is_markable_on_the_day_it_returns(): void
    {
        // In the current shape the makeup date is a pointer on the postponed row,
        // and that row was handed to the makeup day's roster as if it were that
        // day's class. Being already "Postponed", it offered no way to mark the
        // makeup — the class could never be recorded, or paid.
        ClassSession::factory()->postponed()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $this->originalDate,
            'rescheduled_date' => $this->makeupDate,
            'rescheduled_time' => '15:00:00',
            'postponed_by' => Party::Student,
        ]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('A443 Makeup Kid')
            ->assertSee('Makeup for Aug 4')
            ->assertSee('3:00 PM')
            ->assertSee('Present');

        $this->actingAs($this->instructor)
            ->post(route('instructor.classes.attendance'), [
                'student_id' => $this->student->id,
                'date' => $this->makeupDate,
                'status' => 'present',
            ])
            ->assertSessionHasNoErrors();

        // A new row for the day it was actually taught; the postponement it came
        // from is left alone.
        $this->assertSame(
            SessionStatus::Present,
            ClassSession::where('scheduled_date', $this->makeupDate)->firstOrFail()->status,
        );
        $this->assertSame(
            SessionStatus::Postponed,
            ClassSession::where('scheduled_date', $this->originalDate)->firstOrFail()->status,
        );
    }
}
