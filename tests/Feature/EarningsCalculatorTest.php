<?php

namespace Tests\Feature;

use App\Enums\TeachingMethod;
use App\Models\ClassSession;
use App\Models\SessionReport;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Earnings\EarningsCalculator;
use App\Support\PayoutWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The payroll rules. Every assertion here mirrors behaviour the legacy Earnings
 * model had, because these numbers are what instructors are actually paid.
 */
class EarningsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private EarningsCalculator $calculator;

    private User $instructor;

    /** A Saturday — the first day of a payout week. */
    private const WEEK_START = '2025-08-02';

    private const WEEK_END = '2025-08-08';

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = app(EarningsCalculator::class);
        $this->instructor = User::factory()->instructor()->create();

        // Every date in these tests is well after the report requirement, so the
        // report gate is genuinely exercised rather than bypassed as historical.
        config()->set('academy.feedback_required_from', '2024-01-01');
        config()->set('academy.feedback_exempt_instructor_ids', []);
    }

    private function window(): PayoutWindow
    {
        return PayoutWindow::forDate(self::WEEK_START);
    }

    /**
     * A student of this instructor, on a given method and duration.
     */
    private function student(TeachingMethod $method = TeachingMethod::Audio, int $minutes = 25): User
    {
        $student = User::factory()->student()->create();

        StudentProfile::factory()
            ->method($method, $minutes)
            ->create([
                'user_id' => $student->id,
                'instructor_id' => $this->instructor->id,
            ]);

        return $student;
    }

    /**
     * A session plus the report that unlocks its payment.
     */
    private function reportedSession(User $student, string $date, string $state = 'present'): ClassSession
    {
        $session = ClassSession::factory()
            ->{$state}()
            ->create([
                'instructor_id' => $this->instructor->id,
                'student_id' => $student->id,
                'scheduled_date' => $date,
            ]);

        SessionReport::factory()->forSession($session)->create();

        return $session;
    }

    // ------------------------------------------------------------------- rates

    #[Test]
    public function it_prices_each_teaching_method_at_its_own_hourly_rate(): void
    {
        // 25 minutes at the configured hourly rate, prorated.
        $cases = [
            [TeachingMethod::Audio, 25, 79.17],        // 190 * 25/60
            [TeachingMethod::VideoKids, 25, 91.67],    // 220 * 25/60
            [TeachingMethod::VideoAdults, 25, 87.50],  // 210 * 25/60
            [TeachingMethod::Audio, 30, 95.00],        // 190 * 30/60
            [TeachingMethod::VideoKids, 60, 220.00],   // a full hour is the rate
        ];

        foreach ($cases as [$method, $minutes, $expected]) {
            $this->assertSame(
                $expected,
                $this->calculator->amountFor($method, $minutes),
                "{$method->value} for {$minutes} minutes"
            );
        }
    }

    #[Test]
    public function a_missing_teaching_method_bills_at_the_audio_rate(): void
    {
        // Deliberate: the legacy rate lookup fell through to audio for the rows
        // where this field was blank, and changing it would restate historic pay.
        $this->assertSame(79.17, $this->calculator->amountFor(null, 25));
    }

    // ------------------------------------------------------- what pays vs not

    #[Test]
    public function a_present_session_with_a_report_pays(): void
    {
        $student = $this->student(TeachingMethod::VideoKids, 25);
        $this->reportedSession($student, '2025-08-04');

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertSame(91.67, $summary->gross());
        $this->assertSame(1, $summary->sessionsPaid());
        $this->assertSame(91.67, $summary->net());
    }

    #[Test]
    public function a_student_absent_session_still_pays_the_instructor(): void
    {
        // The instructor showed up and waited, so the session is billable.
        $student = $this->student(TeachingMethod::Audio, 25);
        $this->reportedSession($student, '2025-08-04', 'studentAbsent');

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertSame(79.17, $summary->gross());
        $this->assertSame(1, $summary->sessionsPaid());
    }

    #[Test]
    public function a_teacher_absent_session_is_deducted_not_paid(): void
    {
        $student = $this->student(TeachingMethod::Audio, 25);

        ClassSession::factory()->teacherAbsent()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-04',
        ]);

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertSame(0.0, $summary->gross());
        $this->assertSame(79.17, $summary->deductions());
        $this->assertSame(-79.17, $summary->net());
        $this->assertSame(1, $summary->sessionsDeducted());
    }

    #[Test]
    public function a_teacher_absence_is_deducted_even_with_no_report_filed(): void
    {
        // An instructor must not be able to dodge a deduction by not filing.
        $student = $this->student();

        ClassSession::factory()->teacherAbsent()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-04',
        ]);

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertSame(79.17, $summary->deductions());
    }

    #[Test]
    public function a_postponed_session_neither_pays_nor_deducts(): void
    {
        $student = $this->student();

        ClassSession::factory()->postponed()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-04',
        ]);

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertTrue($summary->isEmpty());
        $this->assertSame(0.0, $summary->net());
    }

    #[Test]
    public function an_unmarked_session_does_not_pay(): void
    {
        $student = $this->student();

        ClassSession::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-04',
            'status' => null,
        ]);

        $this->assertSame(0.0, $this->calculator->forWindow($this->instructor->id, $this->window())->gross());
    }

    // ------------------------------------------------------- the report gate

    #[Test]
    public function a_present_session_without_a_report_does_not_pay(): void
    {
        $student = $this->student();

        ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-04',
        ]);

        $this->assertSame(0.0, $this->calculator->forWindow($this->instructor->id, $this->window())->gross());
    }

    #[Test]
    public function filing_the_report_releases_the_payment(): void
    {
        $student = $this->student(TeachingMethod::Audio, 25);

        $session = ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-04',
        ]);

        $this->assertSame(0.0, $this->calculator->forWindow($this->instructor->id, $this->window())->gross());

        SessionReport::factory()->forSession($session)->create();

        $this->assertSame(79.17, $this->calculator->forWindow($this->instructor->id, $this->window())->gross());
    }

    #[Test]
    public function sessions_predating_the_requirement_pay_without_a_report(): void
    {
        config()->set('academy.feedback_required_from', '2025-08-06');

        $student = $this->student(TeachingMethod::Audio, 25);

        // Before the cutoff — paid unconditionally.
        ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-04',
        ]);

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertSame(79.17, $summary->gross());
        $this->assertCount(1, $summary->historicalLines(), 'should be flagged as historical');
    }

    #[Test]
    public function an_exempt_instructor_is_paid_without_a_report(): void
    {
        config()->set('academy.feedback_exempt_instructor_ids', [$this->instructor->id]);

        $student = $this->student(TeachingMethod::Audio, 25);

        ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-04',
        ]);

        $this->assertSame(79.17, $this->calculator->forWindow($this->instructor->id, $this->window())->gross());
    }

    // ------------------------------------------------------- the early class

    #[Test]
    public function an_early_class_is_paid_in_the_week_it_was_taught(): void
    {
        $student = $this->student(TeachingMethod::Audio, 25);

        // Taught inside this week, covering a slot three weeks out.
        $session = ClassSession::factory()
            ->early(heldDate: '2025-08-04', scheduledDate: '2025-08-27')
            ->create([
                'instructor_id' => $this->instructor->id,
                'student_id' => $student->id,
            ]);

        // The database derives paid_date from held_date.
        $this->assertSame('2025-08-04', $session->refresh()->paid_date->toDateString());

        SessionReport::factory()->forSession($session)->create();

        $thisWeek = $this->calculator->forWindow($this->instructor->id, $this->window());
        $this->assertSame(79.17, $thisWeek->gross(), 'paid in the week it was taught');

        // NOT in the week of the slot it covers.
        $slotWeek = $this->calculator->forWindow($this->instructor->id, PayoutWindow::forDate('2025-08-27'));
        $this->assertSame(0.0, $slotWeek->gross(), 'must not also pay in the scheduled week');
    }

    #[Test]
    public function an_early_class_is_reported_against_the_date_it_was_taught(): void
    {
        $student = $this->student();

        $session = ClassSession::factory()
            ->early(heldDate: '2025-08-04', scheduledDate: '2025-08-27')
            ->create([
                'instructor_id' => $this->instructor->id,
                'student_id' => $student->id,
            ]);

        // A report filed against the SCHEDULED date must not unlock payment —
        // the earnings join matches on the paid date.
        SessionReport::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'class_date' => '2025-08-27',
        ]);

        $this->assertSame(
            0.0,
            $this->calculator->forWindow($this->instructor->id, $this->window())->gross(),
            'a report on the scheduled date should not release an early class'
        );

        SessionReport::factory()->forSession($session)->create();

        $this->assertSame(79.17, $this->calculator->forWindow($this->instructor->id, $this->window())->gross());
    }

    #[Test]
    public function a_double_class_on_one_day_pays_twice(): void
    {
        // A regular class plus an early one pulled onto the same day is two
        // pieces of work. They differ in scheduled_date, so both must pay —
        // this is the case the legacy GROUP BY was careful not to collapse.
        $student = $this->student(TeachingMethod::Audio, 25);

        $regular = ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-04',
        ]);

        $early = ClassSession::factory()
            ->early(heldDate: '2025-08-04', scheduledDate: '2025-08-27')
            ->create([
                'instructor_id' => $this->instructor->id,
                'student_id' => $student->id,
            ]);

        SessionReport::factory()->forSession($regular)->create();

        // Both rows share paid_date 2025-08-04, and the single report on that
        // date releases both — matching the legacy natural-key join.
        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertSame(2, $summary->sessionsPaid(), 'both classes count');
        $this->assertSame(158.34, $summary->gross());
    }

    // ------------------------------------------------------------- boundaries

    #[Test]
    public function it_includes_both_edges_of_the_payout_week(): void
    {
        $student = $this->student(TeachingMethod::Audio, 25);

        foreach ([self::WEEK_START, self::WEEK_END] as $date) {
            $this->reportedSession($student, $date);
        }

        // Just outside, on either side.
        foreach (['2025-08-01', '2025-08-09'] as $date) {
            $this->reportedSession($student, $date);
        }

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertSame(2, $summary->sessionsPaid(), 'only Saturday and Friday fall inside');
        $this->assertSame(158.34, $summary->gross());
    }

    #[Test]
    public function it_never_counts_another_instructors_sessions(): void
    {
        $other = User::factory()->instructor()->create();
        $theirStudent = User::factory()->student()->create();

        StudentProfile::factory()->create([
            'user_id' => $theirStudent->id,
            'instructor_id' => $other->id,
            'teaching_method' => TeachingMethod::Audio,
            'learning_time' => 25,
        ]);

        $session = ClassSession::factory()->present()->create([
            'instructor_id' => $other->id,
            'student_id' => $theirStudent->id,
            'scheduled_date' => '2025-08-04',
        ]);
        SessionReport::factory()->forSession($session)->create();

        $this->assertSame(0.0, $this->calculator->forWindow($this->instructor->id, $this->window())->gross());
        $this->assertSame(79.17, $this->calculator->forWindow($other->id, $this->window())->gross());
    }

    // -------------------------------------------------------- deleted students

    #[Test]
    public function archiving_a_student_preserves_the_instructors_earnings(): void
    {
        // This is the whole reason the legacy schema grew snapshot columns,
        // negative ids and six backup tables. Soft deletes make it fall out.
        $student = $this->student(TeachingMethod::VideoAdults, 30);
        $this->reportedSession($student, '2025-08-04');

        $before = $this->calculator->forWindow($this->instructor->id, $this->window())->gross();
        $this->assertSame(105.00, $before); // 210 * 30/60

        $student->delete();

        $this->assertSoftDeleted('users', ['id' => $student->id]);

        $after = $this->calculator->forWindow($this->instructor->id, $this->window())->gross();

        $this->assertSame($before, $after, 'deleting a student must not change past pay');
    }

    // ---------------------------------------------------------------- roll-ups

    #[Test]
    public function it_splits_gross_by_teaching_method(): void
    {
        $audio = $this->student(TeachingMethod::Audio, 25);
        $kids = $this->student(TeachingMethod::VideoKids, 25);

        $this->reportedSession($audio, '2025-08-04');
        $this->reportedSession($kids, '2025-08-05');

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());
        $byMethod = $summary->grossByMethod();

        $this->assertSame(79.17, $byMethod['audio']);
        $this->assertSame(91.67, $byMethod['video_kids']);
        $this->assertSame(0.0, $byMethod['video_adults']);

        $this->assertSame(1, $summary->audioSessions());
        $this->assertSame(1, $summary->videoSessions());
        $this->assertSame(170.84, $summary->gross());
    }

    #[Test]
    public function it_rolls_up_per_student_with_deductions_signed(): void
    {
        $student = $this->student(TeachingMethod::Audio, 25);

        $this->reportedSession($student, '2025-08-04');                    // +79.17
        $this->reportedSession($student, '2025-08-05', 'studentAbsent');   // +79.17

        ClassSession::factory()->teacherAbsent()->create([                 // -79.17
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2025-08-06',
        ]);

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());
        $row = $summary->byStudent()->firstWhere('student_id', $student->id);

        $this->assertSame(1, $row['present']);
        $this->assertSame(2, $row['absent'], 'student-absent and teacher-absent are both absences');
        $this->assertSame(79.17, $row['amount'], 'two payable minus one deducted');
        $this->assertSame(79.17, $summary->net());
    }
}
