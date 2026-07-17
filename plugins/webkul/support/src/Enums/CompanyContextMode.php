<?php

namespace Webkul\Support\Enums;

/**
 * Which system context CompanyScope::apply() honors for a no-authenticated-
 * user request (ADR 0007, "Contextos de sistema sin actor autenticado").
 * Each case has its own precondition and audit level enforced by
 * CompanyContext — this enum only names the three modes, it carries no
 * behavior itself.
 */
enum CompanyContextMode: string
{
    case COMPANY = 'company';
    case ALL_COMPANIES = 'all_companies';
    case BOOTSTRAP = 'bootstrap';
}
