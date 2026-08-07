<?php

namespace Tests\Feature;

use App\Models\InstructorAvailability;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use App\Support\WeeklyScheduleGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public landing page and its weekly schedule table.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An instructor with one student, so they qualify as bookable.
     */
    private function instructorWithStudent(array $slot = ['day' => 3, 'time' => '18:30:00'], int $minutes = 25): User
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $instructor->id,
            'learning_time' => $minutes,
        ]);

        StudentSchedule::create([
            'student_id' => $student->id,
            'day_of_week' => $slot['day'],
            'start_time' => $slot['time'],
        ]);

        return $instructor;
    }

    // ------------------------------------------------------------------- page

    #[Test]
    public function it_renders_the_legacy_hero(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(config('app.name'))
            ->assertSee('저스트텐미닛 전화, 화상영어')
            ->assertSee('Get Started')
            ->assertSee('Welcome Back to '.config('app.name'));
    }

    #[Test]
    public function the_typewriter_copy_is_present_without_javascript(): void
    {
        // The paragraph is in the markup, not injected, so it survives JS being
        // off and is visible to crawlers.
        $this->get('/')->assertSee('Streamline your workflow and boost productivity.');
    }

    #[Test]
    public function a_signed_in_instructor_is_sent_to_their_dashboard(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->get('/')
            ->assertRedirect(route('instructor.dashboard'));
    }

    #[Test]
    public function the_login_form_posts_to_the_real_login_route(): void
    {
        $this->get('/')
            ->assertSee(route('login.store'), escape: false)
            ->assertSee('name="login"', escape: false)
            ->assertSee('_token', escape: false);
    }

    #[Test]
    public function a_guest_can_sign_in_from_the_landing_page(): void
    {
        $instructor = User::factory()->instructor()->create([
            'email' => 'teacher@example.com',
        ]);

        $this->post(route('login.store'), [
            'login' => 'teacher@example.com',
            'password' => 'password',
        ])->assertRedirect(route('instructor.dashboard'));

        $this->assertAuthenticatedAs($instructor);
    }

    // -------------------------------------------------------------- schedules

    #[Test]
    public function it_lists_bookable_instructors(): void
    {
        $instructor = $this->instructorWithStudent();

        $this->get('/')
            ->assertOk()
            ->assertSee('Teacher Schedules')
            ->assertSee('Weekly Schedule')
            ->assertSee($instructor->name);
    }

    #[Test]
    public function an_instructor_with_neither_students_nor_availability_is_not_listed(): void
    {
        $lonely = User::factory()->instructor()->create(['name' => 'Teacher NoStudents']);

        $this->get('/')->assertDontSee($lonely->name);
    }

    #[Test]
    public function an_instructor_with_published_availability_is_listed_even_with_no_students(): void
    {
        // The only instructor in the real data who published hours has no
        // students, so filtering on students alone hid the single teacher whose
        // schedule was genuine.
        $instructor = User::factory()->instructor()->create(['name' => 'Teacher Published']);

        InstructorAvailability::create([
            'instructor_id' => $instructor->id,
            'day_of_week' => 1,
            'start_time' => '06:00:00',
            'end_time' => '07:00:00',
            'is_available' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Teacher Published');
    }

    #[Test]
    public function instructors_with_published_hours_are_shown_first(): void
    {
        // So the grid a visitor lands on is one they can actually book against.
        $this->instructorWithStudent();

        $published = User::factory()->instructor()->create(['name' => 'ZZZ Last Alphabetically']);
        InstructorAvailability::create([
            'instructor_id' => $published->id,
            'day_of_week' => 1,
            'start_time' => '06:00:00',
            'end_time' => '07:00:00',
            'is_available' => true,
        ]);

        // Selected by default despite sorting last by name.
        $this->get('/')
            ->assertOk()
            ->assertSee($published->name.'\'s weekly availability', escape: false)
            ->assertSee('open hour');
    }

    #[Test]
    public function the_schedule_section_is_hidden_when_nobody_is_bookable(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('Teacher Schedules');
    }

    #[Test]
    public function a_specific_instructor_can_be_selected_by_query_string(): void
    {
        $first = $this->instructorWithStudent();
        $second = $this->instructorWithStudent(['day' => 5, 'time' => '09:00:00']);

        // Both appear in the picker; the selected one drives the table caption.
        $this->get('/?instructor='.$second->id)
            ->assertOk()
            ->assertSee($second->name.'\'s weekly availability', escape: false);
    }

    #[Test]
    public function an_unknown_instructor_id_falls_back_to_the_first(): void
    {
        $instructor = $this->instructorWithStudent();

        $this->get('/?instructor=999999')
            ->assertOk()
            ->assertSee($instructor->name);
    }

    #[Test]
    public function a_student_name_is_never_exposed_on_the_public_page(): void
    {
        // The legacy fallback printed the student's name into the cell on a page
        // that needed no login. That must not happen here.
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create(['name' => 'A999 Very Private']);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $instructor->id,
            'learning_time' => 25,
        ]);
        StudentSchedule::create([
            'student_id' => $student->id,
            'day_of_week' => 3,
            'start_time' => '18:30:00',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('A999 Very Private');
    }

    // ------------------------------------------------------------- grid logic

    #[Test]
    public function declared_availability_fills_whole_hours_as_available(): void
    {
        $instructor = $this->instructorWithStudent();

        InstructorAvailability::create([
            'instructor_id' => $instructor->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'is_available' => true,
        ]);

        $grid = WeeklyScheduleGrid::forInstructor($instructor->refresh());

        $this->assertTrue($grid->isDeclared);
        $this->assertSame(WeeklyScheduleGrid::AVAILABLE, $grid->slot(1, 9)['status']);
        $this->assertSame(WeeklyScheduleGrid::AVAILABLE, $grid->slot(1, 11)['status']);
        // An end time on the hour does not occupy that hour.
        $this->assertNull($grid->slot(1, 12));
        $this->assertSame(3, $grid->availableHours());
    }

    #[Test]
    public function an_unavailable_declaration_reads_as_not_available(): void
    {
        $instructor = $this->instructorWithStudent();

        InstructorAvailability::create([
            'instructor_id' => $instructor->id,
            'day_of_week' => 2,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'is_available' => false,
        ]);

        $grid = WeeklyScheduleGrid::forInstructor($instructor->refresh());

        $this->assertSame(WeeklyScheduleGrid::UNAVAILABLE, $grid->slot(2, 14)['status']);
        $this->assertSame(0, $grid->availableHours());
    }

    #[Test]
    public function it_falls_back_to_existing_class_hours_when_nothing_is_declared(): void
    {
        // Only 2 of 33 real instructors declared availability, so without this
        // fallback the table is empty for nearly everyone.
        $instructor = $this->instructorWithStudent(['day' => 3, 'time' => '18:30:00'], minutes: 25);

        $grid = WeeklyScheduleGrid::forInstructor($instructor);

        $this->assertFalse($grid->isDeclared);
        $this->assertFalse($grid->isEmpty());
        // A booked hour is Not Available to a visitor looking for a free slot.
        $this->assertSame(WeeklyScheduleGrid::UNAVAILABLE, $grid->slot(3, 18)['status']);
    }

    #[Test]
    public function a_long_class_spans_more_than_one_hour_in_the_fallback(): void
    {
        // Legacy: start + ceil(minutes / 60).
        $instructor = $this->instructorWithStudent(['day' => 4, 'time' => '10:00:00'], minutes: 90);

        $grid = WeeklyScheduleGrid::forInstructor($instructor);

        $this->assertNotNull($grid->slot(4, 10));
        $this->assertNotNull($grid->slot(4, 11));
        $this->assertNull($grid->slot(4, 12));
    }

    #[Test]
    public function the_table_only_prints_hours_that_are_in_use(): void
    {
        // An evening-only teacher should not get a dozen empty morning rows.
        $instructor = $this->instructorWithStudent(['day' => 3, 'time' => '19:00:00'], minutes: 25);

        $hours = WeeklyScheduleGrid::forInstructor($instructor)->hours();

        $this->assertSame([19], $hours);
    }

    #[Test]
    public function an_empty_week_still_prints_the_full_day(): void
    {
        $instructor = User::factory()->instructor()->create();

        $grid = WeeklyScheduleGrid::forInstructor($instructor);

        $this->assertTrue($grid->isEmpty());
        $this->assertSame(
            range(WeeklyScheduleGrid::FIRST_HOUR, WeeklyScheduleGrid::LAST_HOUR),
            $grid->hours(),
        );
    }

    #[Test]
    public function an_archived_students_hours_are_not_shown(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->inactive()->create();

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $instructor->id,
            'learning_time' => 25,
        ]);
        StudentSchedule::create([
            'student_id' => $student->id,
            'day_of_week' => 3,
            'start_time' => '18:30:00',
        ]);

        $this->assertTrue(WeeklyScheduleGrid::forInstructor($instructor)->isEmpty());
    }
}
