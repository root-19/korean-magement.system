# Bug report — students appear on the teacher's dashboard / classes page when they have no class

**Reported:** students show up on `instructor/dashboard` and `instructor/classes` straight away, on days
that are not their class.
**Scope of this report:** the instructor-facing roster only. No migration, no earnings logic, no schema
change is needed for any of the fixes below.
**Date:** 2026-09-17

---

## 1. How the roster decides who has class today

`App\Support\DayRoster::for()` (`app/Support/DayRoster.php`) builds a day from three groups:

| group | source | bounded by the enrolment window? |
|---|---|---|
| 1 — weekly timetable | `student_schedules` joined to approved, active profiles (`DayRoster.php:298`) | **yes** — `EnrollmentWindow::constrain(..., 'sp')` at `DayRoster.php:316` |
| 2 — any `class_sessions` row on this date | `DayRoster.php:74-96` | **no** |
| 3 — makeups pointed at this date | `DayRoster.php:98` (`status = postponed`, `rescheduled_date = today`) | **no** |

Group 1 is correct, and `tests/Feature/EnrollmentWindowRosterTest.php` protects it. **Every phantom row
left in the product comes in through group 2, or through a page that never consults the enrolment window
at all.**

---

## 2. Findings, most likely first

### Finding 1 — a cleared class leaves a row behind, and that empty row keeps the student on the roster forever

**Severity: high. This is the one that most matches "kahit hindi pa nila class".**

`AttendanceService::unmark()` (`app/Services/Attendance/AttendanceService.php:267`) nulls the status but
**keeps the row**:

```php
$session->fill([
    'status' => null, 'absent_by' => null, 'postponed_by' => null, 'held_date' => null,
    'rescheduled_date' => null, 'rescheduled_time' => null,
    'marked_by' => $instructor->id, 'marked_at' => now(),
])->save();
```

That is deliberate and asserted by `tests/Feature/AttendanceServiceTest.php:294`
(`$this->assertNull($session->refresh()->status)`), so the row must not start being deleted.

The problem is on the read side. `DayRoster.php:74-96` pulls **every** `class_sessions` row touching the
date and `put()`s a roster line for each one — with no check that the row records anything:

```php
$onThisDate = $sessions->filter(
    fn (ClassSession $session) => $session->scheduled_date->toDateString() === $dateString
        || $session->paid_date?->toDateString() === $dateString
);
```

A `status = NULL` row (a "husk") is indistinguishable from a real class here. So:

**Repro**
1. Teacher marks a class on the wrong date (or an early class on a day the student has no slot).
2. Teacher clears it with the Clear/delete control → `ClassSessionController::destroy` → `unmark()`.
3. The husk row survives on that date.
4. That student now appears on that date's roster **forever**, tagged `Off timetable`
   (`resources/views/instructor/_roster.blade.php:89`), at a time of `—`.
5. The day is past, so `AttendanceRequest::classIsOpen()` is false and the only control on the row is
   **For evaluation** — the instructor asks an admin to reopen a class that never existed.

**This has already happened in production.** `storage/app/cleanup/fix-phantom-classes.php:32` lists
session `#12017` — *"A688 Elizabeth 8 .14 — Aug 10, unmarked — backdated Aug 16, already cleared, husk
left behind"*. That script deleted four rows by hand; the code that manufactures them was never changed.

Same husks also inflate the dashboard calendar: `DashboardController::calendar()` counts
`$rows->whereNull('status')->count()` as `unmarked` (`app/Http/Controllers/Instructor/DashboardController.php:107-160`),
so a cleared class still paints a "needs marking" badge on the day.

Note that the history page already treats these rows as non-classes —
`HistoryController::index()` filters `whereNotNull('status')`. `DayRoster` is the outlier.

---

### Finding 2 — the enrolment window is honoured by the roster and the calendar, and by nothing else

**Severity: high. This is the literal "nag-display agad sa dashboard/classes".**

`EnrollmentWindow` is used in exactly two places (`DayRoster.php:316`,
`DashboardController.php:177`). Every other teacher-facing list of "my students" ignores `start_date`
and `end_date` entirely, so a student whose classes start next month is visible the moment the profile
is approved:

