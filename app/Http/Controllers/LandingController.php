<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Http\Middleware\RedirectToRoleHome;
use App\Models\User;
use App\Support\WeeklyScheduleGrid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The public landing page — the legacy public/index.php, plus the weekly
 * schedule table that used to live only on public/instructor_profile.php.
 *
 * Signed-in visitors are sent to their own dashboard rather than shown the
 * marketing page.
 */
class LandingController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->to(RedirectToRoleHome::homeFor($request->user()));
        }

        $instructors = $this->bookableInstructors();
        $selected = $this->selectedInstructor($instructors, $request->query('instructor'));

        return view('landing', [
            'instructors' => $instructors,
            'selected' => $selected,
            'grid' => $selected ? WeeklyScheduleGrid::forInstructor($selected) : null,
            'days' => WeeklyScheduleGrid::days(),
        ]);
    }

    /**
     * Instructors worth showing a visitor: active, and with something to show —
     * either published availability or students already on the books.
     *
     * Both halves of that OR matter. Only one instructor in the imported data
     * has published availability, and they happen to have no students yet;
     * filtering on students alone would hide the single teacher whose schedule
     * is real, leaving every grid on the page derived from the fallback.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function bookableInstructors(): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->instructors()
            ->active()
            // Eager-loaded because the grid reads them, and lazy loading is an
            // error in local (see AppServiceProvider).
            ->with(['availabilities', 'instructorProfile:id,user_id,bio'])
            ->withCount([
                'availabilities',
                'students as active_student_count' => fn ($q) => $q
                    ->where('enrollment_status', EnrollmentStatus::Approved)
                    ->whereHas('user', fn ($q) => $q->where('is_active', true)),
            ])
            ->havingRaw('availabilities_count > 0 OR active_student_count > 0')
            // Teachers who published real hours lead, so the grid a visitor sees
            // first is one they can actually book against. Alphabetical after
            // that, as the legacy list was.
            ->orderByDesc('availabilities_count')
            ->orderBy('name')
            ->get();
    }

    /**
     * The instructor whose week is on screen: the one asked for, else the first.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $instructors
     */
    private function selectedInstructor(Collection $instructors, mixed $requested): ?User
    {
        if ($requested !== null && $requested !== '') {
            $match = $instructors->firstWhere('id', (int) $requested);

            if ($match !== null) {
                return $match;
            }
        }

        return $instructors->first();
    }
}
