<?php

namespace Webkul\Support\ValueObjects;

use Webkul\Support\Enums\CompanyContextMode;

/**
 * Immutable snapshot of one active CompanyContext::runFor*() call (ADR
 * 0007). Only CompanyContext constructs and stores this — no public
 * mutator, so a caller holding a reference (e.g. via current()) cannot
 * reach back in and change the active context out from under the scope
 * that owns it.
 */
final readonly class CompanyContextState
{
    public function __construct(
        public CompanyContextMode $mode,
        public ?int $companyId,
        public string $reason,
        public string $caller,
        public string $correlationId,
    ) {}
}
