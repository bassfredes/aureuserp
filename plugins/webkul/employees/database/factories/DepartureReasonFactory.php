<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\DepartureReason;

class DepartureReasonFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = DepartureReason::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // employees_departure_reasons has 'sort' and an INTEGER
            // 'reason_code', not 'sequence' — never hit before this
            // factory's first real invocation (#138 PR4 ola4B, unrelated
            // to company-scope).
            'sort'        => fake()->randomNumber(),
            'reason_code' => fake()->randomNumber(),
            'name'        => fake()->word,
        ];
    }
}
