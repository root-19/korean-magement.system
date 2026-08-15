<?php

use App\Http\Controllers\Admin\AttendanceRequestController as AdminEvaluations;
use App\Http\Controllers\Admin\ClassSessionController as AdminClassSessions;
use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Admin\EnrollmentController;
use App\Http\Controllers\Admin\InstructorController as AdminInstructors;
use App\Http\Controllers\Admin\LearningMaterialController as AdminMaterials;
use App\Http\Controllers\Admin\OverviewController as AdminOverview;
use App\Http\Controllers\Admin\PayoutController;
use App\Http\Controllers\Admin\StudentController as AdminStudents;
use App\Http\Controllers\Admin\StudentDeletionController as AdminDeletions;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Instructor\BookingController;
use App\Http\Controllers\Instructor\ClassListController;
use App\Http\Controllers\Instructor\ClassSessionController;
use App\Http\Controllers\Instructor\DashboardController as InstructorDashboard;
use App\Http\Controllers\Instructor\EarningsController;
use App\Http\Controllers\Instructor\HistoryController;
use App\Http\Controllers\Instructor\LearningMaterialController;
use App\Http\Controllers\Instructor\ProfileController;
use App\Http\Controllers\Instructor\ScheduleController;
use App\Http\Controllers\Instructor\SessionReportController;
use App\Http\Controllers\Instructor\StudentController;
use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The legacy app declared routes as a flat array of
| [handler, action, isProtected, requiredRole] tuples in routes/web.php, which
| router.php then interpreted — including running `require` on a view path built
| from the URL. Named routes and middleware groups replace that: role checks are
| declarative, and no request can reach the filesystem.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Public landing page: hero, sign-in, and the teacher schedules.
// Signed-in visitors are sent to their own dashboard instead.
Route::get('/', LandingController::class)->name('home');

