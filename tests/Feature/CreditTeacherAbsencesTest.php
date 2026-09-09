<?php

namespace Tests\Feature;

use App\Console\Commands\CreditTeacherAbsences;
use App\Enums\EnrollmentStatus;
use App\Enums\Party;
use App\Enums\SessionStatus;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Backfilling the make-good class for teacher absences marked before the rule
 * was restored.
 *
 * Re-marking cannot find these: the counters move by the DELTA of a status
 * change, so a row that is already absent-by-teacher stays uncredited however
 * many times it is saved.
 */
class CreditTeacherAbsencesTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->instructor()->create();
        $this->student = User::factory()->student()->create(['name' => 'A100 Owed One']);

        StudentProfile::factory()->create([
            'user_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'sessions_remaining' => 4,
        ]);
    }

    private function absence(string $date): ClassSession
    {
        return ClassSession::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => $date,
            'status' => SessionStatus::Absent,
            'absent_by' => Party::Teacher,
            'marked_by' => $this->instructor->id,
            'marked_at' => now(),
        ]);
    }

    private function remaining(): int
    {
        return (int) StudentProfile::where('user_id', $this->student->id)->value('sessions_remaining');
    }

    #[Test]
    public function the_dry_run_writes_nothing(): void
    {
        $this->absence('2026-08-13');

        $this->artisan('sessions:credit-teacher-absences')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(4, $this->remaining());
        $this->assertSame(0, AuditLog::where('action', CreditTeacherAbsences::AUDIT_ACTION)->count());
    }

    #[Test]
    public function applying_credits_one_class_per_absence(): void
    {
        $this->absence('2026-08-13');
        $this->absence('2026-08-20');

        $this->artisan('sessions:credit-teacher-absences --apply')->assertSuccessful();

        $this->assertSame(6, $this->remaining());
    }

    #[Test]
    public function a_second_run_does_not_credit_the_same_student_twice(): void
    {
        $this->absence('2026-08-13');

        $this->artisan('sessions:credit-teacher-absences --apply')->assertSuccessful();
        $this->assertSame(5, $this->remaining());

        $this->artisan('sessions:credit-teacher-absences --apply')
            ->expectsOutputToContain('Every teacher absence')
            ->assertSuccessful();

        $this->assertSame(5, $this->remaining());
    }

    #[Test]
    public function the_credit_is_recorded_with_the_dates_it_paid_for(): void
    {
        $this->absence('2026-08-13');

        $this->artisan('sessions:credit-teacher-absences --apply')->assertSuccessful();

        $entry = AuditLog::where('action', CreditTeacherAbsences::AUDIT_ACTION)->sole();

        $this->assertSame($this->student->id, (int) $entry->auditable_id);
        $this->assertSame(1, $entry->details['credited']);
        $this->assertSame(4, $entry->details['sessions_remaining_before']);
        $this->assertSame(5, $entry->details['sessions_remaining_after']);
        $this->assertSame(['2026-08-13'], $entry->details['absence_dates']);
    }

    #[Test]
    public function an_archived_student_is_left_alone_unless_asked_for(): void
    {
        // Most of the backlog is theirs: the absences are months old and those
        // students have since finished and been archived. Crediting a leaver
        // puts a balance on someone who is not coming back.
        $this->absence('2026-08-13');
        $this->student->delete();

        $this->artisan('sessions:credit-teacher-absences --apply')
            ->expectsOutputToContain('Every teacher absence')
            ->assertSuccessful();

        $this->assertSame(4, $this->remaining());

        $this->artisan('sessions:credit-teacher-absences --apply --include-archived')->assertSuccessful();

        $this->assertSame(5, $this->remaining());
    }

    #[Test]
    public function a_student_absence_is_not_credited(): void
    {
        // Only the teacher not turning up earns the make-good class.
        ClassSession::create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'scheduled_date' => '2026-08-13',
            'status' => SessionStatus::Absent,
            'absent_by' => Party::Student,
            'marked_by' => $this->instructor->id,
            'marked_at' => now(),
        ]);

        $this->artisan('sessions:credit-teacher-absences --apply')
            ->expectsOutputToContain('Every teacher absence')
            ->assertSuccessful();

        $this->assertSame(4, $this->remaining());
    }
}
