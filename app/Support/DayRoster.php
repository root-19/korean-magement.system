<?php

namespace App\Support;

use App\Enums\EnrollmentStatus;
use App\Enums\SessionStatus;
use App\Models\AttendanceRequest;
use App\Models\ClassSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who an instructor teaches on one date.
 *
 * Three groups make up a day, and dropping any of them hides real work:
 *
 *   1. students whose weekly timetable falls on this weekday;
 *   2. students with a session row already on this date, so a class that was
 *      marked off-timetable stays visible;
 *   3. students whose class was postponed TO this date.
 *
 * Students who have used up every prepaid session are then dropped — see
 * withoutFinishedStudents, which holds their row for 24 hours after the class
 * that finished them so the report can still be filed from it.
 *
 * Group 3 is the one that keeps going missing. A makeup lands on whatever day
 * the student can come back, very often a weekday they have no slot on at all,
 * so a timetable-only roster shows nothing and the class quietly goes untaught
 * and unpaid.
 *
 * Both makeup shapes are handled — see ClassSession::MAKEUP_MARKER. The dashboard
 * and the class list each grew their own copy of this logic and drifted apart:
 * the list was still timetable-only, so a postponed Friday class moved to Monday
 * appeared on neither page.
 *
 * @phpstan-type RosterRow array{
 *     student: User,
 *     profile: \App\Models\StudentProfile|null,
 *     time: string|null,
 *     session: ClassSession|null,
 *     makeup_for: CarbonImmutable|null,
 *     is_extra: bool,
 *     has_report: bool,
 *     request: AttendanceRequest|null,
 * }
 */
