<?php

namespace Tests\Feature;

use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Enrollment\StudentEnroller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The read-only counter audit — see AuditSessionCounters.
 *
 * It exists because `sessions_remaining` cannot be recomputed from the counters
 * alone: the purchased total is derived back out of them, so a wrong remaining
 * takes the apparent purchase down with it and the identity still balances. The
 * `student.enrolled` audit entry is the only independent record of the purchase,
 * and these tests pin both what that lets the report claim and what it refuses
 * to claim without it.
 *
 * Assertions read the command's real output rather than going through
 * expectsOutputToContain: the findings are rendered as tables, and the mocked
 * console output the test helpers install does not capture those.
 */
class SessionCounterAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->instructor = User::factory()->instructor()->create(['name' => 'Teacher Ana']);
    }

    /**
     * Run the audit and hand back its exit code and full output.
     *
     * @param  array<string, mixed>  $options
     * @return array{code: int, output: string}
     */
    private function audit(array $options = []): array
    {
        $this->withoutMockingConsoleOutput();

        $code = Artisan::call('sessions:audit-counters', $options);

        return ['code' => $code, 'output' => Artisan::output()];
    }

    /**
     * Enrol through the real service so the purchase anchor is the real shape.
     */
    private function enrol(string $name, int $purchased, int $deducted = 0, ?User $instructor = null): User
    {
        return app(StudentEnroller::class)->enrol(
            $this->admin,
            [
                'name' => $name,
                'sessions_purchased' => $purchased,
                'sessions_deducted' => $deducted,
                'learning_time' => 25,
            ],
            $instructor ?? $this->instructor,
        )['student'];
    }

    private function profile(User $student): StudentProfile
    {
        return StudentProfile::query()->where('user_id', $student->id)->firstOrFail();
    }

    #[Test]
    public function it_reports_a_student_whose_remaining_was_double_deducted(): void
    {
        $student = $this->enrol('Mina Short', 10);

        app(AttendanceService::class)->mark($this->instructor, $student, '2025-08-04', SessionStatus::Present);
        app(AttendanceService::class)->mark($this->instructor, $student, '2025-08-11', SessionStatus::Present);

        // What the lost absent_by in mark() used to leave behind: one class taken
        // off remaining twice.
        $this->profile($student)->update(['sessions_remaining' => 7]);

        $result = $this->audit();

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('SHORT on remaining sessions', $result['output']);
        $this->assertStringContainsString('Mina Short', $result['output']);
        $this->assertStringContainsString('+1', $result['output']);
        $this->assertStringContainsString('enrolled with 10', $result['output']);
    }

    #[Test]
    public function a_student_absence_counts_as_a_consumed_session(): void
    {
        $student = $this->enrol('Bea Absent', 10);

        // Consumes a session without being attendance, so it belongs in the
        // expected remaining but not in the expected attended.
        app(AttendanceService::class)->mark(
            $this->instructor,
            $student,
            '2025-08-04',
            SessionStatus::Absent,
            Party::Student,
        );

        $this->assertSame(9, $this->profile($student)->sessions_remaining);

        $result = $this->audit();

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Every checkable counter agrees', $result['output']);
    }

    #[Test]
    public function a_postponement_leaves_the_expected_count_alone(): void
    {
        $student = $this->enrol('Cara Moved', 10);

        app(AttendanceService::class)->postpone(
            $this->instructor,
            $student,
            '2025-08-04',
            Party::Student,
            rescheduledDate: '2025-08-06',
        );

        $result = $this->audit();

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Every checkable counter agrees', $result['output']);
    }

    #[Test]
    public function the_write_off_at_enrolment_is_not_counted_twice(): void
    {
        // 12 bought, 2 written off, 1 taught: 9 left.
        $student = $this->enrol('Dina Deducted', 12, 2);

        app(AttendanceService::class)->mark($this->instructor, $student, '2025-08-04', SessionStatus::Present);

        $this->assertSame(9, $this->profile($student)->sessions_remaining);

        $result = $this->audit();

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Every checkable counter agrees', $result['output']);
    }

    #[Test]
    public function it_will_not_guess_for_a_student_with_no_enrolment_record(): void
    {
        // The legacy import brought counters across but no purchase history, so
        // there is nothing to derive `remaining` from. Reporting these as correct
        // would be a lie either way.
        $student = User::factory()->student()->create(['name' => 'Elle Legacy']);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'sessions_remaining' => 5,
            'sessions_attended' => 0,
            'sessions_deducted' => 0,
        ]);

        $quiet = $this->audit();

        $this->assertSame(0, $quiet['code']);
        $this->assertStringContainsString('not checked for `remaining`', $quiet['output']);
        $this->assertStringNotContainsString('Elle Legacy', $quiet['output']);

        $listed = $this->audit(['--show-unverifiable' => true]);

        $this->assertStringContainsString('Elle Legacy', $listed['output']);
        $this->assertStringContainsString('no enrolment record', $listed['output']);
    }

    #[Test]
    public function it_defers_to_an_admins_manual_correction(): void
    {
        $student = $this->enrol('Fay Corrected', 10);

        // An admin has since set the counters by hand. That figure is the
        // intended truth, so the enrolment anchor no longer applies and
        // re-deriving over it would report their correction as the error.
        AuditLog::record(
            action: 'student.updated',
            subject: $student,
            targetName: $student->name,
            details: ['sessions_remaining' => ['from' => 10, 'to' => 4]],
            userId: $this->admin->id,
        );

        $this->profile($student)->update(['sessions_remaining' => 4]);

        $result = $this->audit(['--show-unverifiable' => true]);

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Fay Corrected', $result['output']);
        $this->assertStringContainsString('counters edited', $result['output']);
    }

    #[Test]
    public function a_re_enrolment_replaces_a_superseded_anchor(): void
    {
        $student = $this->enrol('Gene Topped Up', 10);

        AuditLog::record(
            action: 'student.updated',
            subject: $student,
            details: ['sessions_remaining' => ['from' => 10, 'to' => 2]],
            userId: $this->admin->id,
        );

        // A fresh purchase is a new anchor, and it clears the edit made against
        // the old one — otherwise a student stays unverifiable for good after a
        // single hand correction.
        AuditLog::record(
            action: 'student.enrolled',
            subject: $student,
            details: ['sessions_purchased' => 20, 'sessions_deducted' => 0],
            userId: $this->admin->id,
        );

        $this->profile($student)->update(['sessions_remaining' => 20]);

        $result = $this->audit();

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('Every checkable counter agrees', $result['output']);
    }

    #[Test]
    public function an_attended_count_that_drifted_is_reported_on_its_own(): void
    {
        $student = $this->enrol('Gina Attended', 10);

        app(AttendanceService::class)->mark($this->instructor, $student, '2025-08-04', SessionStatus::Present);

        // Remaining is right, attended is not — the case that can be fixed in the
        // sheet, so it is reported apart from the ones that cannot.
        $this->profile($student)->update(['sessions_attended' => 4]);

        $result = $this->audit();

        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('right remaining count but a wrong attended count', $result['output']);
        $this->assertStringContainsString('Gina Attended', $result['output']);
    }

    #[Test]
    public function it_can_be_narrowed_to_one_instructor(): void
    {
        $other = User::factory()->instructor()->create(['name' => 'Teacher Bo']);

        $mine = $this->enrol('Hana Mine', 10);
        $this->profile($mine)->update(['sessions_remaining' => 8]);

        $theirs = $this->enrol('Iris Theirs', 10, 0, $other);
        $this->profile($theirs)->update(['sessions_remaining' => 3]);

        $result = $this->audit(['--instructor' => 'Teacher Bo']);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Iris Theirs', $result['output']);
        $this->assertStringNotContainsString('Hana Mine', $result['output']);
    }

    #[Test]
    public function it_writes_the_full_list_to_a_csv(): void
    {
        $student = $this->enrol('Jill Csv', 10);
        $this->profile($student)->update(['sessions_remaining' => 6]);

        $path = storage_path('app/counter-audit-test.csv');
        @unlink($path);

        $result = $this->audit(['--csv' => $path]);

        $this->assertSame(1, $result['code']);
        $this->assertFileExists($path);

        $csv = (string) file_get_contents($path);

        $this->assertStringContainsString('remaining_expected', $csv);
        // 10 bought, none taught, 6 stored: 4 owed back.
        $this->assertStringContainsString('Jill Csv', $csv);
        $this->assertStringContainsString(',6,10,4,', $csv);

        @unlink($path);
    }
}
