<?php

declare(strict_types=1);

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
 * Currently EMPTY, and that is the intended steady state. Its only entry
 * ever was Webkul\Security\Models\Invitation (added 2026-08-03, #138 PR4),
 * retired on 2026-08-07 by #264: the risk was resolved rather than renewed
 * — Invitation now uses HasCompanyScope, and the guest accept route
 * resolves its single row through an explicit, documented
 * withoutGlobalScope(CompanyScope::class) whose authorization is the
 * signed URL's token, not company membership. With no real gaps left, an
 * empty registry is what "nothing is being deferred" looks like; a new
 * entry here is a deliberate, owner-level decision to defer an isolation
 * gap, never a way to quiet the auditor.
 *
 * @return array<class-string, array{table: string, tracking: string, justification: string, owner: string, review_by: string}>
 */
return [];
