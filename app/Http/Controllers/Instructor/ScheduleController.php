<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InstructorAvailability;
use App\Models\StudentSchedule;
use App\Support\WeeklyScheduleGrid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The instructor's own weekly availability.
 *
 * Replaces app/views/instructor/teacher_schedule.php, and closes the gap that
 * made the public schedule table nearly useless: only 1 of 33 imported
 * instructors had any availability on record, because the legacy page was the
 * only way to add it.
 */
class ScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $instructor = $request->user();

        $instructor->load('availabilities');

        return view('instructor.schedule.index', [
            'instructor' => $instructor,
            // Grouped by weekday for the editor; ordered by time within a day.
            'byDay' => $instructor->availabilities
                ->sortBy('start_time')
                ->groupBy('day_of_week'),
            'days' => StudentSchedule::DAYS,
            'grid' => WeeklyScheduleGrid::forInstructor($instructor),
            // What the student timetable already commits them to, so they do not
            // publish availability that clashes with a class they teach.
            'bookedHours' => $this->bookedHours($instructor->id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $instructor = $request->user();

        $data = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'is_available' => ['nullable', 'boolean'],
        ], [], [
            'day_of_week' => 'day',
        ]);

        $this->assertNoOverlap(
            $instructor->id,
            (int) $data['day_of_week'],
            $data['start_time'],
            $data['end_time'],
        );

        $availability = InstructorAvailability::create([
            'instructor_id' => $instructor->id,
            'day_of_week' => (int) $data['day_of_week'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'is_available' => $request->boolean('is_available', true),
        ]);

        AuditLog::record(
            action: 'schedule.slot_added',
            subject: $availability,
            targetName: $instructor->name,
            details: [
                'day' => StudentSchedule::DAYS[$availability->day_of_week] ?? null,
                'from' => $data['start_time'],
                'to' => $data['end_time'],
            ],
            userId: $instructor->id,
        );

        return back()->with('success', sprintf(
            '%s %s–%s added.',
            StudentSchedule::DAYS[$availability->day_of_week] ?? '',
            $availability->start_time,
            $availability->end_time,
        ));
    }

    public function update(Request $request, InstructorAvailability $availability): RedirectResponse
    {
        abort_unless($availability->instructor_id === $request->user()->id, 403);

        $data = $request->validate([
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'is_available' => ['nullable', 'boolean'],
        ]);

        $this->assertNoOverlap(
            $availability->instructor_id,
            $availability->day_of_week,
            $data['start_time'],
            $data['end_time'],
            exceptId: $availability->id,
        );

        $availability->update([
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'is_available' => $request->boolean('is_available', true),
        ]);

        return back()->with('success', 'Time slot updated.');
    }

    public function destroy(Request $request, InstructorAvailability $availability): RedirectResponse
    {
        abort_unless($availability->instructor_id === $request->user()->id, 403);

        $day = StudentSchedule::DAYS[$availability->day_of_week] ?? '';
        $availability->delete();

        AuditLog::record(
            action: 'schedule.slot_removed',
            targetName: $request->user()->name,
            details: ['day' => $day],
            userId: $request->user()->id,
        );

        return back()->with('success', "{$day} slot removed.");
    }

    /**
     * Copy one day's slots onto other days.
     *
     * The legacy page had no such thing, so an instructor with the same hours
     * Monday to Friday had to fill the form five times.
     */
    public function copyDay(Request $request): RedirectResponse
    {
        $instructor = $request->user();

        $data = $request->validate([
            'from_day' => ['required', 'integer', 'between:1,7'],
            'to_days' => ['required', 'array', 'min:1'],
            'to_days.*' => ['integer', 'between:1,7'],
        ]);

        $source = InstructorAvailability::query()
            ->where('instructor_id', $instructor->id)
            ->where('day_of_week', (int) $data['from_day'])
            ->get();

        if ($source->isEmpty()) {
            throw ValidationException::withMessages([
                'from_day' => 'That day has no time slots to copy.',
            ]);
        }

        $copied = 0;

        foreach ($data['to_days'] as $day) {
            $day = (int) $day;

            if ($day === (int) $data['from_day']) {
                continue;
            }

            foreach ($source as $slot) {
                // updateOrCreate against the unique slot key, so copying twice is
                // harmless rather than a constraint violation.
                InstructorAvailability::updateOrCreate(
                    [
                        'instructor_id' => $instructor->id,
                        'day_of_week' => $day,
                        'start_time' => $slot->start_time,
                        'end_time' => $slot->end_time,
                    ],
                    ['is_available' => $slot->is_available],
                );
                $copied++;
            }
        }

        return back()->with('success', "{$copied} ".Str::plural('slot', $copied).' copied.');
    }

    // ---------------------------------------------------------------- internals

    /**
     * Refuse a slot that overlaps one already on that day.
     *
     * Touching endpoints (10:00-11:00 then 11:00-12:00) do not overlap.
     */
    private function assertNoOverlap(
        int $instructorId,
        int $day,
        string $startTime,
        string $endTime,
        ?int $exceptId = null,
    ): void {
        $clash = InstructorAvailability::query()
            ->where('instructor_id', $instructorId)
            ->where('day_of_week', $day)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get()
            ->first(fn (InstructorAvailability $slot) => $slot->overlaps(
                $startTime.':00',
                $endTime.':00',
            ));

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'start_time' => sprintf(
                    'That overlaps an existing slot (%s).',
                    $clash->formattedRange(),
                ),
            ]);
        }
    }

    /**
     * Hours the instructor's students already hold classes in, keyed
     * [isoDay][hour]. Shown alongside the editor so availability is not
     * published over a class already on the books.
     *
     * @return array<int, array<int, string>>
     */
    private function bookedHours(int $instructorId): array
    {
        $rows = StudentSchedule::query()
            ->join('student_profiles as sp', 'sp.user_id', '=', 'student_schedules.student_id')
            ->join('users as u', 'u.id', '=', 'student_schedules.student_id')
            ->where('sp.instructor_id', $instructorId)
            ->where('sp.enrollment_status', EnrollmentStatus::Approved)
            ->where('u.is_active', true)
            ->whereNull('u.deleted_at')
            ->select(['student_schedules.day_of_week', 'student_schedules.start_time', 'u.name'])
            ->get();

        $booked = [];

        foreach ($rows as $row) {
            $hour = (int) substr((string) $row->start_time, 0, 2);
            $booked[$row->day_of_week][$hour] = $row->name;
        }

        return $booked;
    }
}
