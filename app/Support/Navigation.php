<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AttendanceRequest;
use App\Models\Booking;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the sidebar for the signed-in user's role.
 *
 * Mirrors the legacy sidebars item for item
 * (app/views/{instructor,admin}/layout/sidebar.php), with two changes:
 *
 *   * Items are grouped under headings. The legacy sidebars were one flat run of
 *     13 and 18 links respectively, which is a lot to scan.
 *   * Active state comes from the named route, so renaming a URL cannot break
 *     the highlight. The legacy sidebars highlighted nothing at all, so you
 *     could not tell which page you were on.
 *
 * Legacy labels are kept verbatim ("My Classes", "Data Classes", "Trial
 * Student") so nobody has to relearn the menu — even where the wording is odd.
 */
final class Navigation
{
    /**
     * @return array<int, array{label: ?string, items: array<int, mixed>}>
     */
    public static function for(User $user): array
    {
        return match (true) {
            $user->isAdmin() => self::admin(),
            default => self::instructor(),
        };
    }

    /**
     * The legacy instructor sidebar, in its original order.
     *
     * @return array<int, array{label: ?string, items: array<int, mixed>}>
     */
    private static function instructor(): array
    {
        return [
            [
                'label' => null,
                'items' => [
                    self::item('Dashboard', 'instructor.dashboard', 'dashboard'),
                ],
            ],
            [
                'label' => 'Classes',
                'items' => [
                    self::item('My Classes', 'instructor.classes.index', 'calendar'),
                    self::item('Regular Classes', 'instructor.regular.index', 'clock'),
                    self::item('Data Classes', 'instructor.demo.index', 'video'),
                    self::item('Class History', 'instructor.history.index', 'book'),
                ],
            ],
            [
                'label' => 'Students',
                'items' => [
                    self::item('My Students', 'instructor.students.index', 'users'),
                    self::item('Enroll Student', 'instructor.students.create', 'user-plus'),
                    self::item('Trial Student', 'instructor.trials.index', 'users'),
                    self::item('Reports', 'instructor.reports.index', 'clipboard'),
                ],
            ],
            [
                'label' => 'Schedule',
                'items' => [
                    self::item('Teacher Schedule', 'instructor.schedule.index', 'calendar'),
                    self::item(
                        'Bookings',
                        'instructor.bookings.index',
                        'book-open',
                        badge: self::pendingBookingCount(),
                    ),
                ],
            ],
            [
                'label' => 'Resources',
                'items' => [
                    self::item('Learning Materials', 'instructor.materials.index', 'book-open'),
                ],
            ],
            [
                'label' => 'Account',
                'items' => [
                    self::item('My Earnings', 'instructor.earnings.index', 'wallet'),
                    self::item('Profile', 'instructor.profile.edit', 'user'),
                ],
            ],
        ];
    }

    /**
     * The legacy admin sidebar, in its original order.
     *
     * Four legacy student pages (Register Student, Register Trial Student, Users
     * Table, Student Sessions) were variants of one query, so they point at the
     * consolidated student list with a filter rather than four separate pages.
     *
     * @return array<int, array{label: ?string, items: array<int, mixed>}>
     */
    private static function admin(): array
    {
        return [
            [
                'label' => null,
                'items' => [
                    self::item('Home', 'admin.dashboard', 'dashboard'),
                ],
            ],
            [
                'label' => 'People',
                'items' => [
                    self::item('Instructors', 'admin.instructors.index', 'user-check'),
                    self::item('Users Table', 'admin.students.index', 'users'),
                    self::item(
                        'Pending Enrollments',
                        'admin.enrollments.index',
                        'user-plus',
                        badge: self::pendingEnrollmentCount(),
                    ),
                    self::item('Student Sessions', 'admin.students.index', 'refresh', query: ['filter' => 'no_sessions']),
                    self::item('Trial Students', 'admin.students.index', 'user-plus', query: ['filter' => 'unassigned']),
                ],
            ],
            [
                'label' => 'Classes',
                'items' => [
                    self::item('All Classes', 'admin.classes.index', 'calendar'),
                    self::item(
                        'Evaluation List',
                        'admin.evaluations.index',
                        'clipboard',
                        badge: self::pendingEvaluationCount(),
                    ),
                    self::item('Teacher Schedules', 'admin.schedules.index', 'clock'),
                    self::item('Bookings', 'admin.bookings.index', 'book-open'),
                ],
            ],
            [
                'label' => 'Payroll',
                'items' => [
                    self::item('Instructor Earn', 'admin.payouts.index', 'wallet'),
                ],
            ],
            [
                'label' => 'Resources',
                'items' => [
                    self::item('Learning Materials', 'admin.materials.index', 'book-open'),
                ],
            ],
            [
                'label' => 'System',
                'items' => [
                    self::item('Audit Log', 'admin.audit.index', 'database'),
                ],
            ],
        ];
    }

    /** Late-attendance requests waiting on a decision; an instructor is unpaid until then. */
    private static function pendingEvaluationCount(): int
    {
        if (! Schema::hasTable('attendance_requests')) {
            return 0;
        }

        return AttendanceRequest::query()->pending()->count();
    }

    /**
     * A single nav entry, or null when its route does not exist yet.
     *
     * Returning null rather than throwing lets the menu be declared ahead of the
     * pages being built; Navigation::prune() drops the gaps.
     *
     * @param  array<string, string>  $query
     */
    private static function item(
        string $label,
        string $route,
        string $icon,
        int $badge = 0,
        array $query = [],
    ): ?array {
        if (! Route::has($route)) {
            return null;
        }

        return [
            'label' => $label,
            'url' => route($route, $query),
            'icon' => $icon,
            'active' => self::isActive($route, $query),
            'badge' => $badge,
        ];
    }

    /**
     * Whether this entry is the page being viewed.
     *
     * Entries that differ only by query string (the student-list filters) must
     * compare that too, otherwise all of them would highlight at once.
     *
     * @param  array<string, string>  $query
     */
    private static function isActive(string $route, array $query): bool
    {
        if (! request()->routeIs($route) && ! request()->routeIs(self::wildcard($route))) {
            return false;
        }

        foreach ($query as $key => $value) {
            if ((string) request()->query($key) !== (string) $value) {
                return false;
            }
        }

        // A filtered entry must not also claim the unfiltered page.
        if ($query === []) {
            foreach (['filter', 'status'] as $key) {
                if (request()->filled($key) && request()->query($key) !== 'active') {
                    return false;
                }
            }
        }

        return true;
    }

    /** 'instructor.classes.index' => 'instructor.classes.*' */
    private static function wildcard(string $route): string
    {
        $parts = explode('.', $route);
        array_pop($parts);

        return implode('.', $parts).'.*';
    }

    private static function pendingEnrollmentCount(): int
    {
        return StudentProfile::query()
            ->where('enrollment_status', EnrollmentStatus::Pending)
            ->count();
    }

    /**
     * Trial requests the signed-in instructor has not answered yet.
     */
    private static function pendingBookingCount(): int
    {
        $user = auth()->user();

        if ($user === null) {
            return 0;
        }

        return Booking::query()
            ->where('instructor_id', $user->id)
            ->where('status', BookingStatus::Pending)
            ->count();
    }

    /**
     * Drop entries whose routes are not registered yet, and any group left empty
     * as a result.
     *
     * @param  array<int, mixed>  $groups
     * @return array<int, mixed>
     */
    public static function prune(array $groups): array
    {
        $pruned = [];

        foreach ($groups as $group) {
            $items = array_values(array_filter($group['items']));

            if ($items !== []) {
                $pruned[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        return $pruned;
    }
}