final class DayRoster
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function for(int $instructorId, CarbonImmutable $date): Collection
    {
        $dateString = $date->toDateString();

        $rows = self::timetabled($instructorId, $date->dayOfWeekIso);

        // Anyone with a session touching this date, whether or not they are
        // timetabled on it.
        $sessions = ClassSession::query()
            ->with(['student.studentProfile', 'student.schedules'])
            ->where('instructor_id', $instructorId)
            ->where(fn ($q) => $q->where('scheduled_date', $dateString)
                ->orWhere('paid_date', $dateString)
                ->orWhere('rescheduled_date', $dateString))
            ->get();

        $onThisDate = $sessions->filter(
            fn (ClassSession $session) => $session->scheduled_date->toDateString() === $dateString
                || $session->paid_date?->toDateString() === $dateString
        );

        // A postponed class in the current shape points at the day it comes back
        // on while the row itself stays on the original date. On that later day
        // it is the *reason* for a class, not the class: marking it would edit
        // the postponement rather than record the makeup. So it is held apart and
        // only its label is taken — the row the instructor marks is the one
        // sitting on this date, which may not exist yet.
        //
        // The pointer counts only while the row is still postponed. A slot that
        // was re-marked or cleared was taught, or is owed, on its own date, and a
        // pointer left behind by it would keep conjuring a makeup on a day the
        // student is not coming.
        $pointers = $sessions->filter(
            fn (ClassSession $session) => $session->status === SessionStatus::Postponed
                && $session->rescheduled_date?->toDateString() === $dateString
                && $session->scheduled_date->toDateString() !== $dateString
        );

        foreach ($onThisDate as $session) {
            $existing = $rows->get($session->student_id);

            $rows->put($session->student_id, [
                'student' => $existing['student'] ?? $session->student,
                'profile' => $existing['profile'] ?? $session->student?->studentProfile,
                'time' => $existing['time'] ?? $session->startTime(),
                'session' => $session,
                'makeup_for' => $session->makeupOrigin(),
                // Flagged so the view can say why an off-timetable student is here.
                'is_extra' => $existing === null,
            ]);
        }

        foreach ($pointers as $session) {
            $existing = $rows->get($session->student_id);

            $rows->put($session->student_id, [
                'student' => $existing['student'] ?? $session->student,
                'profile' => $existing['profile'] ?? $session->student?->studentProfile,
                // The agreed hour wins over the student's usual slot: a makeup
                // that lands on a weekday they already attend was still moved to
                // a time both sides settled on, and showing the timetable time
                // sends the instructor to the wrong hour.
                'time' => $session->rescheduled_time ?: $session->makeup_time ?: ($existing['time'] ?? null),
                'session' => $existing['session'] ?? null,
                'makeup_for' => $session->scheduled_date,
                'is_extra' => $existing === null,
            ]);
        }

        $rows = self::withoutFinishedStudents(
            $rows->filter(fn (array $row) => $row['student'] !== null),
            $instructorId,
        );

        return self::withReportsAndRequests($rows, $instructorId, $dateString)
            ->sortBy(fn (array $row) => [$row['time'] === null, $row['time'], $row['student']->name])
            ->values();
    }

    /**
     * Drop students who have used up every prepaid session — legacy hid them the
     * same way, on both the dashboard and the class list.
     *
     * Marking the last class takes sessions_remaining to 0 the instant it saves,
     * so hiding on that alone would pull the row out from under the instructor
     * who just marked it — and the report is filed from that row. Two things keep
     * a finished student on a day:
     *
     *   1. a record of their own on this date — their final class, or a makeup
     *      that landed here;
     *   2. a marking made in the last 24 hours, wherever it sits.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private static function withoutFinishedStudents(Collection $rows, int $instructorId): Collection
    {
        $finished = $rows->filter(fn (array $row) => ! ($row['profile']?->hasSessionsRemaining() ?? false)
            && $row['session']?->status === null
            && $row['makeup_for'] === null);

        if ($finished->isEmpty()) {
            return $rows;
        }

        $justMarked = ClassSession::query()
            ->where('instructor_id', $instructorId)
            ->whereIn('student_id', $finished->keys())
            ->whereNotNull('status')
            ->where('marked_at', '>=', CarbonImmutable::now()->subDay())
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $rows->reject(
            fn (array $row, int $studentId) => $finished->has($studentId)
                && ! in_array($studentId, $justMarked, true)
        );
    }

    /**
     * Group 1 — the students whose weekly timetable puts them on this weekday.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function timetabled(int $instructorId, int $isoDay): Collection
    {
        return User::query()
            ->select('users.*')
            ->addSelect('ss.start_time as slot_time')
            ->join('student_profiles as sp', 'sp.user_id', '=', 'users.id')
            ->join('student_schedules as ss', function ($join) use ($isoDay) {
                $join->on('ss.student_id', '=', 'users.id')->where('ss.day_of_week', '=', $isoDay);
            })
            // `schedules` feeds MakeupSchedule in the roster views: postponing a
            // class needs the student's timetable to know where it lands.
            ->with(['studentProfile', 'schedules'])
            ->where('sp.instructor_id', $instructorId)
            ->where('sp.enrollment_status', EnrollmentStatus::Approved)
            ->where('users.is_active', true)
            ->get()
            ->mapWithKeys(fn (User $student) => [$student->id => [
                'student' => $student,
                'profile' => $student->studentProfile,
                'time' => $student->slot_time,
                'session' => null,
                'makeup_for' => null,
                'is_extra' => false,
            ]]);
    }

    /**
     * Attach whether the class is still open to marking and whether its report
     * is in, both fetched once for the whole day rather than per row.
     *
     * A report is matched on the natural key the earnings query uses — instructor,
     * student and the PAID date — so a class taught early is looked up under the
     * day it was actually taught, not the slot it covered.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private static function withReportsAndRequests(
        Collection $rows,
        int $instructorId,
        string $dateString,
    ): Collection {
        $requests = AttendanceRequest::query()
            ->where('instructor_id', $instructorId)
            ->whereDate('class_date', $dateString)
            ->get()
            ->keyBy('student_id');

        // The date each row's report would be filed under.
        $reportDates = $rows->map(
            fn (array $row) => $row['session']?->paid_date?->toDateString() ?? $dateString
        );

        $filed = DB::table('session_reports')
            ->where('instructor_id', $instructorId)
            ->whereIn('student_id', $reportDates->keys())
            ->whereIn('class_date', $reportDates->unique()->values())
            ->get(['student_id', 'class_date'])
            // Keyed by the pair: a student with reports on several dates must not
            // make every one of their rows look filed.
            ->keyBy(fn ($report) => $report->student_id.'|'.$report->class_date);

        return $rows->map(function (array $row) use ($filed, $requests, $reportDates) {
            $id = $row['student']->id;

            $row['has_report'] = $filed->has($id.'|'.$reportDates->get($id));
            $row['request'] = $requests->get($id);

            return $row;
        });
    }
}
