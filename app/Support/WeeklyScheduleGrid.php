<?php

namespace App\Support;

use App\Enums\EnrollmentStatus;
use App\Models\InstructorAvailability;
use App\Models\StudentSchedule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * An instructor's week as a day × hour grid, for the public schedule table.
 *
 * Ported from the grid public/instructor_profile.php built inline, including its
 * two-source behaviour:
 *
 *   1. `instructor_availabilities` — hours the instructor declared open.
 *   2. If they declared none, fall back to the hours their students already
 *      hold classes in, derived from `student_schedules`.
 *
 * The fallback matters: exactly 1 of 33 imported instructors has declared any
 * availability (the legacy `teacher_schedules` table held 2 rows in total), so
 * without it the table is empty for everyone else.
 *
 * ONE DELIBERATE DEVIATION. The legacy fallback labelled booked hours
 * "Available" and printed the student's name into the cell — on a page reachable
 * without logging in. Here a booked hour reads "Not Available" (truthful for a
 * visitor looking for a free slot) and no student name is ever emitted.
 */
final class WeeklyScheduleGrid
{
    /** 6am through 11pm, matching the legacy `range(6, 23)`. */
    public const FIRST_HOUR = 6;

    public const LAST_HOUR = 23;

    public const AVAILABLE = 'available';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<int, array<int, array{status: string, start_time: string, end_time: string}>>  $slots
     *                                                                                                      Keyed [isoDay][hour].
     */
    private function __construct(
        private readonly array $slots,
        public readonly bool $isDeclared,
    ) {}

    public static function forInstructor(User $instructor): self
    {
        $declared = $instructor->relationLoaded('availabilities')
            ? $instructor->availabilities
            : $instructor->availabilities()->get();

        if ($declared->isNotEmpty()) {
            return new self(self::fromAvailabilities($declared), isDeclared: true);
        }

        return new self(self::fromStudentClasses($instructor), isDeclared: false);
    }

    /**
     * Hours the instructor declared, expanded from ranges into whole hours.
     *
     * @param  Collection<int, InstructorAvailability>  $availabilities
     * @return array<int, array<int, array{status: string, start_time: string, end_time: string}>>
     */
    private static function fromAvailabilities(Collection $availabilities): array
    {
        $slots = [];

        foreach ($availabilities as $availability) {
            $startHour = (int) substr((string) $availability->start_time, 0, 2);
            $endHour = (int) substr((string) $availability->end_time, 0, 2);

            // A range ending on the hour does not occupy that hour, so `<`.
            // A range inside one hour (10:15-10:45) still fills it, hence max().
            for ($hour = $startHour; $hour < max($endHour, $startHour + 1); $hour++) {
                $slots[$availability->day_of_week][$hour] = [
                    'status' => $availability->is_available ? self::AVAILABLE : self::UNAVAILABLE,
                    'start_time' => (string) $availability->start_time,
                    'end_time' => (string) $availability->end_time,
                ];
            }
        }

        return $slots;
    }

    /**
     * Hours already taken by this instructor's students.
     *
     * @return array<int, array<int, array{status: string, start_time: string, end_time: string}>>
     */
    private static function fromStudentClasses(User $instructor): array
    {
        $schedules = StudentSchedule::query()
            ->join('student_profiles as sp', 'sp.user_id', '=', 'student_schedules.student_id')
            ->join('users as u', 'u.id', '=', 'student_schedules.student_id')
            ->where('sp.instructor_id', $instructor->id)
            ->where('sp.enrollment_status', EnrollmentStatus::Approved)
            ->where('u.is_active', true)
            ->whereNull('u.deleted_at')
            ->select([
                'student_schedules.day_of_week',
                'student_schedules.start_time',
                'sp.learning_time',
            ])
            ->get();

        $slots = [];

        foreach ($schedules as $schedule) {
            $startHour = (int) substr((string) $schedule->start_time, 0, 2);

            // Legacy: start + ceil(minutes / 60), so a 25-minute class occupies
            // one hour and a 90-minute class occupies two.
            $span = max(1, (int) ceil(((int) $schedule->learning_time) / 60));

            $endTime = date(
                'H:i:s',
                strtotime((string) $schedule->start_time) + ((int) $schedule->learning_time * 60)
            );

            for ($hour = $startHour; $hour < $startHour + $span; $hour++) {
                $slots[$schedule->day_of_week][$hour] = [
                    'status' => self::UNAVAILABLE,
                    'start_time' => (string) $schedule->start_time,
                    'end_time' => $endTime,
                ];
            }
        }

        return $slots;
    }

    /**
     * @return array{status: string, start_time: string, end_time: string}|null
     */
    public function slot(int $isoDay, int $hour): ?array
    {
        return $this->slots[$isoDay][$hour] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->slots === [];
    }

    /** How many hours across the week are open. */
    public function availableHours(): int
    {
        $count = 0;

        foreach ($this->slots as $hours) {
            foreach ($hours as $slot) {
                if ($slot['status'] === self::AVAILABLE) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Only the hours worth printing: the rows between the first and last slot,
     * so an instructor teaching evenings does not get 12 empty morning rows.
     * Falls back to the full 6am-11pm span when the week is empty.
     *
     * @return array<int, int>
     */
    public function hours(): array
    {
        $used = [];

        foreach ($this->slots as $hours) {
            $used = array_merge($used, array_keys($hours));
        }

        if ($used === []) {
            return range(self::FIRST_HOUR, self::LAST_HOUR);
        }

        return range(
            max(self::FIRST_HOUR, min($used)),
            min(self::LAST_HOUR, max($used)),
        );
    }

    /**
     * ISO day number => full day name, in display order.
     *
     * @return array<int, string>
     */
    public static function days(): array
    {
        return StudentSchedule::DAYS;
    }
}