| surface | code | what the teacher sees |
|---|---|---|
| "Total Students" tile | `DashboardController.php:52` → `StudentProfile::scopeTeachable` (`app/Models/StudentProfile.php:87`) | count includes not-yet-started and already-ended students |
| Regular Classes / Data Classes / Trial Students | `app/Http/Controllers/Instructor/ClassListController.php:84` | listed with attendance columns, as if they were live classes |
| Students index | `app/Http/Controllers/Instructor/StudentController.php:39` (`teachable()`) | same |
| Teacher schedule grid | `app/Support/WeeklyScheduleGrid.php:96` | their hours are already blocked as booked |

`scopeTeachable` is documented as "students who should appear in an instructor's working lists" — it is
approved + active only, and has no notion of when the classes actually run.

---

### Finding 3 — a blank `start_date` still means "has class every day, forever"

**Severity: high, because it silently re-creates the bug that `EnrollmentWindow` was written to fix.**

`EnrollmentWindow::covers()/constrain()` treat a null bound as no bound
(`app/Support/EnrollmentWindow.php:53`), which was a conscious decision for 6 legacy imports with no
start date. But nothing stops a **new** profile from being saved that way — the field is `nullable` on
all four write paths:

- `app/Http/Controllers/Instructor/StudentController.php:134` — `'start_date' => ['nullable', 'date']`
- `app/Http/Requests/Admin/StoreStudentRequest.php:40`
- `app/Http/Requests/Admin/UpdateStudentRequest.php:49`
- `StudentEnroller::enrol()` (`app/Services/Enrollment/StudentEnroller.php:85`) and
  `StudentUpdater` both store `$data['start_date'] ?? null`

Clear the date field on the edit form and the student is projected onto every one of their timetabled
weekdays, in every week of history and every week to come.

---

### Finding 4 — approval is retroactive

**Severity: medium.**

`EnrollmentService::approve()` (`app/Services/Enrollment/EnrollmentService.php:20`) flips the status and
records `enrollment_decided_at`, but nothing reads that date when deciding who has class. A student
enrolled by an instructor is invisible while pending (group 1 requires
`enrollment_status = approved`), then becomes visible **for the whole pending period at once**:

**Repro** — instructor enrols a Mon/Wed/Fri student on Sep 1, admin approves on Sep 17. The moment
approval lands, seven closed class days (Sep 2, 4, 6, 9, 11, 13, 16) appear on the teacher's roster,
each offering only "For evaluation".

`enrollment_decided_at` is currently rendered on the admin enrolment list and read nowhere else
(`grep enrollment_decided_at`).

---

### Finding 5 — "Start date" is captured as the enrolment day, not the first class day

**Severity: medium — no code defect, but it is the everyday cause of "not their class yet".**

Both create forms default the field to today:

- `resources/views/instructor/students/create.blade.php:130` — `old('start_date', now()->toDateString())`
- `resources/views/admin/students/create.blade.php:138` — same

The roster has no other signal for "when do the lessons begin", so a student enrolled on a Tuesday for a
Mon/Wed/Fri plan whose first lesson is agreed for **next** Monday is listed on Wednesday and Friday of
the enrolment week. Nothing in the code is wrong; the field's meaning and default are.

---

## 3. Fixes

### Fix 1 — an empty slot may decorate a roster line, never create one  *(fixes Finding 1)*

In `DayRoster::for()`, a `class_sessions` row should only be able to **add** a student to a day when it
records something. Define "records something" as: `status` is set, **or** `held_date` is set (an early
class), **or** it carries the legacy makeup marker (`postpone_reason LIKE 'Rescheduled from %'`, i.e.
`makeupOrigin() !== null`).

Suggested shape — add a helper to `ClassSession` beside `isEarly()`/`makeupOrigin()`:

```php
/**
 * Does this row record something, or is it an empty slot?
 *
 * unmark() keeps the row and nulls the status, so a cleared class leaves a
 * husk behind. On a day the student is timetabled the husk is harmless — the
 * timetable lists them anyway and marking finds the row by
 * (instructor, student, scheduled_date). Off the timetable it is a class that
 * never happened, renders closed, and the only control left on it is
 * "For evaluation".
 */
public function records(): bool
{
    return $this->status !== null
        || $this->held_date !== null
        || $this->makeupOrigin() !== null;
}
```

