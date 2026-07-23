<?php

namespace Webkul\Security\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Security\Models\Invitation;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    public function definition(): array
    {
        return [
            'email'      => fake()->safeEmail(),
            'company_id' => Company::factory(),
            // Role (Spatie) has no factory of its own — callers that need
            // a specific role pass 'role_id' explicitly (e.g. via
            // Role::findOrCreate()).
            'role_id'    => null,
            'token'      => fake()->uuid(),
            'expires_at' => now()->addDays(7),
            // withoutEvents() avoids User's own saved() hook, which spreads
            // every User attribute into a Partner::create() call —
            // partners_partners lacks several User-only columns and the
            // insert fails otherwise (same reason every other fixture in
            // this codebase creates Users this way, not a plain
            // User::factory()->create()).
            'invited_by' => User::query()->value('id') ?? User::withoutEvents(fn () => User::factory()->create())->id,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
