<?php

namespace Webkul\TimeOff\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Models\UserLeaveType;

/**
 * @extends Factory<UserLeaveType>
 */
class UserLeaveTypeFactory extends Factory
{
    protected $model = UserLeaveType::class;

    public function definition(): array
    {
        return [
            'leave_type_id' => LeaveType::factory(),
            // Resolved after 'leave_type_id' above (factory attributes are
            // evaluated in definition order) — the created User's
            // default_company_id always matches the LeaveType's company, or
            // the model's own write-authorization check would reject this
            // pairing outright (#138 PR4 ola4B).
            // withoutEvents() avoids User's own saved() hook, which spreads
            // every User attribute (including default_company_id) into a
            // Partner::create() call — partners_partners has no such
            // column and the insert fails; the same reason every other
            // fixture in this codebase creates Users via
            // User::withoutEvents(...), not plain User::factory()->create().
            'user_id'       => fn (array $attributes) => User::withoutEvents(fn () => User::factory()->create([
                'default_company_id' => LeaveType::withoutGlobalScope(CompanyScope::class)->find($attributes['leave_type_id'])?->company_id,
            ]))->id,
        ];
    }
}
