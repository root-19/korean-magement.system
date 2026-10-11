<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\Enrollment\StudentEnroller;
use Illuminate\Console\Command;

/**
 * Give existing students the academy's default password so they can sign in
 * with their name, as newly enrolled students already can.
 *
 * Dry-run unless --apply is passed: this overwrites password hashes, which
 * cannot be undone. Students who have already signed in on this site are left
 * alone unless --include-logged-in is given, so nobody loses a password they
 * chose themselves.
 */
class ResetStudentPasswords extends Command
{
    protected $signature = 'students:reset-passwords
                            {--apply : Write the change; without it only reports what would happen}
                            {--include-logged-in : Also reset students who have signed in before}';

    protected $description = 'Set the default password on existing student accounts';

    public function handle(): int
    {
        $students = User::query()
            ->where('role', Role::Student)
            ->when(! $this->option('include-logged-in'), fn ($q) => $q->whereNull('last_login_at'))
            ->get(['id', 'name', 'is_active', 'last_login_at']);

        // A name shared by two accounts cannot be used to sign in at all, so a
        // password reset will not help them — list them so they can be renamed.
        $shared = User::query()
            ->whereIn('name', $students->pluck('name'))
            ->get(['id', 'name'])
            ->groupBy('name')
            ->filter(fn ($group) => $group->count() > 1);

        $this->info($students->count().' student(s) '.($this->option('apply') ? 'will be reset' : 'would be reset').'.');

        if ($shared->isNotEmpty()) {
            $this->warn($shared->count().' name(s) are shared by several accounts and still cannot sign in by name:');
            $shared->each(fn ($group, $name) => $this->line("  {$name} (ids ".$group->pluck('id')->implode(', ').')'));
        }

        if (! $this->option('apply')) {
            $this->comment('Dry run. Re-run with --apply to write the change.');

            return self::SUCCESS;
        }

        // Each save hashes through the model's `hashed` cast.
        $students->each(fn (User $student) => $student->forceFill([
            'password' => StudentEnroller::DEFAULT_PASSWORD,
        ])->saveQuietly());

        $this->info('Done.');

        return self::SUCCESS;
    }
}
