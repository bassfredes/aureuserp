<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Enums\WorkLocation as WorkLocationEnum;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class WorkLocationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WorkLocation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // employees_work_locations has no user_id column, and its flag
            // is is_active, not active — plus fake()->word wasn't a valid
            // WorkLocation enum value. Never hit before this factory's
            // first real invocation (#138 PR4 ola4B, unrelated to
            // company-scope).
            'company_id'      => Company::factory(),
            'creator_id'      => User::query()->value('id') ?? User::factory(),
            'name'            => fake()->name,
            'location_type'   => fake()->randomElement(WorkLocationEnum::cases())->value,
            'location_number' => fake()->numberBetween(1, 100),
            'is_active'       => true,
        ];
    }
}
