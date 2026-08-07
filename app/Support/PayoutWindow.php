<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * The weekly pay period: Saturday through Friday inclusive, in app time (KST).
 *
 * The legacy code carried TWO contradictory definitions of this window:
 *
 *   getCurrentPayoutWindow()   Saturday 00:00 -> Friday 23:59  (date strings)
 *   getPayoutWindowForDate()   Sunday 12:00   -> Saturday 23:00 (datetimes)
 *
 * Both were live: the earnings report used the first, while
 * getTaughtStudentsSummaryWithPayoutPeriod() bucketed the very same sessions
 * with the second, so a Saturday class landed in different weeks depending on
 * which function you asked. The Saturday->Friday definition governs the report
 * instructors are actually paid from, so that is the one implemented here and
 * the only one in the codebase.
 *
 * Immutable: every navigation method returns a new instance.
 */
final class PayoutWindow implements Arrayable
{
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    /**
     * The window containing $date (defaults to today).
     */
    public static function forDate(mixed $date = null): self
    {
        $day = CarbonImmutable::parse($date ?? now())->startOfDay();

        // ISO day the week starts on; 6 = Saturday per config/academy.php.
        $startsOn = (int) config('academy.payout.week_starts_on', 6);

        // Days to step back to reach the most recent $startsOn, 0 if today is it.
        $back = ($day->dayOfWeekIso - $startsOn + 7) % 7;

        $start = $day->subDays($back);

        return new self($start, $start->addDays(6));
    }

    public static function current(): self
    {
        return self::forDate(now());
    }

    public function previous(): self
    {
        return self::forDate($this->start->subWeek());
    }

    public function next(): self
    {
        return self::forDate($this->start->addWeek());
    }

    public function contains(mixed $date): bool
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        return $day->gte($this->start) && $day->lte($this->end);
    }

    public function isCurrent(): bool
    {
        return $this->contains(now());
    }

    /** 'Y-m-d' — the form the query builder compares against DATE columns. */
    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end->toDateString();
    }

    /** "Aug 2 – Aug 8, 2025" */
    public function label(): string
    {
        return $this->start->isSameYear($this->end)
            ? $this->start->format('M j').' – '.$this->end->format('M j, Y')
            : $this->start->format('M j, Y').' – '.$this->end->format('M j, Y');
    }

    /** "2025-W31" — stable key for caching and query strings. */
    public function key(): string
    {
        return $this->start->format('o-\WW');
    }

    /**
     * The $count most recent windows, newest first, ending with this one.
     *
     * @return array<int, self>
     */
    public function recent(int $count = 12): array
    {
        $windows = [];
        $window = $this;

        for ($i = 0; $i < $count; $i++) {
            $windows[] = $window;
            $window = $window->previous();
        }

        return $windows;
    }

    /** @return array{start: string, end: string, label: string, key: string} */
    public function toArray(): array
    {
        return [
            'start' => $this->startDate(),
            'end' => $this->endDate(),
            'label' => $this->label(),
            'key' => $this->key(),
        ];
    }
}
