<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\SessionStatus;
use App\Enums\TeachingMethod;
use App\Models\ClassSession;
use App\Models\SessionReport;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Printing a payslip.
 *
 * The page prints itself rather than rendering a separate print view, so the
 * figures on paper cannot drift from the figures on screen. What paper needs
 * instead is the context the app chrome carries — whose payslip, which week —
 * because the sidebar and header are hidden by the print stylesheet.
 */
class EarningsPrintTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    /** A Saturday: the first day of a payout week. */
    private string $weekStart = '2026-07-25';

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->instructor()->create(['name' => 'A01 - Riza']);

        $student = User::factory()->student()->create(['name' => 'A723 Juni']);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            // Pinned: the factory randomises the method, and the method sets the
            // rate this test asserts on.
            'teaching_method' => TeachingMethod::Audio,
            'learning_time' => 25,
        ]);

        // One paid session with its report filed, so the week has real figures.
        $session = ClassSession::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'scheduled_date' => '2026-07-27',
            'status' => SessionStatus::Present,
        ]);

        SessionReport::create([
            'class_session_id' => $session->id,
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
            'class_date' => '2026-07-27',
            'today_lesson' => 'Story 8',
        ]);
    }

    private function payslip(): string
    {
        return $this->actingAs($this->instructor)
            ->get(route('instructor.earnings.index', ['week' => $this->weekStart]))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function the_payslip_offers_a_print_control(): void
    {
        $html = $this->payslip();

        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringContainsString('Print', $html);
    }

    #[Test]
    public function the_printed_sheet_names_the_instructor_and_the_week(): void
    {
        $html = $this->payslip();

        // Inside the print-only block, which the stylesheet reveals on paper.
        $start = strpos($html, 'print-only');
        $this->assertNotFalse($start, 'the payslip needs a print-only header');

        $header = substr($html, $start, 1200);

        $this->assertStringContainsString('Payslip', $header);
        $this->assertStringContainsString('A01 - Riza', $header);
        $this->assertStringContainsString('Jul 25', $header, 'the payout week has to be on the sheet');
    }

    #[Test]
    public function the_printed_sheet_carries_the_net_figure_and_says_it_is_not_final(): void
    {
        $html = $this->payslip();

        // 190/h audio × 25 minutes = 79.17, shown whole.
        $this->assertStringContainsString('₱79', $html);
        $this->assertStringContainsString('Net payable', $html);

        // No payout row for this week yet, so the sheet must not read as final.
        $this->assertStringContainsString('Not yet finalised', $html);
    }

    #[Test]
    public function the_app_chrome_is_marked_so_it_does_not_print(): void
    {
        $html = $this->payslip();

        // The sidebar, the sticky header and the toast tray are all chrome; the
        // print stylesheet hides everything carrying this class.
        $this->assertSame(
            4,
            substr_count($html, 'no-print'),
            'sidebar, its mobile overlay, the header and the toast tray',
        );
    }

    #[Test]
    public function a_week_with_no_earnings_still_prints(): void
    {
        ClassSession::query()->delete();

        $html = $this->payslip();

        $this->assertStringContainsString('₱0', $html);
        $this->assertStringContainsString('A01 - Riza', $html);
    }
}
