<?php

namespace Database\Factories;

use App\Enums\TeachingMethod;
use App\Models\PayoutAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutAdjustment>
 */
class PayoutAdjustmentFactory extends Factory
{
    protected $model = PayoutAdjustment::class;

    public function definition(): array
    {
        return [
            'instructor_id' => User::factory()->instructor(),
            'student_id' => User::factory()->student(),
            'student_name' => $this->faker->name(),
            'paid_date' => $this->faker->date(),
            'teaching_method' => TeachingMethod::Audio,
            'learning_time' => 25,
            'amount' => 79.17,
            'note' => 'Manual adjustment',
            'created_by' => User::factory()->admin(),
        ];
    }
}
