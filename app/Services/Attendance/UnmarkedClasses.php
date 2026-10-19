<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRequest;
use App\Models\User;
use App\Support\DayRoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Past classes with no Present, Absent or Postponed recorded.
 *
 * Built from DayRoster rather than a query of its own, so every entry is the
 * same row the instructor sees on that date's roster — including timetabled
 * classes that never got a session row at all, which a class_sessions query
 * misses. The instructor dashboard and the admin's list of teachers with
 * classes for evaluation both read it, so the two cannot disagree.
 */
class UnmarkedClasses
{
    /** How many past days are looked back over. Today is left out: it is still open to marking. */
    public const LOOKBACK_DAYS = 7;

    /**
     * One instructor's unmarked past classes, newest first, each row with its `date`.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forInstructor(int $instructorId): Collection
    {
        $today = CarbonImmutable::today();

        return collect(range(1, self::LOOKBACK_DAYS))
            ->map(fn (int $daysAgo) => $today->subDays($daysAgo))
            ->flatMap(fn (CarbonImmutable $date) => DayRoster::for($instructorId, $date)
                ->filter(fn (array $row) => $row['session']?->status === null)
                ->map(fn (array $row) => $row + ['date' => $date]))
            ->values();
    }

    /**
     * Active instructors whose roster offers "For evaluation" on at least one
     * class: closed to marking, and not already waiting on an admin decision.
     * A rejected request leaves the button there, so it is still listed.
     *
     * Most classes first — the instructor furthest behind is the one to chase.
     *
     * @return Collection<int, array{instructor: User, classes: Collection<int, array<string, mixed>>}>
     */
    public function awaitingEvaluation(): Collection
    {
        return User::query()
            ->instructors()
            ->active()
            ->whereHas('students', fn ($query) => $query->teachable())
            ->orderBy('name')
            ->get()
            ->map(fn (User $instructor) => [
                'instructor' => $instructor,
                'classes' => $this->forInstructor($instructor->id)
                    ->reject(fn (array $row) => AttendanceRequest::classIsOpen($row['date'], $row['request'])
                        || $row['request']?->isPending())
                    ->values(),
            ])
            ->filter(fn (array $item) => $item['classes']->isNotEmpty())
            ->sortByDesc(fn (array $item) => $item['classes']->count())
            ->values();
    }
}
