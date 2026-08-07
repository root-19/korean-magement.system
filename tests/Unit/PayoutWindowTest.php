<?php

namespace Tests\Unit;

use App\Support\PayoutWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The payout week runs Saturday -> Friday inclusive, in app time (KST).
 *
 * The legacy code had two contradictory definitions of this window, so the
 * boundaries are pinned down explicitly here.
 */
class PayoutWindowTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function dates(): array
    {
        return [
            // Saturday is day one and anchors its own week.
            'saturday starts the week' => ['2025-08-02', '2025-08-02', '2025-08-08'],
            'sunday belongs to the preceding saturday' => ['2025-08-03', '2025-08-02', '2025-08-08'],
            'wednesday mid-week' => ['2025-08-06', '2025-08-02', '2025-08-08'],
            // Friday closes the week; the next day opens a new one.
            'friday ends the week' => ['2025-08-08', '2025-08-02', '2025-08-08'],
            'next saturday rolls over' => ['2025-08-09', '2025-08-09', '2025-08-15'],
            // Year boundary: the week straddling New Year must not split.
            'week spanning new year' => ['2026-01-01', '2025-12-27', '2026-01-02'],
        ];
    }

    #[Test]
    #[DataProvider('dates')]
    public function it_resolves_the_saturday_to_friday_window(string $date, string $start, string $end): void
    {
        $window = PayoutWindow::forDate($date);

        $this->assertSame($start, $window->startDate(), "start for {$date}");
        $this->assertSame($end, $window->endDate(), "end for {$date}");
    }

    #[Test]
    public function the_window_is_always_seven_days(): void
    {
        foreach (['2025-08-02', '2025-08-05', '2025-08-08', '2026-02-28', '2024-02-29'] as $date) {
            $window = PayoutWindow::forDate($date);

            $this->assertSame(
                6,
                $window->start->diffInDays($window->end),
                "window starting {$window->startDate()} should span 7 days inclusive"
            );
        }
    }

    #[Test]
    public function it_navigates_between_windows(): void
    {
        $window = PayoutWindow::forDate('2025-08-06');

        $this->assertSame('2025-07-26', $window->previous()->startDate());
        $this->assertSame('2025-08-01', $window->previous()->endDate());
        $this->assertSame('2025-08-09', $window->next()->startDate());
        $this->assertSame('2025-08-15', $window->next()->endDate());
    }

    #[Test]
    public function navigation_round_trips(): void
    {
        $window = PayoutWindow::forDate('2025-08-06');

        $this->assertSame($window->startDate(), $window->next()->previous()->startDate());
        $this->assertSame($window->startDate(), $window->previous()->next()->startDate());
    }

    #[Test]
    public function it_reports_which_dates_it_contains(): void
    {
        $window = PayoutWindow::forDate('2025-08-06');

        $this->assertTrue($window->contains('2025-08-02'), 'first day is inclusive');
        $this->assertTrue($window->contains('2025-08-08'), 'last day is inclusive');
        $this->assertFalse($window->contains('2025-08-01'), 'day before is excluded');
        $this->assertFalse($window->contains('2025-08-09'), 'day after is excluded');
    }

    #[Test]
    public function recent_returns_consecutive_windows_newest_first(): void
    {
        $windows = PayoutWindow::forDate('2025-08-06')->recent(4);

        $this->assertCount(4, $windows);
        $this->assertSame(
            ['2025-08-02', '2025-07-26', '2025-07-19', '2025-07-12'],
            array_map(fn (PayoutWindow $w) => $w->startDate(), $windows),
        );
    }

    #[Test]
    public function it_is_immutable(): void
    {
        $window = PayoutWindow::forDate('2025-08-06');
        $start = $window->startDate();

        $window->next();
        $window->previous();

        $this->assertSame($start, $window->startDate(), 'navigation must not mutate the original');
    }
}
