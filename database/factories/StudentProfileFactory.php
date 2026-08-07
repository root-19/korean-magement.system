<?php

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Enums\TeachingMethod;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentProfile>
 */
class StudentProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'instructor_id' => User::factory()->instructor(),
            'teaching_method' => fake()->randomElement(TeachingMethod::cases()),
            'learning_time' => fake()->randomElement([20, 25, 30]),
            'sessions_remaining' => fake()->numberBetween(0, 30),
            'sessions_attended' => fake()->numberBetween(0, 40),
            'sessions_deducted' => 0,
            'is_regular' => true,
            'enrollment_status' => EnrollmentStatus::Approved,
            'start_date' => now()->subMonths(2)->toDateString(),
        ];
    }

    public function method(TeachingMethod $method, int $minutes = 25): static
    {
        return $this->state(fn () => [
            'teaching_method' => $method,
            'learning_time' => $minutes,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['enrollment_status' => EnrollmentStatus::Pending]);
    }

    public function forInstructor(User $instructor): static
    {
        return $this->state(fn () => ['instructor_id' => $instructor->id]);
    }
}
