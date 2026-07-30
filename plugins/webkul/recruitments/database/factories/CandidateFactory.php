<?php

namespace Webkul\Recruitment\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * partner_id used to default to a random pre-existing (or freshly
 * factory-created) global Partner, independent of company_id — harmless
 * before Candidate's partner_id became model-managed (#138 PR4 A4E):
 * handlePartnerUpdation() unconditionally resyncs whatever Partner
 * partner_id points at to the Candidate's own company_id on every save,
 * so a pre-assigned cross-company Partner would have been silently
 * hijacked into this Candidate's company. Left unset instead — the
 * model's own handlePartnerCreation() always creates a fresh,
 * correctly-scoped Partner, matching the EmployeeFactory precedent of
 * never pre-assigning what the model itself is responsible for.
 * manager_id/employee_id default to null for the same reason
 * (#138 PR4 A4D's parent_id/coach_id precedent) — deferred to explicit
 * test/state construction rather than an independently-randomized
 * company.
 *
 * @extends Factory<Candidate>
 */
class CandidateFactory extends Factory
{
    protected $model = Candidate::class;

    public function definition(): array
    {
        return [
            'name'                 => fake()->name(),
            'email_from'           => fake()->safeEmail(),
            'phone'                => fake()->phoneNumber(),
            'priority'             => 0,
            'is_active'            => true,
            'message_bounced'      => false,
            'linkedin_profile'     => null,
            'email_cc'             => null,
            'availability_date'    => null,
            'candidate_properties' => null,

            // Relationships
            'company_id'  => Company::factory(),
            'partner_id'  => null,
            // Degree has no HasFactory of its own (dormant, pre-existing,
            // out of authorized scope to add) — DegreeFactory::new()
            // directly, not Degree::factory().
            'degree_id'   => DegreeFactory::new()->create()->id,
            'manager_id'  => null,
            'employee_id' => null,
            'creator_id'  => User::query()->value('id') ?? User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function bounced(): static
    {
        return $this->state(fn (array $attributes) => [
            'message_bounced' => true,
        ]);
    }

    public function withLinkedIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'linkedin_profile' => fake()->url(),
        ]);
    }

    public function withManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'manager_id' => User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $attributes['company_id']]))->id,
        ]);
    }

    public function withEmployee(): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => Employee::factory()->create(['company_id' => $attributes['company_id']])->id,
        ]);
    }

    public function withAvailability(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability_date' => fake()->date(),
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 3,
        ]);
    }
}
