<?php

namespace Tests\Feature;

use App\Enums\PayoutStatus;
use App\Enums\TeachingMethod;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\Payout;
use App\Models\PayoutAdjustment;
use App\Models\SessionReport;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Earnings\EarningsCalculator;
use App\Services\Earnings\PayoutService;
use App\Support\PayoutWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A paid class credited by hand, without touching the student's sessions.
 */
class PayoutAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private PayoutService $payouts;

    private EarningsCalculator $calculator;

    private User $admin;

    private User $instructor;

    private User $student;

    private const WEEK_START = '2025-08-02';

    protected function setUp(): void
    {
        parent::setUp();

        $this->payouts = app(PayoutService::class);
        $this->calculator = app(EarningsCalculator::class);
        $this->admin = User::factory()->admin()->create();
        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create();

        StudentProfile::factory()->method(TeachingMethod::VideoKids, 20)->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
        ]);

        config()->set('academy.feedback_required_from', '2024-01-01');
        config()->set('academy.feedback_exempt_instructor_ids', []);
    }

    private function window(): PayoutWindow
    {
        return PayoutWindow::forDate(self::WEEK_START);
    }

    private function adjust(string $date = '2025-08-05'): PayoutAdjustment
    {
        return $this->payouts->addAdjustment(
            admin: $this->admin,
            instructor: $this->instructor,
            student: $this->student,
            paidDate: CarbonImmutable::parse($date),
            note: 'Paid class lost during cleanup',
        );
    }

    #[Test]
    public function it_pays_one_class_at_the_students_rate_without_a_session(): void
    {
        $adjustment = $this->adjust();

        // 220 * 20/60, frozen on the row.
        $this->assertSame('73.33', $adjustment->amount);
        $this->assertSame(TeachingMethod::VideoKids, $adjustment->teaching_method);
        $this->assertSame(0, ClassSession::count());

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertSame(73.33, $summary->gross());
        $this->assertSame(1, $summary->sessionsPaid());
        $this->assertSame(0, $summary->sessionsDeducted());

        $line = $summary->lines->first();
        $this->assertTrue($line->isAdjustment());
        $this->assertSame('Paid class lost during cleanup', $line->adjustmentNote);
        $this->assertSame($this->student->name, $line->studentName);
    }

    #[Test]
    public function it_sits_alongside_real_sessions_in_the_same_week(): void
    {
        $session = ClassSession::factory()->present()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => '2025-08-04',
        ]);
        SessionReport::factory()->forSession($session)->create();

        $this->adjust('2025-08-05');

        $summary = $this->calculator->forWindow($this->instructor->id, $this->window());

        $this->assertSame(2, $summary->sessionsPaid());
        $this->assertSame(146.66, $summary->gross());
        $this->assertSame(146.66, $summary->grossByMethod()[TeachingMethod::VideoKids->value]);
    }

    #[Test]
    public function it_lands_in_the_week_of_its_paid_date_only(): void
    {
        $this->adjust('2025-08-05');

        $nextWeek = $this->calculator->forWindow($this->instructor->id, $this->window()->next());

        $this->assertTrue($nextWeek->isEmpty());
    }

    #[Test]
    public function it_is_frozen_into_the_finalised_payslip(): void
    {
        $this->adjust();

        $payout = $this->payouts->finalise($this->admin, $this->instructor, $this->window());

        $this->assertSame('73.33', $payout->gross_earnings);
        $this->assertSame('73.33', $payout->video_kids_earnings);
        $this->assertSame(1, $payout->sessions_paid);
    }

    #[Test]
    public function it_is_audited(): void
    {
        $adjustment = $this->adjust();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payout.adjusted',
            'auditable_id' => $adjustment->id,
            'user_id' => $this->admin->id,
        ]);

        $this->assertSame(
            'Paid class lost during cleanup',
            AuditLog::where('action', 'payout.adjusted')->first()->details['note']
        );
    }

    #[Test]
    public function it_refuses_a_week_already_marked_paid(): void
    {
        Payout::create([
            'instructor_id' => $this->instructor->id,
            'week_start' => self::WEEK_START,
            'week_end' => '2025-08-08',
            'status' => PayoutStatus::Paid,
        ]);

        $this->expectException(ValidationException::class);

        $this->adjust();
    }

    #[Test]
    public function it_can_be_removed_while_the_week_is_open(): void
    {
        $adjustment = $this->adjust();

        $this->payouts->removeAdjustment($this->admin, $adjustment);

        $this->assertDatabaseMissing('payout_adjustments', ['id' => $adjustment->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout.adjustment_removed']);
        $this->assertTrue($this->calculator->forWindow($this->instructor->id, $this->window())->isEmpty());
    }

    #[Test]
    public function an_admin_can_add_one_through_the_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.payouts.adjustments.create', ['instructor' => $this->instructor, 'week' => self::WEEK_START]))
            ->assertOk()
            ->assertSee($this->student->name);

        $this->actingAs($this->admin)
            ->post(route('admin.payouts.adjustments.store', $this->instructor), [
                'student_id' => $this->student->id,
                'paid_date' => '2025-08-05',
                'note' => 'Paid class lost during cleanup',
            ])
            ->assertRedirect(route('admin.payouts.index', ['week' => self::WEEK_START]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payout_adjustments', [
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'amount' => 73.33,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.payouts.index', ['week' => self::WEEK_START]))
            ->assertOk()
            ->assertSee($this->instructor->name);
    }

    #[Test]
    public function the_instructor_sees_it_on_their_payslip(): void
    {
        $this->adjust();

        $this->actingAs($this->instructor)
            ->get(route('instructor.earnings.index', ['week' => self::WEEK_START]))
            ->assertOk()
            ->assertSee('Manual adjustment')
            ->assertSee('Paid class lost during cleanup');
    }
}
