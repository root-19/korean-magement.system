<?php

namespace App\Services\Kakao;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A day's roster, shaped into a KakaoTalk message.
 *
 * The `text` template rather than the `feed` one from Kakao's sample: a roster
 * is a list, and `feed` is built around a single image and a headline. `text`
 * caps at 200 characters, so the list is trimmed and the remainder counted —
 * silently losing the last students off a schedule would be worse than saying
 * how many did not fit.
 *
 * Times come from the roster row, which already prefers a makeup's agreed hour
 * over the student's usual slot — see DayRoster.
 */
final class RosterMessage
{
    /** Kakao's limit on the default text template. */
    private const MAX_TEXT = 200;

    /**
     * @param  Collection<int, array<string, mixed>>  $roster  rows from DayRoster::for()
     * @return array<string, mixed>
     */
    public static function forDay(Collection $roster, CarbonImmutable $date, string $link): array
    {
        return [
            'object_type' => 'text',
            'text' => self::text($roster, $date),
            'link' => ['web_url' => $link, 'mobile_web_url' => $link],
            'button_title' => 'Open dashboard',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $roster
     */
    private static function text(Collection $roster, CarbonImmutable $date): string
    {
        $header = $roster->isEmpty()
            ? $date->format('D, M j').' — no classes'
            : sprintf(
                '%s — %d %s',
                $date->format('D, M j'),
                $roster->count(),
                $roster->count() === 1 ? 'class' : 'classes',
            );

        $lines = [$header];
        $shown = 0;

        foreach ($roster as $row) {
            $line = self::line($row);

            // Leave room for the "+N more" tail before committing a line.
            $withLine = implode("\n", [...$lines, $line]);
            $remaining = $roster->count() - $shown - 1;
            $tail = $remaining > 0 ? "\n+".$remaining.' more' : '';

            if (mb_strlen($withLine.$tail) > self::MAX_TEXT) {
                break;
            }

            $lines[] = $line;
            $shown++;
        }

        $left = $roster->count() - $shown;

        if ($left > 0) {
            $lines[] = '+'.$left.' more';
        }

        return mb_substr(implode("\n", $lines), 0, self::MAX_TEXT);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function line(array $row): string
    {
        $time = $row['time']
            ? CarbonImmutable::parse($row['time'])->format('g:i A')
            : '—';

        $note = match (true) {
            $row['makeup_for'] !== null => ' (makeup)',
            $row['session']?->isEarly() ?? false => ' (early)',
            (bool) $row['is_extra'] => ' (extra)',
            default => '',
        };

        return $time.' '.$row['student']->name.$note;
    }
}
