<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Booking;
use App\Models\InstructorAvailability;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The instructor pages added to match the legacy sidebar, and their write
 * endpoints.
 *
 * The GET smoke coverage matters as much as the writes here: a view that
 * compiles can still die at runtime on an undefined helper or a missing
 * variable, and only rendering it catches that.
 */
class InstructorPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->instructor()->create();

        $student = User::factory()->student()->create();
        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
        ]);
        StudentSchedule::create([
            'student_id' => $student->id,
            'day_of_week' => now()->dayOfWeekIso,
            'start_time' => '18:30:00',
        ]);
    }

    /**
     * Every page reachable from the instructor sidebar.
     *
     * @return array<string, array{string}>
     */
    public static function instructorPages(): array
    {
        return [
            'dashboard' => ['instructor.dashboard'],
            'my classes' => ['instructor.classes.index'],
            'regular classes' => ['instructor.regular.index'],
            'data classes' => ['instructor.demo.index'],
            'class history' => ['instructor.history.index'],
            'my students' => ['instructor.students.index'],
            'enroll student' => ['instructor.students.create'],
            'trial students' => ['instructor.trials.index'],
            'reports' => ['instructor.reports.index'],
            'teacher schedule' => ['instructor.schedule.index'],
            'bookings' => ['instructor.bookings.index'],
            'earnings' => ['instructor.earnings.index'],
            'profile' => ['instructor.profile.edit'],
        ];
    }

    #[Test]
    #[DataProvider('instructorPages')]
    public function every_sidebar_page_renders(string $route): void
    {
        $this->actingAs($this->instructor)->get(route($route))->assertOk();
    }

    #[Test]
    #[DataProvider('instructorPages')]
    public function every_sidebar_page_is_closed_to_students(string $route): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)->get(route($route))->assertForbidden();
    }

    // ------------------------------------------------------- teacher schedule

    #[Test]
    public function an_instructor_can_publish_a_time_slot(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.schedule.store'), [
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'is_available' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('instructor_availabilities', [
            'instructor_id' => $this->instructor->id,
            'day_of_week' => 1,
            'is_available' => 1,
        ]);
    }

    #[Test]
    public function an_overlapping_slot_is_refused(): void
    {
        InstructorAvailability::create([
            'instructor_id' => $this->instructor->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'is_available' => true,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.schedule.store'), [
                'day_of_week' => 1,
                'start_time' => '11:00',
                'end_time' => '13:00',
            ])
            ->assertSessionHasErrors('start_time');

        $this->assertSame(1, InstructorAvailability::count());
    }

    #[Test]
    public function touching_slots_do_not_count_as_overlapping(): void
    {
        // 09:00-11:00 then 11:00-12:00 is a legitimate pair.
        InstructorAvailability::create([
            'instructor_id' => $this->instructor->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'is_available' => true,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.schedule.store'), [
                'day_of_week' => 1,
                'start_time' => '11:00',
                'end_time' => '12:00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, InstructorAvailability::count());
    }

    #[Test]
    public function an_end_time_before_the_start_is_refused(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.schedule.store'), [
                'day_of_week' => 1,
                'start_time' => '12:00',
                'end_time' => '09:00',
            ])
            ->assertSessionHasErrors('end_time');
    }

    #[Test]
    public function a_days_hours_can_be_copied_onto_other_days(): void
    {
        InstructorAvailability::create([
            'instructor_id' => $this->instructor->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'is_available' => true,
        ]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.schedule.copy'), [
                'from_day' => 1,
                'to_days' => [2, 3, 4],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(4, InstructorAvailability::count());
    }

    #[Test]
    public function copying_the_same_day_twice_is_harmless(): void
    {
        InstructorAvailability::create([
            'instructor_id' => $this->instructor->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'is_available' => true,
        ]);

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->instructor)
                ->post(route('instructor.schedule.copy'), ['from_day' => 1, 'to_days' => [2]])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, InstructorAvailability::count(), 'the unique slot key must not duplicate');
    }

    #[Test]
    public function an_instructor_cannot_touch_another_instructors_slot(): void
    {
        $other = User::factory()->instructor()->create();

        $slot = InstructorAvailability::create([
            'instructor_id' => $other->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'is_available' => true,
        ]);

        $this->actingAs($this->instructor)
            ->delete(route('instructor.schedule.destroy', $slot))
            ->assertForbidden();

        $this->assertSame(1, InstructorAvailability::count());
    }

    // --------------------------------------------------------------- profile

    #[Test]
    public function an_instructor_can_update_their_profile(): void
    {
        $this->actingAs($this->instructor)
            ->patch(route('instructor.profile.update'), [
                'name' => 'Teacher Renamed',
                'email' => 'renamed@example.com',
                'phone' => '010-1234-5678',
                'bio' => 'Ten years of teaching.',
                'bank_name' => 'KB',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Teacher Renamed', $this->instructor->refresh()->name);
        $this->assertDatabaseHas('instructor_profiles', [
            'user_id' => $this->instructor->id,
            'bank_name' => 'KB',
        ]);
    }

    #[Test]
    public function a_blank_email_is_stored_as_null_not_empty_string(): void
    {
        // Empty strings would collide under the unique index; most academy
        // accounts have no email at all.
        $this->actingAs($this->instructor)
            ->patch(route('instructor.profile.update'), ['name' => 'No Email', 'email' => '']);

        $this->assertNull($this->instructor->refresh()->email);
    }

    #[Test]
    public function changing_the_password_requires_the_current_one(): void
    {
        // The legacy endpoint never checked, so a borrowed session could lock the
        // real owner out.
        $this->actingAs($this->instructor)
            ->put(route('instructor.profile.password'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $this->instructor->refresh()->password));
    }

    #[Test]
    public function the_password_changes_when_the_current_one_is_right(): void
    {
        $this->actingAs($this->instructor)
            ->put(route('instructor.profile.password'), [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password-123', $this->instructor->refresh()->password));
    }

    // -------------------------------------------------------------- bookings

    #[Test]
    public function an_instructor_can_confirm_a_booking(): void
    {
        $booking = Booking::create([
            'instructor_id' => $this->instructor->id,
            'student_name' => 'Prospect Kim',
            'kakaotalk_id' => 'kim123',
            'session_date' => now()->addWeek()->toDateString(),
            'session_time' => '10:00:00',
            'sessions' => 1,
            'status' => BookingStatus::Pending,
        ]);

        $this->actingAs($this->instructor)
            ->patch(route('instructor.bookings.status', $booking), ['status' => 'confirmed'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    #[Test]
    public function an_instructor_cannot_touch_another_instructors_booking(): void
    {
        $other = User::factory()->instructor()->create();

        $booking = Booking::create([
            'instructor_id' => $other->id,
            'student_name' => 'Not Yours',
            'kakaotalk_id' => 'x',
            'session_date' => now()->addWeek()->toDateString(),
            'session_time' => '10:00:00',
            'sessions' => 1,
            'status' => BookingStatus::Pending,
        ]);

        $this->actingAs($this->instructor)
            ->patch(route('instructor.bookings.status', $booking), ['status' => 'confirmed'])
            ->assertForbidden();

        $this->assertSame(BookingStatus::Pending, $booking->refresh()->status);
    }

    // -------------------------------------------------------- enrol a student

    #[Test]
    public function an_instructor_can_enrol_a_student(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.students.store'), [
                'name' => 'A999 New Student',
                'teaching_method' => 'video_kids',
                'learning_time' => 25,
                'sessions_purchased' => 30,
                'sessions_deducted' => 2,
                'is_regular' => 1,
                'schedule' => [1 => '18:30', 3 => '18:30'],
            ])
            ->assertRedirect(route('instructor.students.index'))
            ->assertSessionHasNoErrors();

        $student = User::where('name', 'A999 New Student')->firstOrFail();
        $profile = StudentProfile::where('user_id', $student->id)->firstOrFail();

        // Purchased minus written-off is what is left to use.
        $this->assertSame(28, $profile->sessions_remaining);
        $this->assertSame(2, $profile->sessions_deducted);
        $this->assertSame($this->instructor->id, $profile->instructor_id);

        // Enrolled by an instructor, so it awaits admin approval.
        $this->assertSame(EnrollmentStatus::Pending, $profile->enrollment_status);

        // The timetable is rows, not a comma-joined string.
        $this->assertSame(2, StudentSchedule::where('student_id', $student->id)->count());
    }

    #[Test]
    public function an_enrolled_student_is_not_teachable_until_approved(): void
    {
        $response = $this->actingAs($this->instructor)
            ->post(route('instructor.students.store'), [
                'name' => 'A998 Pending One',
                'teaching_method' => 'audio',
                'learning_time' => 25,
                'sessions_purchased' => 10,
                'schedule' => [now()->dayOfWeekIso => '09:00'],
            ]);

        // Follow the redirect so the success flash — which names the student and
        // its one-time password — is consumed here rather than bleeding onto the
        // next page and making the assertion below match on the banner.
        $response->assertRedirect();
        $this->actingAs($this->instructor)->get($response->headers->get('Location'));

        $this->actingAs($this->instructor)
            ->get(route('instructor.classes.index'))
            ->assertOk()
            ->assertDontSee('A998 Pending One');
    }

    /**
     * The dropdown and the validation rule are both built from
     * config('academy.learning_times'), so a duration offered on the form is
     * necessarily accepted on save.
     */
    #[Test]
    public function the_enrolment_form_offers_every_configured_duration(): void
    {
        $html = $this->actingAs($this->instructor)
            ->get(route('instructor.students.create'))
            ->assertOk()
            ->getContent();

        foreach (config('academy.learning_times') as $minutes) {
            $this->assertStringContainsString('value="'.$minutes.'"', $html);
            $this->assertStringContainsString($minutes.' min', $html);
        }

        // The short durations the shortest lessons are booked at.
        $this->assertContains(10, config('academy.learning_times'));
        $this->assertContains(15, config('academy.learning_times'));
    }

    #[Test]
    public function a_short_lesson_can_be_enrolled_and_prices_pro_rata(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.students.store'), [
                'name' => 'A997 Short Lesson',
                'teaching_method' => 'audio',
                'learning_time' => 15,
                'sessions_purchased' => 10,
                'schedule' => [1 => '18:30'],
            ])
            ->assertSessionHasNoErrors();

        $student = User::where('name', 'A997 Short Lesson')->firstOrFail();
        $profile = StudentProfile::where('user_id', $student->id)->firstOrFail();

        $this->assertSame(15, $profile->learning_time);

        // A quarter of an hour bills a quarter of the method's hourly rate.
        $rate = config('academy.rates.audio');
        $this->assertEqualsWithDelta($rate / 4, $profile->sessionValue(), 0.01);
    }

    #[Test]
    public function enrolling_requires_a_plan(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('instructor.students.store'), ['name' => 'Nameless Plan'])
            ->assertSessionHasErrors(['teaching_method', 'learning_time', 'sessions_purchased']);

        $this->assertDatabaseMissing('users', ['name' => 'Nameless Plan']);
    }
}
