<?php

namespace Webkul\Sale\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Sale\Models\Team;
use Webkul\Sale\Models\TeamMember;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * user_id used to reuse whatever user happened to exist first,
 * independent of the Team's own company — incompatible with the
 * membership check added in #138 PR4 A4G. Derived from the Team's
 * resolved company_id instead, matching the recruitments pivot factories
 * (#138 PR4 A4E): no bypass inside the factory itself, only correlated
 * attributes. team_id stays declared first on purpose, since
 * Factory::expandAttributes() resolves in array order and passes the
 * already-resolved value forward.
 *
 * @extends Factory<TeamMember>
 */
class TeamMemberFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = TeamMember::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => fn (array $attributes) => User::withoutEvents(fn () => User::factory()->create([
                'default_company_id' => Team::withoutGlobalScope(CompanyScope::class)->withTrashed()->find($attributes['team_id'])?->company_id,
            ]))->id,
        ];
    }
}