then in the `$onThisDate` loop (`DayRoster.php:117`):

```php
foreach ($onThisDate as $session) {
    $existing = $rows->get($session->student_id);

    // An empty slot is not a class: it may attach itself to a line the
    // timetable already produced, but it cannot conjure one.
    if ($existing === null && ! $session->records()) {
        continue;
    }
    ...
}
```

Why this is safe:

- `mark()` looks the row up by `(instructor_id, student_id, scheduled_date)`
  (`AttendanceService.php:46`), so a husk that is no longer displayed is still reused when the class is
  marked later — no duplicate-key risk against `class_sessions_slot_unique`.
- Legacy makeup rows are unmarked rows carrying the marker, so `records()` keeps them and
  `tests/Feature/LegacyMakeupTest.php` stays green.
- `EnrollmentWindowRosterTest::a_class_recorded_before_the_start_date_still_shows` uses a **present**
  row, so a real class before the start date still shows, as intended.
- The husk also stops inflating the calendar once `calendar()` uses the same rule — filter `$recorded`
  to rows where `status` is not null, or to `records()`, in `DashboardController.php:107`.

**Migration check before shipping** (I could not run it — see §5): count how many currently-visible rows
this hides, so nobody loses a real owed class imported from legacy:

```sql
SELECT cs.id, cs.instructor_id, cs.student_id, cs.scheduled_date
FROM class_sessions cs
JOIN student_profiles sp ON sp.user_id = cs.student_id
LEFT JOIN student_schedules ss
       ON ss.student_id = cs.student_id
      AND ss.day_of_week = WEEKDAY(cs.scheduled_date) + 1
WHERE cs.status IS NULL
  AND cs.held_date IS NULL
  AND (cs.postpone_reason IS NULL OR cs.postpone_reason NOT LIKE 'Rescheduled from %')
  AND ss.id IS NULL;               -- off the timetable, so today it shows as "Off timetable"
```

If that returns rows that look like genuine untaught legacy classes, hand them to the same
evaluation/cleanup path as `storage/app/cleanup/fix-phantom-classes.php` rather than widening the rule.

### Fix 2 — one definition of "teachable today"  *(fixes Finding 2)*

Add a scope beside `scopeTeachable` in `app/Models/StudentProfile.php` and route the four surfaces
through it, so the lists and the roster cannot disagree:

```php
/** Teachable, and inside the enrolment window on $date. */
public function scopeTeachableOn(Builder $query, string $date): Builder
{
    return $query->teachable()->tap(
        fn (Builder $q) => EnrollmentWindow::constrain($q, $date, 'student_profiles')
    );
}
```

- `DashboardController.php:52` → `StudentProfile::forInstructor($id)->teachableOn(today)->count()`
- `ClassListController.php:84` → add `EnrollmentWindow::constrain($query, today, 'student_profiles')`
- `WeeklyScheduleGrid::fromStudentClasses()` → same, with the `sp` alias
- Instructor students index (`StudentController.php:39`): **do not** hide them here. This page is the
  student directory, not a class list. Show a badge instead — `Starts Oct 1` / `Ended Aug 30` — using
  `$profile->start_date`, so the teacher can see the student exists and why they have no classes yet.

### Fix 3 — stop new profiles being saved with no window  *(fixes Finding 3)*

Two options; **3a is the smaller and is the recommendation.**

- **3a — floor the window with a date the row always has.** In `EnrollmentWindow::constrain()/covers()`,
  when `start_date` is null fall back to `COALESCE(enrollment_decided_at, student_profiles.created_at)`.
  The 6 legacy imports keep projecting from their import date instead of from the beginning of time,
  and nothing has to be back-filled or re-validated.
- **3b — require the date on new and edited profiles** (`'required', 'date'` in the three validators
  above). Cleaner going forward, but it is a validation/behaviour change on forms admins use daily, and
  CLAUDE.md says to ask first. **Needs your approval.**

### Fix 4 — approval is not retroactive  *(fixes Finding 4)*

