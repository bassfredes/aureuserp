<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\Department;
use Webkul\Support\Models\Company;

class DepartmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Department::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'       => fake()->name,
            // manager_id is nullable; defaulting it to Employee::factory()
            // created an infinite factory cycle with EmployeeFactory's own
            // 'department_id' => Department::factory() default — never hit
            // before because nothing in the suite called either factory
            // with full defaults (#138 PR4 ola4B, discovered blocking Leave/
            // LeaveAllocation fixtures, unrelated to company-scope).
            'manager_id' => null,
            'company_id' => Company::factory(),
            'color'      => fake()->hexColor,
        ];
    }
}