/*
|--------------------------------------------------------------------------
| Instructor
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:instructor,admin'])
    ->prefix('instructor')
    ->name('instructor.')
    ->group(function () {
        Route::get('/dashboard', InstructorDashboard::class)->name('dashboard');

        // Classes / attendance
        Route::get('/classes', [ClassSessionController::class, 'index'])->name('classes.index');
        Route::post('/classes/attendance', [ClassSessionController::class, 'store'])->name('classes.attendance');
        Route::post('/classes/early', [ClassSessionController::class, 'storeEarly'])->name('classes.early');
        Route::delete('/classes/{session}', [ClassSessionController::class, 'destroy'])->name('classes.destroy');

        // Class lists — the legacy regular_classes / demo_classes /
        // irregular_students pages, which differed only by which students they
        // selected. Separate routes so the sidebar matches; one controller.
        Route::get('/regular-classes', [ClassListController::class, 'regular'])->name('regular.index');
        Route::get('/data-classes', [ClassListController::class, 'demo'])->name('demo.index');
        Route::get('/trial-students', [ClassListController::class, 'trials'])->name('trials.index');

        // Class history
        Route::get('/history', [HistoryController::class, 'index'])->name('history.index');

        // Students. `create` is declared before `{student}` so "create" is not
        // swallowed as a route parameter.
        Route::get('/students', [StudentController::class, 'index'])->name('students.index');
        Route::get('/students/create', [StudentController::class, 'create'])->name('students.create');
        Route::post('/students', [StudentController::class, 'store'])->name('students.store');
        Route::get('/students/{student}', [StudentController::class, 'show'])->name('students.show');

        // Asking an admin to delete a student. Never the deletion itself — that
        // lives behind the admin decision below.
        Route::post('/students/{student}/deletion', [StudentController::class, 'requestDeletion'])
            ->name('students.deletion');

        // Post-class reports (legacy: "feedback").
        //
        // `new` is declared before `{report}` so it is not swallowed as a route
        // parameter. `edit`/`update` are bound to the report rather than to
        // (student, date) because an instructor must still be able to open what
        // they wrote after the student was archived or reassigned.
        Route::get('/reports', [SessionReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/new', [SessionReportController::class, 'create'])->name('reports.create');
        Route::post('/reports', [SessionReportController::class, 'store'])->name('reports.store');
        Route::get('/reports/{report}/edit', [SessionReportController::class, 'edit'])->name('reports.edit');
        Route::put('/reports/{report}', [SessionReportController::class, 'update'])->name('reports.update');
        Route::get('/reports/{report}', [SessionReportController::class, 'show'])->name('reports.show');

        // Own weekly availability — what the public schedule table reads.
        Route::get('/teacher-schedule', [ScheduleController::class, 'index'])->name('schedule.index');
        Route::post('/teacher-schedule', [ScheduleController::class, 'store'])->name('schedule.store');
        Route::post('/teacher-schedule/copy', [ScheduleController::class, 'copyDay'])->name('schedule.copy');
        Route::patch('/teacher-schedule/{availability}', [ScheduleController::class, 'update'])
            ->name('schedule.update');
        Route::delete('/teacher-schedule/{availability}', [ScheduleController::class, 'destroy'])
            ->name('schedule.destroy');

        // Trial-class requests from the public profile
        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::patch('/bookings/{booking}', [BookingController::class, 'updateStatus'])->name('bookings.status');

        // Asking an admin to reopen a class that has already passed.
        Route::post('/classes/evaluation', [ClassSessionController::class, 'requestEvaluation'])
            ->name('classes.evaluation');

        // Learning materials published by an admin. The PDF is served by the
        // controller, not by the web server, so it stays behind auth.
        Route::get('/learning-materials', [LearningMaterialController::class, 'index'])
            ->name('materials.index');
        Route::get('/learning-materials/{material}/download', [LearningMaterialController::class, 'download'])
            ->name('materials.download');

        // Earnings
        Route::get('/earnings', [EarningsController::class, 'index'])->name('earnings.index');

        // Own profile
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    });

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
|
| Consolidated from the legacy admin views: the four student-list pages
| (student, user_table, free_students, re_enrolled) become one filtered list,
| and the three class pages (all_classes, data_classes, teacher-student) become
| one day view.
|
*/

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', AdminDashboard::class)->name('dashboard');

        // Instructors
        Route::get('/instructors', [AdminInstructors::class, 'index'])->name('instructors.index');
        Route::get('/instructors/{instructor}', [AdminInstructors::class, 'show'])->name('instructors.show');
        Route::patch('/instructors/{instructor}/status', [AdminInstructors::class, 'toggleStatus'])
            ->name('instructors.status');

        // Students.
        //
        // withTrashed on the bindings: an admin must be able to open a student who
        // has been archived or soft-deleted — that is precisely who they need to
        // look at when auditing an instructor's past earnings. Without it these
        // routes 404 on exactly those students.
        Route::get('/students', [AdminStudents::class, 'index'])->name('students.index');

        // `create` is declared before `{student}` so "create" is not swallowed
        // as a route parameter.
        Route::get('/students/create', [AdminStudents::class, 'create'])->name('students.create');
        Route::post('/students', [AdminStudents::class, 'store'])->name('students.store');

        Route::get('/students/{student}', [AdminStudents::class, 'show'])
            ->withTrashed()
            ->name('students.show');
        Route::get('/students/{student}/edit', [AdminStudents::class, 'edit'])
            ->withTrashed()
            ->name('students.edit');
        Route::patch('/students/{student}', [AdminStudents::class, 'update'])
            ->withTrashed()
            ->name('students.update');
        Route::patch('/students/{student}/instructor', [AdminStudents::class, 'reassign'])
            ->withTrashed()
            ->name('students.reassign');
        Route::patch('/students/{student}/status', [AdminStudents::class, 'toggleStatus'])
            ->withTrashed()
            ->name('students.status');

        // Enrolment approval queue
        Route::get('/enrollments', [EnrollmentController::class, 'index'])->name('enrollments.index');
        Route::patch('/enrollments/{enrollment}/approve', [EnrollmentController::class, 'approve'])
            ->name('enrollments.approve');
        Route::patch('/enrollments/{enrollment}/reject', [EnrollmentController::class, 'reject'])
            ->name('enrollments.reject');
        Route::patch('/enrollments/{enrollment}/reinstate', [EnrollmentController::class, 'reinstate'])
            ->name('enrollments.reinstate');

        // Deletion approval queue. Approving is what deletes the student, so it
        // is a decision here and nothing more than a request on the other side.
        Route::get('/student-deletions', [AdminDeletions::class, 'index'])->name('deletions.index');
        Route::patch('/student-deletions/{deletion}', [AdminDeletions::class, 'decide'])->name('deletions.decide');

        // Classes across all instructors
        Route::get('/classes', [AdminClassSessions::class, 'index'])->name('classes.index');

        // Evaluation queue for late attendance
        Route::get('/evaluations', [AdminEvaluations::class, 'index'])->name('evaluations.index');
        Route::patch('/evaluations/{evaluation}', [AdminEvaluations::class, 'decide'])->name('evaluations.decide');

        // Learning materials handed out to instructors
        Route::get('/materials', [AdminMaterials::class, 'index'])->name('materials.index');
        Route::post('/materials', [AdminMaterials::class, 'store'])->name('materials.store');
        Route::post('/material-folders', [AdminMaterials::class, 'storeFolder'])->name('materials.folders.store');
        Route::delete('/material-folders/{folder}', [AdminMaterials::class, 'destroyFolder'])
            ->name('materials.folders.destroy');
        Route::patch('/materials/{material}/published', [AdminMaterials::class, 'togglePublished'])
            ->name('materials.published');
        Route::delete('/materials/{material}', [AdminMaterials::class, 'destroy'])->name('materials.destroy');

        // Read-only overviews backing the remaining legacy sidebar entries
        Route::get('/teacher-schedules', [AdminOverview::class, 'schedules'])->name('schedules.index');
        Route::get('/bookings', [AdminOverview::class, 'bookings'])->name('bookings.index');
        Route::get('/audit-log', [AdminOverview::class, 'auditLog'])->name('audit.index');

        // Payroll
        Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
        Route::post('/payouts/week', [PayoutController::class, 'finaliseWeek'])->name('payouts.finalise-week');
        Route::post('/payouts/{instructor}', [PayoutController::class, 'finalise'])->name('payouts.finalise');
        Route::patch('/payouts/{payout}/paid', [PayoutController::class, 'markPaid'])->name('payouts.paid');
    });
