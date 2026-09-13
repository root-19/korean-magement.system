<?php

namespace Tests\Feature;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Models\SessionReport;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Earnings\EarningsCalculator;
use App\Support\PayoutWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What one teacher absence costs, on both sides of it.
 *
 * The rule has two halves and they are owned by two different services, so
 * each half is covered by its own unit tests — AttendanceServiceTest for the
 * student's counters, EarningsCalculatorTest for the payslip. Nothing pinned
 * them TOGETHER, which is how the rule is actually stated and paid:
 *
 *   the student gets the class back, and the instructor loses exactly what
 *   that class would have paid them.
 *
 * "Exactly" is the part worth a test of its own. The deduction is priced by the
 * same rate * minutes / 60 as the payment, so an absence can never cost the
 * instructor more or less than the session was worth, whatever the method.
 */
class TeacherAbsenceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $attendance;

    private EarningsCalculator $earnings;

    private User $instructor;

    /** A Saturday — the first day of a payout week. */
    private const WEEK_START = '2025-08-02';

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendance = app(AttendanceService::class);
        $this->earnings = app(EarningsCalculator::class);
        $this->instructor = User::factory()->instructor()->create();

        config()->set('academy.feedback_required_from', '2024-01-01');
        config()->set('academy.feedback_exempt_instructor_ids', []);
    }

    private function student(TeachingMethod $method, int $minutes): User
    {
        $student = User::factory()->student()->create();

        StudentProfile::factory()
            ->method($method, $minutes)
            ->create([
                'user_id' => $student->id,
                'instructor_id' => $this->instructor->id,
                'sessions_remaining' => 10,
                'sessions_attended' => 0,
                'sessions_deducted' => 0,
            ]);

        return $student;
    }

    private function summary(): \App\Services\Earnings\EarningsSummary
    {
        return $this->earnings->forWindow($this->instructor->id, PayoutWindow::forDate(self::WEEK_START));
    }

    #[Test]
    public function a_teacher_absence_gives_the_student_a_class_and_takes_the_pay_back(): void
    {
        $student = $this->student(TeachingMethod::Audio, 25);

        // One class taught and reported, so there is pay to take back from.
        $taught = $this->attendance->mark($this->instructor, $student, '2025-08-04', SessionStatus::Present);
        SessionReport::factory()->forSession($taught)->create();

        $this->attendance->mark(
            $this->instructor,
            $student,
            '2025-08-05',
            SessionStatus::Absent,
            Party::Teacher,
        );

        $profile = StudentProfile::where('user_id', $student->id)->firstOrFail();

        // 10 prepaid - 1 taught + 1 returned for the class the teacher missed.
        $this->assertSame(10, $profile->sessions_remaining);
        $this->assertSame(1, $profile->sessions_attended);

        $summary = $this->summary();

        // 190/hr * 25min: paid once for the class taught, taken back once for
        // the class that was not.
        $this->assertSame(79.17, $summary->gross());
        $this->assertSame(79.17, $summary->deductions());
        $this->assertSame(0.0, $summary->net());
        $this->assertSame(1, $summary->sessionsPaid());
        $this->assertSame(1, $summary->sessionsDeducted());
    }

    #[Test]
    public function the_deduction_is_priced_at_the_students_own_rate(): void
    {
        // Whatever the method and duration, one absence costs one session's pay
        // — not a flat fee, and not the rate of some other student.
        $cases = [
            [TeachingMethod::Audio, 25],
            [TeachingMethod::VideoKids, 50],
            [TeachingMethod::VideoAdults, 30],
        ];

        foreach ($cases as [$method, $minutes]) {
            $student = $this->student($method, $minutes);

            $this->attendance->mark(
                $this->instructor,
                $student,
                '2025-08-06',
                SessionStatus::Absent,
                Party::Teacher,
            );

            $line = $this->summary()
                ->deductionLines()
                ->firstWhere('studentId', $student->id);

            $this->assertNotNull($line, "{$method->value} {$minutes}min produced no deduction");
            $this->assertSame(
                $this->earnings->amountFor($method, $minutes),
                $line->amount,
                "{$method->value} {$minutes}min is not deducted at its own rate"
            );
        }
    }

    #[Test]
    public function correcting_the_absence_returns_the_class_and_restores_the_pay(): void
    {
        // The instructor turned up after all, or the wrong slot was marked.
        // Both halves have to unwind, or the student keeps a class nobody owes
        // them and the instructor stays docked for a class they taught.
        $student = $this->student(TeachingMethod::Audio, 25);

        $this->attendance->mark(
            $this->instructor,
            $student,
            '2025-08-04',
            SessionStatus::Absent,
            Party::Teacher,
        );

        $this->assertSame(79.17, $this->summary()->deductions());

        $corrected = $this->attendance->mark($this->instructor, $student, '2025-08-04', SessionStatus::Present);
        SessionReport::factory()->forSession($corrected)->create();

        $profile = StudentProfile::where('user_id', $student->id)->firstOrFail();

        // 10 prepaid, one of them now taught. The credit is gone with it.
        $this->assertSame(9, $profile->sessions_remaining);

        $summary = $this->summary();

        $this->assertSame(0.0, $summary->deductions());
        $this->assertSame(79.17, $summary->net());
    }
}
