<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class EmployeeJobPositionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = EmployeeJobPosition::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort'               => fake()->randomNumber(),
            'name'               => fake()->word,
            'description'        => fake()->text,
            'requirements'       => fake()->text,
            'expected_employees' => fake()->randomNumber(),
            'no_of_employee'     => fake()->randomNumber(),
            // 'status' and 'open_date' are not real columns on
            // employees_job_positions (the actual flag is 'is_active') —
            // never hit before this factory's first real invocation (#138
            // PR4 ola4B, unrelated to company-scope).
            'is_active'          => true,
            'no_of_recruitment'  => fake()->randomNumber(),
            'company_id'         => Company::factory(),
            // Derived from the already-resolved company_id (see key order —
            // company_id above resolves first) rather than an independent
            // random Company, so a bare factory call satisfies the model's
            // own department_id/company_id match validation (#138 PR4 A4D,
            // employees family). Accepts an explicit override: a caller
            // passing a different-company Department is not corrected here
            // silently, the model itself rejects the mismatch.
            'department_id'      => fn (array $attributes) => Department::factory()->create(['company_id' => $attributes['company_id']])->id,
            'creator_id'         => User::query()->value('id') ?? User::factory(),
        ];
    }
}
