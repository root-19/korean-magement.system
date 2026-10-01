<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\LearningMaterial;
use App\Models\StudentProfile;
use App\Services\Students\ClassProgress;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The student's home page: their class counts, timetable, recent classes and
 * the learning materials the academy has published.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ClassProgress $progress) {}

    public function __invoke(Request $request): View
    {
        $student = $request->user();

        $profile = StudentProfile::query()
            ->with('instructor:id,name')
            ->where('user_id', $student->id)
            ->first();

        return view('student.dashboard', [
            'student' => $student,
            'profile' => $profile,
            'progress' => $profile ? $this->progress->for($profile) : null,
            'schedules' => $student->schedules()->orderBy('day_of_week')->get(),
            'recent' => ClassSession::query()
                ->with('instructor:id,name')
                ->where('student_id', $student->id)
                ->whereNotNull('status')
                ->latest('scheduled_date')
                ->limit(10)
                ->get(),
            'materials' => LearningMaterial::query()
                ->published()
                ->with('folder:id,name')
                ->latest('published_at')
                ->latest('id')
                ->get()
                ->groupBy(fn (LearningMaterial $m) => $m->folder?->name ?? 'Uncategorised'),
        ]);
    }
}
