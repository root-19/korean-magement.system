<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreInstructorRequest;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\InstructorProfile;
use App\Models\User;
use App\Services\Earnings\EarningsCalculator;
use App\Support\PayoutWindow;
use App\Support\WeeklyScheduleGrid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Instructor roster and detail. Replaces admin/instructor.php and
 * admin/instructor_table.php.
 */
class InstructorController extends Controller
{
    public function __construct(private readonly EarningsCalculator $earnings) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('viewData'));
        $window = PayoutWindow::current();

        $instructors = User::query()
            ->instructors()
            ->with('instructorProfile:id,user_id,bank_name')
            ->withCount([
                'students as student_count' => fn ($q) => $q
                    ->where('enrollment_status', EnrollmentStatus::Approved)
                    ->whereHas('user', fn ($q) => $q->where('is_active', true)),
            ])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when(
                $request->query('status') === 'inactive',
                fn ($q) => $q->where('is_active', false),
                fn ($q) => $q->when($request->query('status') !== 'all', fn ($q) => $q->where('is_active', true)),
            )
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // One query for the week's session counts, rather than one per row.
        $sessionCounts = ClassSession::query()
            ->selectRaw('instructor_id, COUNT(*) as total')
            ->whereBetween('paid_date', [$window->startDate(), $window->endDate()])
            ->whereNotNull('status')
            ->whereIn('instructor_id', $instructors->pluck('id'))
            ->groupBy('instructor_id')
            ->pluck('total', 'instructor_id');

        return view('admin.instructors.index', [
            'instructors' => $instructors,
            'sessionCounts' => $sessionCounts,
            'search' => $search,
            'window' => $window,
            'statusFilter' => $request->query('status', 'active'),
        ]);
    }

    public function create(): View
    {
        return view('admin.instructors.create');
    }

    /**
     * Create an instructor account and profile in one transaction.
     *
     * Instructors rarely start with bank details on hand, so those are
     * optional here — the same fields are editable later from their profile.
     */
    public function store(StoreInstructorRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $password = trim((string) ($data['password'] ?? '')) !== ''
            ? $data['password']
            : Str::upper(Str::random(8));

        $instructor = DB::transaction(function () use ($request, $data, $password) {
            $email = trim((string) ($data['email'] ?? ''));

            $instructor = User::create([
                'name' => $data['name'],
                'email' => $email !== '' ? $email : null,
                'password' => $password,
                'role' => Role::Instructor,
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
            ]);

            InstructorProfile::create([
                'user_id' => $instructor->id,
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account' => $data['bank_account'] ?? null,
            ]);

            AuditLog::record(
                action: 'instructor.created',
                subject: $instructor,
                targetName: $instructor->name,
                userId: $request->user()->id,
            );

            return $instructor;
        });

        return redirect()
            ->route('admin.instructors.show', $instructor)
            ->with('success', sprintf(
                '%s added. Temporary password: %s',
                $instructor->name,
                $password,
            ));
    }

    public function show(Request $request, User $instructor): View
    {
        abort_unless($instructor->role === Role::Instructor, 404);

        $instructor->load(['instructorProfile', 'availabilities']);

        $window = $request->filled('week')
            ? PayoutWindow::forDate($request->query('week'))
            : PayoutWindow::current();

        return view('admin.instructors.show', [
            'instructor' => $instructor,
            'window' => $window,
            'windows' => PayoutWindow::current()->recent(12),
            'summary' => $this->earnings->forWindow($instructor->id, $window),
            'grid' => WeeklyScheduleGrid::forInstructor($instructor),
            'days' => WeeklyScheduleGrid::days(),
            'students' => $instructor->students()
                ->with('user:id,name,avatar_path,is_active')
                ->approved()
                ->get(),
            'payouts' => $instructor->payouts()->orderByDesc('week_start')->limit(10)->get(),
        ]);
    }

    /**
     * Activate or deactivate an instructor.
     *
     * Replaces AdminController::toggleInstructorStatus. A deactivated account is
     * signed out on its next request by EnsureUserHasRole.
     */
    public function toggleStatus(Request $request, User $instructor): RedirectResponse
    {
        abort_unless($instructor->role === Role::Instructor, 404);

        // An admin locking themselves out of their own account is never intended.
        abort_if($instructor->id === $request->user()->id, 403, 'You cannot deactivate your own account.');

        $instructor->update(['is_active' => ! $instructor->is_active]);

        AuditLog::record(
            action: $instructor->is_active ? 'instructor.activated' : 'instructor.deactivated',
            subject: $instructor,
            targetName: $instructor->name,
            userId: $request->user()->id,
        );

        return back()->with(
            'success',
            "{$instructor->name} is now ".($instructor->is_active ? 'active' : 'inactive').'.'
        );
    }
}
