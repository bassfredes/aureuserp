<?php

declare(strict_types=1);

use Webkul\Security\Models\Invitation;

/**
 * Registry of TEMPORARILY accepted company-scope gaps — deliberately
 * separate from config/company-scope-exceptions.php (see that file's
 * docblock: mixing a real, unresolved gap into ExceptionManifest would
 * misrepresent it as actual isolation evidence rather than a time-boxed
 * risk decision). This is App\Support\CompanyScopeAudit\
 * AcceptedRiskRegistry's backing config file (#138 PR4, Codex
 * adversarial-review recommendation, 2026-08-03).
 *
 * An entry here changes ONLY the exit policy of
 * `php scripts/audit-company-scope.php --fail-on-unapproved-gaps`:
 *   - `--fail-on-missing` stays a strict, zero-gap certification and is
 *     entirely unaffected by this file — it still exits 1 for ANY real
 *     gap, registered here or not.
 *   - a registered, non-expired entry only changes
 *     `--fail-on-unapproved-gaps`'s exit code; the row is never
 *     reclassified to `classified_exception` and is never hidden from
 *     the audit's table/JSON output. It is always visible, annotated
 *     with `accepted_risk_status`.
 *   - `--fail-on-unapproved-gaps` exits 1 for a real gap unless its entry
 *     here exists (keyed by the model's exact FQCN), its `table` field
 *     exactly matches the model's actual table right now, and its
 *     `review_by` date has not yet passed.
 *
 * Every entry is validated on every run, with the same discipline as
 * ExceptionManifest — a malformed entry fails the audit loudly (exit 2):
 *   - `table`/`tracking`/`justification`/`owner`/`review_by` must be
 *     non-empty strings;
 *   - `review_by` must be a well-formed `Y-m-d` date;
 *   - the class must exist, be autoloadable, and be a concrete Eloquent
 *     model;
 *   - `table` must match the model's actual table right now.
 *
 * Review-by cadence: no existing project convention for a security-debt
 * review interval was found (checked AGENTS.md and docs/security/*.md
 * for precedent). Six months from the date an entry is added/renewed is
 * used as a deliberately short default, so an accepted risk cannot
 * silently coast for years next to a `--fail-on-unapproved-gaps` CI gate
 * — each renewal is meant to be a conscious re-review, never an
 * automatic extension.
 *
 * @return array<class-string, array{table: string, tracking: string, justification: string, owner: string, review_by: string}>
 */
return [

    Invitation::class => [
        'table'         => 'user_invitations',
        'tracking'      => '#138 PR4 (PR #18); IDOR fix commit a32f381f0',
        'justification' => 'Company-scope enforcement (HasCompanyScope) remains deliberately unimplemented on Invitation — has_company_id=true, uses_company_scope=false is a conscious design choice, not an omission (see docs/security/company-scope-pr4-inventory.md and company-scope-pr4-wave-4d-plan.md, "Security\\Invitation" entries). No admin listing/enumeration surface exists: InvitationResource (Http/Resources/V1/InvitationResource.php) is confirmed dead code, never referenced by any route or controller — the security routes only register login/logout via AuthController. The only real reader is the guest-only, signed-URL AcceptInvitation Livewire flow (routes/web.php), which cannot enumerate: it resolves exactly one invitation from its own signed link, never a listing. The bespoke write-side guard already in place on Invitation::boot() (assertCanWriteCompany() on creating/updating, company_id immutable after creation except by an authorized authenticated actor, fail-closed with no actor/CompanyContext outside the guest accept flow) is adequate for what surface actually exists today. This session (2026-08-03) additionally investigated and fixed a real, separate IDOR in AcceptInvitation.php (a Livewire public-property retargeting gap from a missing #[Locked], plus a missing per-token re-validation on the mutating create() call) in commit a32f381f0 — that fix closes the actual exploitable vulnerability. This registry entry accepts only the narrower, already-scoped risk that Invitation still has no HasCompanyScope enforcement of its own; it is not a substitute for that fix and does not change the model\'s company-scope classification (still real_gap_company_column in the auditor).',
        'owner'         => 'Bastian Fredes (#138 / #81 PR4 owner)',
        'review_by'     => '2027-02-03',
    ],

];
