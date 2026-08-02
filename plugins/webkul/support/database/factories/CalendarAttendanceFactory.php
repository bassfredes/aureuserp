<?php

namespace Webkul\Support\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;

class CalendarAttendanceFactory extends Factory
{
    protected $model = CalendarAttendance::class;

    public function definition(): array
    {
        return [
            'sort'              => fake()->randomNumber(),
            'name'              => fake()->word,
            'day_of_week'       => fake()->randomElement(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
            'day_period'        => fake()->randomElement(['morning', 'afternoon', 'evening']),
            'week_type'         => fake()->randomElement(['odd', 'even', 'both']),
            // NULL or one of the CalendarDisplayType enum values — the
            // model rejects anything else (#138 A4I). daily/weekly/monthly
            // were never valid values for this column.
            'display_type'      => fake()->randomElement([null, 'working', 'off', 'holiday']),
            'date_from'         => fake()->date(),
            'date_to'           => fake()->date(),
            'hour_from'         => fake()->time(),
            'hour_to'           => fake()->time(),
            'duration_days'     => fake()->randomNumber(),
            'calendar_id'       => Calendar::factory(),
            'creator_id'        => User::query()->value('id') ?? User::factory(),
        ];
    }
}
