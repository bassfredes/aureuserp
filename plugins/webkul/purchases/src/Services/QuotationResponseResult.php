<?php

namespace Webkul\Purchase\Services;

use Webkul\Purchase\Models\Order;

final class QuotationResponseResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $outcome,
        public readonly ?Order $order = null,
        public readonly bool $idempotent = false,
    ) {}
}
