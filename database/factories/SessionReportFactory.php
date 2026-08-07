<?php

namespace Database\Factories;

use App\Models\ClassSession;
use App\Models\SessionReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionReport>
 */
class SessionReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'instructor_id' => User::factory()->instructor(),
            'student_id' => User::factory()->student(),
            'class_date' => now()->toDateString(),
            'today_lesson' => fake()->sentence(),
            'next_lesson' => fake()->sentence(),
            'listening_score' => fake()->numberBetween(5, 10),
            'speaking_score' => fake()->numberBetween(5, 10),
        ];
    }

    /**
     * A report filed for a specific session.
     *
     * class_date is set to the session's paid_date — the date the class was
     * actually taught — which is what the earnings calculation matches on.
     *
     * paid_date is a STORED generated column, so a freshly created model has no
     * value for it until it is read back; hence the refresh() fallback.
     */
    public function forSession(ClassSession $session): static
    {
        $paidDate = $session->paid_date ?? $session->refresh()->paid_date;

        return $this->state(fn () => [
            'class_session_id' => $session->id,
            'instructor_id' => $session->instructor_id,
            'student_id' => $session->student_id,
            'class_date' => $paidDate->toDateString(),
        ]);
    }
}
