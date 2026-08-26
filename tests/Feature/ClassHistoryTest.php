<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The instructor's class history — the record of who was present, absent or
 * postponed, per student.
 */
class ClassHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->instructor()->create();
        $this->ana = User::factory()->student()->create(['name' => 'Ana Reyes']);
        $this->ben = User::factory()->student()->create(['name' => 'Ben Cruz']);

        $for = fn (User $student) => ClassSession::factory()->for($this->instructor, 'instructor')
            ->for($student, 'student');

        $for($this->ana)->present()->on('2026-08-03')->create();
        $for($this->ana)->present()->on('2026-08-05')->create();
        $for($this->ana)->studentAbsent()->on('2026-08-07')->create();
        $for($this->ana)->teacherAbsent()->on('2026-08-10')->create();
        $for($this->ana)->postponed()->on('2026-08-12')->create();

        $for($this->ben)->present()->on('2026-08-04')->create();
        $for($this->ben)->postponed()->on('2026-08-06')->create();

        // Unmarked: nothing happened yet, so it is not history.
        $for($this->ben)->on('2026-08-20')->create();
    }

    #[Test]
    public function the_totals_count_present_absent_and_postponed(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.history.index'))
            ->assertOk()
            ->assertViewHas('totals', fn (array $totals) => $totals['total'] === 7
                && $totals['present'] === 3
                && $totals['absent'] === 2
                && $totals['postponed'] === 2
                && $totals['students'] === 2);
    }

    #[Test]
    public function the_breakdown_splits_each_students_attendance(): void
    {
        $response = $this->actingAs($this->instructor)->get(route('instructor.history.index'));

        $ana = $response->viewData('byStudent')->firstWhere('student.id', $this->ana->id);

        $this->assertSame(5, $ana['total']);
        $this->assertSame(2, $ana['present']);
        $this->assertSame(1, $ana['student_absent']);
        $this->assertSame(1, $ana['teacher_absent']);
        $this->assertSame(1, $ana['postponed']);
        $this->assertSame('2026-08-12', $ana['last_class']);
    }

    #[Test]
    public function filtering_by_student_narrows_the_totals_and_the_list(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.history.index', ['student_id' => $this->ben->id]))
            ->assertOk()
            ->assertViewHas('selectedStudentId', $this->ben->id)
            ->assertViewHas('totals', fn (array $totals) => $totals['total'] === 2
                && $totals['present'] === 1
                && $totals['postponed'] === 1)
            ->assertViewHas('sessions', fn ($sessions) => $sessions->total() === 2
                && $sessions->every(fn ($s) => $s->student_id === $this->ben->id));
    }

    #[Test]
    public function the_date_range_and_the_status_filter_still_compose(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.history.index', [
                'student_id' => $this->ana->id,
                'from' => '2026-08-05',
                'to' => '2026-08-10',
                'status' => 'present',
            ]))
            ->assertOk()
            ->assertViewHas('sessions', fn ($sessions) => $sessions->total() === 1)
            // The breakdown ignores the status filter — it is what says which
            // status is worth filtering by — but honours the date range.
            ->assertViewHas('byStudent', fn ($rows) => $rows->count() === 1
                && $rows->first()['total'] === 3);
    }

    #[Test]
    public function another_instructors_student_cannot_be_filtered_to(): void
    {
        $other = User::factory()->instructor()->create();
        $theirs = User::factory()->student()->create(['name' => 'Not Mine']);

        ClassSession::factory()->for($other, 'instructor')->for($theirs, 'student')
            ->present()->on('2026-08-03')->create();

        // Falls back to the full history rather than 404ing on a stale bookmark,
        // and never leaks the other instructor's classes.
        $this->actingAs($this->instructor)
            ->get(route('instructor.history.index', ['student_id' => $theirs->id]))
            ->assertOk()
            ->assertViewHas('selectedStudentId', null)
            ->assertViewHas('totals', fn (array $totals) => $totals['total'] === 7)
            ->assertDontSee('Not Mine');
    }

    #[Test]
    public function an_archived_student_stays_selectable(): void
    {
        $this->ana->delete();

        $this->actingAs($this->instructor)
            ->get(route('instructor.history.index', ['student_id' => $this->ana->id]))
            ->assertOk()
            ->assertViewHas('selectedStudentId', $this->ana->id)
            ->assertSee('Ana Reyes');
    }
}
