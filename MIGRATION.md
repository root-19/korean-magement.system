# Legacy → Laravel 10 migration

The native-PHP app in the parent directory, rebuilt on Laravel 10.50 with a
normalised schema, Blade + Tailwind + Alpine, and a domain layer for the payroll
rules.

**Status:** foundation, the instructor area, the public landing page and the
admin area are done. See [What is left](#what-is-left).

---

## Getting started

```bash
cd modern-web
composer install
npm install

# One-off: create the databases
mysql -u root -e "CREATE DATABASE academy10_modern      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -e "CREATE DATABASE academy10_modern_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

php artisan migrate
php artisan legacy:import          # pulls from the LEGACY_DB_* connection
npm run build

php artisan serve
```

Sign in with any imported account. Passwords carry over unchanged — the legacy
digests are `password_hash()` output, which Laravel's bcrypt driver verifies
as-is.

### If the page loads with no styling

Almost always a stale `public/hot`.

That file is Vite's dev-server marker. While it exists, `@vite()` ignores the
compiled CSS in `public/build/` and points every asset at the dev server
(`http://[::1]:5173/...`) instead. `npm run dev` deletes it on a clean exit, but
closing the terminal or killing the process leaves it behind — and then nothing
serves those URLs, so the page renders as bare HTML.

```bash
# Check
cat public/hot            # if this prints a URL, that is the problem

# Fix: either delete it and use the built assets…
rm public/hot && npm run build

# …or start the dev server it is pointing at
npm run dev
```

To confirm what the app is actually emitting:

```bash
php artisan tinker --execute="echo app(Illuminate\Foundation\Vite::class)(['resources/css/app.css','resources/js/app.js']);"
```

A `/build/assets/app-*.css` URL is correct. A `:5173` URL means the dev server
must be running.

`/public/hot` is already in `.gitignore`, so it never reaches production — but it
does survive locally.

### Commands

| Command | Purpose |
| --- | --- |
| `php artisan legacy:import --dry-run` | Report what would change, write nothing |
| `php artisan legacy:import` | Import; idempotent, safe to re-run |
| `php artisan legacy:import --fresh` | Wipe target tables first (prompts) |
| `php artisan legacy:verify-earnings` | Compare payouts against the original legacy SQL |
| `php artisan test` | 186 tests |

---

## Is it correct?

`legacy:verify-earnings` runs the **original legacy SQL** against the old
database and the new `EarningsCalculator` against the imported one, for every
instructor across every payout week, and diffs them. The legacy query is
reproduced verbatim in the command rather than reimplemented, so it is a genuine
independent check.

```
weeks compared ....... 1,980
weeks with earnings .. 150
legacy gross total ... ₱161,733.08
new gross total ...... ₱161,733.08
difference ........... ₱0.00

✓ Every week matches.
```

Re-run this after any change to the earnings logic.

---

## Schema changes

### The god-table split

`users` carried admin, instructor and student rows side by side plus ~20
student-only columns that were NULL on two thirds of every row.

| Was | Is now |
| --- | --- |
| `users` (34 columns, all roles) | `users` — identity and auth only |
| `users.bio`, `credential_image_1..3`, `bank_name` | `instructor_profiles` |
| `users.instructor_id`, `learning_time`, `semester`, … | `student_profiles` |
| `users.schedule` + `monday_time`…`sunday_time` | `student_schedules` (one row per day) |
| `teacher_presence` | `class_sessions` |
| `feedback` | `session_reports` |
| `teacher_schedules` | `instructor_availabilities` |
| `audit_log` | `audit_logs` (polymorphic subject) |
| `archived_users`, `deleted_users`, `feedback_backup`, `teacher_attendance_backup`, `teacher_presence_backup`, `teacher_student_backup` | **dropped** — replaced by soft deletes |
| `user_sessions` | **dropped** — Laravel sessions |

### Columns renamed because the old names lied

| Legacy | New | Why |
| --- | --- | --- |
| `users.semester` | `student_profiles.sessions_remaining` | Never a semester. A countdown of prepaid classes: marking present ran `semester = GREATEST(semester - 1, 0)`, and `ClassModel` selected it as `u.semester AS remaining_sessions`. |
| `users.present_count` | `sessions_attended` | |
| `users.deduction_days` | `sessions_deducted` | Sessions written off at enrolment, not days. |
| `users.regular` | `is_regular` | Was the string `'regular'` or `''`. |
| `users.teaching_methods` | `teaching_method` | Only ever held one value. |
| `feedback` | `session_reports` | Not student feedback — the instructor's assessment, and filing it is what unlocks payment. |

Session accounting, as the feedback page rendered it:

```
purchased = sessions_attended + student-absent + sessions_remaining + sessions_deducted
```

A student-absent class burns a prepaid session but is neither attended nor
remaining, so it is its own term.

### The early-class fix

Legacy had `UNIQUE (teacher_id, student_id, date)`, so a class taught ahead of
schedule could not be stored on the day it was taught — that slot held the
student's regular class. The date was smuggled into a prose column:

```
status          = 'present'
postpone_reason = 'Early class held on 2025-04-18'
```

and every earnings query dug it back out:

```sql
CASE WHEN tp.postpone_reason LIKE 'Early class held on %'
     THEN COALESCE(STR_TO_DATE(RIGHT(tp.postpone_reason, 10), '%Y-%m-%d'), DATE(tp.date))
     ELSE DATE(tp.date) END
```

A date parsed from the last 10 characters of free text, on every filter, join and
`GROUP BY` — unindexable, and silently wrong if anyone appended a note.

Now:

```sql
scheduled_date  DATE  -- the timetable slot
held_date       DATE  -- NULL unless taught early
paid_date       DATE  AS (COALESCE(held_date, scheduled_date)) STORED   -- indexed
```

`postpone_reason` is free text again, and nothing parses it.

### Deleted students

Preserving an instructor's earnings across a student deletion is what drove most
of the legacy complexity: snapshot columns on `teacher_presence`, negative
`student_id` values, a username-matching `NOT EXISTS` subquery in every earnings
query, and six backup tables.

Soft deletes remove the cause. The student's row survives, so the earnings query
just joins to it. `EarningsCalculator` uses a **raw** join to `users`,
deliberately bypassing the soft-delete scope.

The importer found **two** different legacy deletion paths that orphaned
attendance rows:

- `student_id < 0` — `hardDeleteStudentForInstructor()` negated the id (1 row)
- `student_id = 0` — a second path zeroed it (82 rows, all one student)

The zeroed rows were **silently dropped from legacy earnings**: `student_id = 0`
satisfies neither the `> 0` nor the `< 0` branch of the gate in
`getTeacherEarnings`. The importer rebuilds both kinds of student from the
`student_username` snapshot, so the sessions are preserved. This does not
retroactively create pay — the report requirement still gates payment and none
of those rows has a report — but the teaching record no longer vanishes.

---

## Payroll rules

All in `app/Services/Earnings/EarningsCalculator.php`, replacing ~100 lines of
nested SQL. Behaviour is unchanged — see the parity check above.

1. Only **settled** sessions count: `present` or `absent`. Postponed was never
   taught; NULL is unmarked.
2. **Pays** when present, or absent through the student's fault — the instructor
   showed up and waited either way.
3. **Deducts** when absent through the instructor's fault.
4. A **report must be filed** before a session pays. Sessions before
   `academy.feedback_required_from` predate the rule and pay unconditionally.
   Deductions are *not* gated on a report — an instructor cannot dodge one by not
   filing.
5. Amount = hourly rate × `learning_time / 60`, to 2dp. A blank teaching method
   bills at the audio rate (preserved from legacy; changing it would restate
   historical pay).
6. Everything keys off `paid_date`, so an early class is paid in the week the
   work was done.

Rates and the cutoff live in `config/academy.php`, not in code.

### Two things the new query no longer needs

- **The `GROUP BY` + `MAX(CASE …)` derived table.** The unique key on
  (instructor, student, scheduled_date) means one row per slot already. A genuine
  double class (regular plus an early one pulled onto the same day) still pays
  twice, because the two rows differ in `scheduled_date` — covered by
  `a_double_class_on_one_day_pays_twice`.
- **The username-matching `NOT EXISTS`.** Soft deletes remove the duplicate-row
  cause; the importer applies the old rule once, on the way in.

### The payout week

Saturday → Friday inclusive, in KST. Legacy had **two contradictory**
definitions live at once:

| Function | Window |
| --- | --- |
| `getCurrentPayoutWindow()` | Sat 00:00 → Fri 23:59 |
| `getPayoutWindowForDate()` | Sun 12:00 → Sat 23:00 |

A Saturday class landed in different weeks depending on which you asked.
`app/Support/PayoutWindow.php` implements the Saturday→Friday one — the report
instructors are actually paid from — and it is the only one in the codebase.

---

## Security fixes carried over

The legacy app had these; they are fixed here, not ported.

- **Live production credentials committed to source.** `config/database.php` in
  the parent directory holds a real host, user and password in plain text. Treat
  those as compromised and rotate them.
- **No CSRF protection.** Every legacy form posted unprotected. Laravel's
  `VerifyCsrfToken` is on by default.
- **No authorisation on student ids.** Legacy attendance and feedback endpoints
  took `student_id` from `$_POST` and trusted it, so any instructor could mark
  attendance — and therefore bill — against another instructor's student. Every
  controller here resolves the student through `authorizedStudent()`, which
  requires the assignment.
- **Path traversal in the router.** `router.php` ran
  `require "app/views/{$action}.php"` on a path derived from the URL. Replaced by
  named routes.
- **No login rate limiting.** Now 5 attempts per minute per identifier+IP.
- **`SET foreign_key_checks = 0`** around deletions in `User::hardDelete…`.
  Gone — soft deletes need no constraint bypass.
- **Deactivated accounts kept working** until their session expired.
  `EnsureUserHasRole` logs them out on the next request.

---

## Layout

```
app/
  Console/Commands/     legacy:import, legacy:verify-earnings
  Enums/                Role, TeachingMethod, SessionStatus, Party, …
  Http/
    Controllers/
      Auth/             LoginController
      Instructor/       Dashboard, ClassSession, Student, SessionReport, Earnings
    Middleware/         EnsureUserHasRole, RedirectToRoleHome
  Models/               User, StudentProfile, ClassSession, SessionReport, …
  Services/
    Attendance/         AttendanceService — marking, early classes, counters
    Earnings/           EarningsCalculator, EarningsSummary, EarningsLine
  Support/              PayoutWindow, Navigation
resources/
  css/app.css           design tokens: .btn-*, .card, .badge-*, .table, .nav-link
  views/
    components/         icon, avatar, card, stat-card, empty-state, flash, toasts
    layouts/            app (sidebar shell), guest
    instructor/         dashboard, classes/, students/, reports/, earnings/
```

### The landing page

`/` is the public marketing page, rebuilt from the legacy `public/index.php`:
near-black gradient (`#181818` → `#232323`), orange `#ff8800` heading, yellow
`#ffe600` Korean subtitle, the typewriter paragraph, and the "Get Started →"
button that reveals the sign-in card in place. It is **always dark and has no
theme toggle**, as the original was — all of its styling is scoped under
`.landing` in `app.css` so none of it reaches the signed-in app, which uses the
teal brand palette.

Bolted onto it is the **weekly schedule table** that previously existed only on
`public/instructor_profile.php` — the glass panel, gradient heading and
green/red/grey status pills are carried over unchanged. A teacher picker across
the top switches between instructors; each is a plain link with an
`?instructor=` query string, so it works without JavaScript and every teacher's
week is a shareable URL.

`app/Support/WeeklyScheduleGrid.php` builds the day × hour grid and reproduces
the legacy two-source behaviour: published `instructor_availabilities` first,
falling back to the hours the instructor's students already hold classes in. The
fallback is load-bearing — **exactly 1 of 33 instructors has published any
availability** (legacy `teacher_schedules` held 2 rows total), so without it the
table would be empty for everyone else.

Two deliberate changes from the legacy page:

- **No student names.** The legacy fallback printed the student's name into each
  cell, on a page needing no login. Nothing here emits one, and
  `a_student_name_is_never_exposed_on_the_public_page` guards it.
- **A booked hour reads "Not Available", not "Available".** Legacy labelled
  taken slots green, which tells a prospective student a slot is free when it is
  not. Same three-state legend, honest mapping.

Because published availability is so scarce, the picker sorts instructors with
real published hours first — otherwise a visitor lands on a table derived
entirely from the fallback. **Porting the instructor availability editor is the
highest-value next step for this page**; until teachers can publish hours, the
schedule is mostly "No Schedule".

### The theme

The signed-in app reproduces the legacy sidebars
(`app/views/{instructor,admin}/layout/sidebar.php`):

| Element | Value | Legacy source |
| --- | --- | --- |
| Page | `gray-900` | `<body class="bg-gray-900">` on every view |
| Sidebar / panels | `gray-800`, panels on a `gray-800 → gray-900` gradient | `bg-gradient-to-br from-gray-800 to-gray-900` |
| Borders | `gray-700` | |
| Headings, nav, stat figures | `yellow-400` = `brand-400` | `text-yellow-400` |
| Nav hover | `orange-400` = `accent-400` | `hover:text-orange-400 hover:bg-gray-700` |
| Primary button | `yellow-400` bg, `gray-900` text | dashboard tour button, sidebar toggle |
| Status pills | `bg-<c>-500/20 text-<c>-400` | `px-3 py-1 rounded-full bg-yellow-500/20` |

**Dark only, no toggle** — every legacy view was, so `darkMode` is not
configured at all and no `dark:` variants are generated. That is deliberate: with
`darkMode` unset Tailwind defaults to the `media` strategy, so a leftover
light-base + `dark:` override pair would follow the visitor's OS preference and
render light. The views were swept to single dark values; the compiled CSS
dropped from 61 KB to 48 KB as a result.

The public landing page is the exception — it keeps its own near-black gradient
under `.landing`, scoped so it cannot reach the app.

Other conventions:

- **Semantic colour, not hue.** `success` / `danger` / `warning` / `brand`.
  Attendance states map onto them, so a status change is one class change.
- **Component classes over repetition.** `.card`, `.btn-*`, `.badge-*`, `.table`,
  `.nav-link`, `.heading` in `app.css`. Restyling the whole app is editing those,
  not sweeping 20 views — which is exactly how the legacy look was applied.
- **`.numeric`** applies tabular figures wherever numbers are compared down a
  column.
- **Wide tables scroll themselves** via `.table-wrap`; the page body never
  scrolls sideways.
- **Attendance and approvals work without JavaScript.** The controls are real
  forms; Alpine only handles the absent-party prompt and the reject-reason panel.
- **`@money` / `@money2`** Blade directives for KRW.
- No CDN dependencies. The legacy pages pulled Tailwind, feather-icons,
  SweetAlert2 and intro.js from four CDNs on every request; icons are now inline
  SVG (`<x-icon>`) and toasts are `<x-toasts>`.

### The admin area

Consolidated rather than ported page-for-page — the legacy sidebar had 17 links
over 24 files, several running variants of the same query.

| New page | Replaces |
| --- | --- |
| Dashboard | `admin/dashboard.php` |
| Instructors (+ detail) | `admin/instructor.php`, `admin/instructor_table.php`, `admin/teacher_schedules.php` |
| Students (5 filters) | `admin/student.php`, `admin/user_table.php`, `admin/free_students.php`, `admin/re_enrolled.php` |
| Enrolments | `admin/pending_enrollments.php` + `AdminController::approve/rejectEnrollment` |
| Classes (one day, all instructors) | `admin/all_classes.php`, `admin/data_classes.php`, `admin/teacher-student.php` |
| Payroll | `admin/instractor_earn.php` |

**Payout finalisation now exists.** Previously flagged as a gap: earnings were
recomputed live on every view, so editing an old attendance row silently restated
what had already been paid. `PayoutService` freezes a week into a `payouts` row,
records who paid it and when, refuses to overwrite a paid week without an
explicit override, and flags **drift** when a frozen figure no longer matches the
live one. `payouts.deductions` is stored too — the legacy table had no column for
it even though teacher-absent sessions were being subtracted on screen.

Two things worth knowing about admin queries:

- The student list eager-loads `user` with **`withTrashed()`**, and the student
  routes bind **`->withTrashed()`**. The list joins `users` directly, which does
  not apply the soft-delete scope, so an archived or deleted student's row is
  returned — without this the relation came back null for exactly those rows and
  the page 500'd. Admins are the people who need to see archived students.
- Rejecting an enrolment **deactivates**, never deletes. Destroying the row is
  what made instructor earnings so hard to preserve in the legacy app.

---

## Notes on the imported data

| | |
| --- | --- |
| users | 256 (1 admin, 33 instructors, 222 students) |
| student_profiles | 222 |
| student_schedules | 689 |
| class_sessions | 3,084 — none skipped |
| session_reports | 2,071, all linked to a session |
| restored students | 2 (orphaned by hard deletes) |
| audit_logs | 247 |

Two things worth knowing:

- **1 schedule slot was dropped** — a student had a day named in
  `users.schedule` with no corresponding `<day>_time`, and a schedule row cannot
  exist without a start time. The importer counts these.
- **`sessions_attended` drifts from the attendance rows** on some students,
  because legacy maintained it by hand outside a transaction. The student detail
  page surfaces the mismatch instead of hiding it; attendance rows are the source
  of truth.

---

## What is left

Done so far: the foundation, the instructor area, the public landing page, the
admin area, and payout finalisation. Still to port:

- **Instructor availability editor** — the highest-value remaining item. Only 1
  of 33 instructors has published any hours, so the landing page's schedule is
  mostly "No Schedule" until teachers can set their own. The
  `instructor_availabilities` table, model and `WeeklyScheduleGrid` are all in
  place; what is missing is the form.
- **Instructor extras**: profile editing (`instructor/profile.php`), bookings
  inbox (`instructor/bookings.php` — the `bookings` table and model exist,
  nothing reads them yet), the public per-instructor profile page
  (`public/instructor_profile.php`, including its trial-booking form),
  demo/trial classes, class history.
- **Admin extras**: account creation for instructors and students
  (`admin/accounts.php`, `admin/update_user.php`), password resets, and the
  backup/restore screens (`admin/backup.php` — largely obsolete now that soft
  deletes replaced the six backup tables, so port only what is still wanted).
- **Student-facing area**: no legacy equivalent, but the schema supports it.

Patterns to follow when adding pages: a controller per resource, domain logic in
a service (never in a controller or a Blade file), Blade components for anything
appearing twice, component classes in `app.css` rather than repeated utility
strings, and a test for anything touching money.
