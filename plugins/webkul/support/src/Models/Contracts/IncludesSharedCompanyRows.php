<?php

namespace Webkul\Support\Models\Contracts;

/**
 * Opts a HasCompanyScope model into company_or_shared visibility: rows with
 * company_id IS NULL are treated as system-managed, shared references
 * visible to every authenticated user with at least one allowed company
 * (never to a user with zero companies — CompanyScope's fail-closed branch
 * still applies first). Models without this contract keep CompanyScope's
 * default strict_company behavior: company_id IN (allowed companies) only.
 *
 * Implementing this is not enough on its own — see ADR 0007. A model with
 * shared rows must also guard writes so company_id IS NULL rows cannot be
 * created, updated, or deleted through normal business flows.
 */
interface IncludesSharedCompanyRows {}
