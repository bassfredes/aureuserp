<?php

namespace Webkul\TimeOff\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\LeaveMandatoryDay;

/**
 * @extends Factory<\App\Models\LeaveMandatoryDay>
 */
class LeaveMandatoryDayFactory extends Factory
{
    protected $model = LeaveMandatoryDay::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('now', '+30 days');
        // '+7 days' is relative to *now*, not $startDate — when $startDate
        // itself landed past now+7 days (most of the time, since it's
        // drawn from a 30-day window), the range was inverted and Faker
        // threw. Never hit before this factory's first real invocation
        // (#138 PR4 ola4B, unrelated to company-scope).
        $endDate = fake()->dateTimeBetween($startDate, (clone $startDate)->modify('+7 days'));

        return [
            'company_id' => Company::factory(),
            'creator_id' => User::query()->value('id') ?? User::factory(),
            'color'      => fake()->numberBetween(1, 10),
            'name'       => fake()->words(3, true),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date'   => $endDate->format('Y-m-d'),
        ];
    }
}
