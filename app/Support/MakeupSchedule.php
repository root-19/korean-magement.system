<?php

namespace App\Support;

use App\Models\StudentSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Where a postponed class comes back.
 *
 * The legacy modal called this "Auto (After Last Scheduled Class)": a postponed
 * class is appended after the student's remaining prepaid classes, so it becomes
 * their new last class. Walking the student's weekly timetable forward from the
 * postponed date, that is the Nth upcoming slot where N is how many prepaid
 * sessions they have left.
 *
 * Worked example — Friday's class is postponed, the student has 2 sessions left
 * and a Monday-to-Friday timetable:
 *
 *   Fri (postponed) -> Mon is slot 1, Tue is slot 2 -> the makeup is Tuesday.
 *
 * The two remaining classes are taught Monday and Tuesday, and Tuesday is now
 * their last class. That is exactly what the legacy preview showed.
 *
 * Nothing here writes: the caller passes the chosen date to AttendanceService.
 */
final class MakeupSchedule implements Arrayable
{
    /**
     * How far ahead to look for slots. A student can have at most 7 slots a
     * week, so 12 weeks covers any realistic session balance and keeps a student
     * with a huge balance from walking years into the future.
     */
    private const HORIZON_WEEKS = 12;

    /**
     * @param  array<int, CarbonImmutable>  $upcoming  Slots between the postponed date and the makeup, in order
     * @param  array<int, int>  $isoDays  The student's timetable weekdays, ISO
     */
    private function __construct(
        public readonly CarbonImmutable $postponedDate,
        public readonly ?CarbonImmutable $autoDate,
        public readonly ?string $defaultTime,
        public readonly array $upcoming,
        public readonly array $isoDays,
        public readonly int $sessionsRemaining,
    ) {}

    /**
     * Work out the makeup slot for one student's postponed class.
     *
     * Reads $student->schedules, so eager-load it — this runs once per roster row.
     */
    public static function for(User $student, mixed $postponedDate, int $sessionsRemaining): self
    {
        $from = CarbonImmutable::parse($postponedDate)->startOfDay();

        $slotDays = $student->schedules
            ->pluck('day_of_week')
            ->map(fn ($day) => (int) $day)
            ->unique()
            ->sort()
            ->values();

        // At least one slot ahead. A student with nothing left still needs a day
        // to come back on, and the alternative is a postponement whose makeup
        // never appears anywhere — the failure the legacy modal warned about in
        // red capitals.
        $wanted = max(1, $sessionsRemaining);

        $slots = [];

        if ($slotDays->isNotEmpty()) {
            $horizon = $from->addWeeks(self::HORIZON_WEEKS);

            for ($day = $from->addDay(); $day->lte($horizon) && count($slots) < $wanted; $day = $day->addDay()) {
                if ($slotDays->contains($day->dayOfWeekIso)) {
                    $slots[] = $day;
                }
            }
        }

        $autoDate = $slots === [] ? null : $slots[count($slots) - 1];

        return new self(
            postponedDate: $from,
            autoDate: $autoDate,
            defaultTime: $autoDate ? self::usualTimeOn($student, $autoDate) : null,
            upcoming: $slots,
            isoDays: $slotDays->all(),
            sessionsRemaining: $sessionsRemaining,
        );
    }

    /**
     * "Monday, Tuesday, Wednesday" — the timetable, as the legacy modal showed it.
     */
    public function scheduleLabel(): string
    {
        $names = array_map(fn (int $day) => StudentSchedule::DAYS[$day] ?? '—', $this->isoDays);

        return $names === [] ? 'No weekly timetable' : implode(', ', $names);
    }

    /**
     * The student's usual start time on that weekday, so a makeup inherits their
     * normal slot instead of asking for a time that is already known.
     *
     * Public because a manually chosen makeup date wants the same courtesy.
     */
    public static function usualTimeOn(User $student, mixed $date): ?string
    {
        $isoDay = CarbonImmutable::parse($date)->dayOfWeekIso;

        $slot = $student->schedules->firstWhere('day_of_week', $isoDay);

        return $slot?->start_time ? substr((string) $slot->start_time, 0, 5) : null;
    }

    /**
     * Shape the postpone modal reads. Dates are pre-formatted here so the
     * template has no date logic in it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'autoDate' => $this->autoDate?->toDateString(),
            'autoLabel' => $this->autoDate?->format('l, F j, Y'),
            'defaultTime' => $this->defaultTime,
            'minDate' => $this->postponedDate->addDay()->toDateString(),
            'scheduleLabel' => $this->scheduleLabel(),
            'sessionsRemaining' => $this->sessionsRemaining,
            'preview' => array_map(fn (CarbonImmutable $day) => [
                'label' => $day->format('D, M j'),
                'isMakeup' => $this->autoDate !== null && $day->isSameDay($this->autoDate),
            ], $this->upcoming),
        ];
    }
}
