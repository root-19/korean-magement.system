<?php

namespace App\Support;

/**
 * The span of dates a student is actually enrolled for.
 *
 * `student_profiles.start_date` and `end_date` were captured on the enrolment
 * form, validated, and printed on the student page — and then read by nothing
 * that decides who has class. The weekly timetable was projected onto every
 * matching weekday for all time, so a student enrolled on the 26th was listed
 * as a class on the 25th, and on every one of their weekdays in the weeks
 * before they existed.
 *
 * Those back-dated rows are not harmless padding. The day has passed, so the
 * roster renders them closed, and the only control left on a closed class is
 * "For evaluation" — which is how an instructor ends up asking an admin to
 * reopen classes that were never scheduled, and how the evaluation list fills
 * with requests nobody can act on.
 *
 * A blank bound means no bound: 6 of the 273 live students have no start_date
 * (legacy imports that never carried one), and they must keep projecting the
 * way they always have rather than vanishing from every roster.
 */
final class EnrollmentWindow
{
    /**
     * Does the enrolment cover this date?
     *
     * Dates are compared as `Y-m-d` strings, which sort correctly, so a row read
     * straight off the query builder needs no parsing.
     */
    public static function covers(?string $start, ?string $end, string $date): bool
    {
        return ($start === null || $start <= $date)
            && ($end === null || $end >= $date);
    }

    /**
     * Constrain a query to the students enrolled on $date.
     *
     * Takes the table alias because the callers join `student_profiles` under
     * one — `sp` in DayRoster and the dashboard calendar alike.
     *
     * @template TQuery of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function constrain($query, string $date, string $alias = 'student_profiles')
    {
        return $query
            ->where(fn ($q) => $q->whereNull($alias.'.start_date')
                ->orWhere($alias.'.start_date', '<=', $date))
            ->where(fn ($q) => $q->whereNull($alias.'.end_date')
                ->orWhere($alias.'.end_date', '>=', $date));
    }
}