Make the effective start `max(start_date, date(enrollment_decided_at))` — but only when no class was
actually recorded before the decision, so a back-dated enrolment that was genuinely taught is not
hidden. Keep the rule inside `EnrollmentWindow` so there is still exactly one place that answers "was
this student enrolled on this date". `enrollment_decided_at` already exists and is already set by
`EnrollmentService::approve()` — no schema change.

**Needs your decision:** should approval on Sep 17 of an enrolment dated Sep 1 make Sep 2-16 disappear
from the teacher's roster? My recommendation is yes (they are all closed days that can only produce
evaluation requests), but this is a business rule, not a defect.

### Fix 5 — make the field say what it means  *(fixes Finding 5)*

View-only change, no logic:

- Relabel "Start date" → **"First class date"** with the hint *"the first day this student actually has
  a class — not the day you enrolled them"*, in `resources/views/instructor/students/create.blade.php`,
  `resources/views/admin/students/create.blade.php` and both edit views.
- Change the default from `now()->toDateString()` to **blank**, or to the earliest ticked timetable day
  on or after today. Blank is only safe once Fix 3 lands, otherwise a blank date means "every day
  forever".

---

## 4. Regression tests to add

Put the roster ones next to the existing `tests/Feature/EnrollmentWindowRosterTest.php`:

1. **`a_cleared_class_leaves_no_phantom_row`** — mark a class on a day the student is not timetabled,
   `unmark()` it, then assert the roster for that date does not list the student and does not render
   "For evaluation". (This is the `#12017` shape.)
2. **`a_cleared_class_is_not_counted_on_the_calendar`** — same setup, assert the day cell's `unmarked`
   is 0.
3. **`a_legacy_makeup_row_still_shows_after_the_change`** — already covered by `LegacyMakeupTest`; run
   it, do not duplicate it.
4. **`an_unmarked_row_on_a_timetabled_day_is_still_markable`** — husk on a normal class day: one roster
   line, Present still works, and marking updates the existing row instead of inserting a second one.
5. **`a_student_whose_classes_have_not_started_is_not_counted_or_listed`** — `start_date` in the future:
   assert the dashboard student count and each of the three class-list pages exclude them, and that the
   students index still shows them with a "Starts …" badge.
6. **`a_profile_with_no_start_date_does_not_project_before_it_was_created`** — Fix 3a.
7. **`approval_does_not_backdate_the_roster`** — Fix 4, if you approve that rule.

**Running the suite:** `phpunit.xml` sets `DB_DATABASE=academy10_modern_test` but **not `DB_HOST`**, so a
bare `php artisan test` inherits `DB_HOST` from `.env` — the production host. Always force it:

```
DB_HOST=127.0.0.1 php artisan test --filter=Roster
```

---

## 5. What I could not verify

Read access to the production database was blocked in this session, so every finding above is from code
plus the evidence already written into `storage/app/cleanup/fix-phantom-classes.php`. Before starting,
run these three counts to see which finding is actually biting hardest, and in what volume:

```sql
-- Finding 1: husks that can produce a phantom roster line
SELECT COUNT(*) FROM class_sessions
WHERE status IS NULL AND held_date IS NULL
  AND (postpone_reason IS NULL OR postpone_reason NOT LIKE 'Rescheduled from %');

-- Findings 2 and 5: students visible in the lists whose classes have not started
SELECT COUNT(*) FROM student_profiles
WHERE enrollment_status = 'approved' AND start_date > CURDATE();

-- Finding 3: profiles with no window at all, and how many are recent
SELECT COUNT(*) AS no_start, SUM(created_at > '2026-01-01') AS created_this_year
FROM student_profiles WHERE start_date IS NULL;
```

---

## 6. Suggested order of work

1. **Fix 1** — self-contained, highest impact, no business-rule decision needed.
2. **Fix 2** — mechanical, one new scope and four call sites.
3. **Fix 3a** — small, and it closes the hole that would let Finding 3 come back.
4. **Fix 5** — views only.
5. **Fix 4** — last, and only after the rule in §3 Fix 4 is agreed.

Nothing here touches earnings, payouts or `class_sessions` writes, so the earnings verification step is
not in scope for this change.
