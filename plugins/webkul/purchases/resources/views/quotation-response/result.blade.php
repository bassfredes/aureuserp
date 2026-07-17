<?php
/**
 * @var int $status
 * @var string $outcome
 * @var string $action
 * @var \Webkul\Purchase\Models\Order|null $order
 * @var bool $idempotent
 */
$messageKey = match (true) {
    $idempotent ?? false => 'already-recorded',
    $outcome === 'accepted' => 'accepted',
    $outcome === 'declined' => 'declined',
    $outcome === 'conflict', $outcome === 'invalid_state', $outcome === 'invalid_partner', $outcome === 'invalid_action' => 'conflict',
    $outcome === 'link_invalid' => 'link-invalid',
    default => 'order-unavailable',
};
?>
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('purchases::app.quotation-response.title') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; max-width: 32rem; margin: 4rem auto; padding: 0 1rem; color: #1f2937; }
        .card { border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 2rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('purchases::app.quotation-response.title') }}</h1>
        <p>{{ __('purchases::app.quotation-response.messages.'.$messageKey) }}</p>
    </div>
</body>
</html>
