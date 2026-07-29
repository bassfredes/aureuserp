<?php

namespace Webkul\Employee\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\DepartureReason;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\State;

class EmployeeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id'                     => Company::factory(),
            // Tenant-aware defaults derive from the Employee's own
            // company_id (already resolved by the time these closures run —
            // Eloquent factories resolve definition() attributes in array
            // order, merging each into $attributes before the next one
            // runs) rather than each nested factory's own independent
            // random Company: the model now validates that department_id/
            // job_id/work_location_id belong to the same company as the
            // Employee, and that user_id/attendance_manager_id reference a
            // User enabled for that company (#138 PR4 A4D, employees
            // family). employees_employees.user_id also has a UNIQUE
            // constraint, hence a fresh User per Employee rather than
            // reusing an existing one. Wrapped in User::withoutEvents():
            // User::handlePartnerCreation() spreads the model's own
            // toArray() into Partner::create() (a pre-existing bug,
            // partners_partners has no default_company_id column) —
            // every other User::factory()->create() call in this codebase
            // already avoids it the same way, this is not a new pattern.
            'user_id'                        => fn (array $attributes) => User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $attributes['company_id']]))->id,
            'creator_id'                     => User::query()->value('id') ?? User::factory(),
            'calendar_id'                    => null,
            'department_id'                  => fn (array $attributes) => Department::factory()->create(['company_id' => $attributes['company_id']])->id,
            'attendance_manager_id'          => fn (array $attributes) => User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $attributes['company_id']]))->id,
            'job_id'                         => fn (array $attributes) => EmployeeJobPosition::factory()->create(['company_id' => $attributes['company_id'], 'department_id' => $attributes['department_id']])->id,
            'partner_id'                     => null,
            'work_location_id'               => fn (array $attributes) => WorkLocation::factory()->create(['company_id' => $attributes['company_id']])->id,
            // parent_id/coach_id are self-relations to Employee, not User —
            // the previous defaults mistakenly assigned User ids to an
            // Employee FK. Nullable by design; hierarchical relations are
            // built explicitly in tests/states that need them, to avoid an
            // infinite factory cycle (same reasoning already documented on
            // Department.manager_id's own factory below).
            'parent_id'                      => null,
            'coach_id'                       => null,
            'country_id'                     => Country::factory(),
            'private_state_id'               => State::factory(),
            'private_country_id'             => Country::factory(),
            'country_of_birth'               => Country::factory(),
            'bank_account_id'                => null,
            'departure_reason_id'            => DepartureReason::factory(),
            'name'                           => fake()->name,
            'job_title'                      => fake()->jobTitle,
            'work_phone'                     => fake()->phoneNumber,
            'mobile_phone'                   => fake()->phoneNumber,
            'color'                          => fake()->safeColorName,
            'work_email'                     => fake()->unique()->safeEmail,
            'children'                       => fake()->numberBetween(0, 5),
            'distance_home_work'             => fake()->numberBetween(5, 100),
            'km_home_work'                   => fake()->numberBetween(5, 100),
            'distance_home_work_unit'        => fake()->randomElement(['km', 'miles']),
            'private_street1'                => fake()->streetAddress,
            'private_street2'                => fake()->secondaryAddress,
            'private_city'                   => fake()->city,
            'private_zip'                    => fake()->postcode,
            'private_phone'                  => fake()->phoneNumber,
            'private_email'                  => fake()->unique()->safeEmail,
            'lang'                           => fake()->languageCode,
            'gender'                         => fake()->randomElement(),
            'birthday'                       => fake()->date(),
            'marital'                        => fake()->randomElement(['single', 'married', 'divorced', 'widowed']),
            'spouse_complete_name'           => fake()->name,
            'spouse_birthdate'               => fake()->date(),
            'place_of_birth'                 => fake()->city,
            'ssnid'                          => fake()->uuid,
            'sinid'                          => fake()->uuid,
            'identification_id'              => fake()->uuid,
            'passport_id'                    => fake()->uuid,
            'permit_no'                      => fake()->uuid,
            'visa_no'                        => fake()->uuid,
            'certificate'                    => fake()->word,
            'study_field'                    => fake()->word,
            'study_school'                   => fake()->company,
            'emergency_contact'              => fake()->name,
            'emergency_phone'                => fake()->phoneNumber,
            'employee_type'                  => fake()->randomElement(['full-time', 'part-time', 'contractor']),
            'barcode'                        => fake()->ean13,
            'pin'                            => fake()->randomNumber(6, true),
            'private_car_plate'              => fake()->bothify('??-###-##'),
            'visa_expire'                    => fake()->date(),
            'work_permit_expiration_date'    => fake()->date(),
            'departure_date'                 => fake()->optional()->date(),
            'departure_description'          => fake()->optional()->text,
            'additional_note'                => fake()->optional()->text,
            'notes'                          => fake()->optional()->text,
            'is_active'                      => fake()->boolean(),
            'is_flexible'                    => fake()->boolean(),
            'is_fully_flexible'              => fake()->boolean(),
            'work_permit_scheduled_activity' => fake()->boolean(),
        ];
    }
}
