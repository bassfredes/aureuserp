<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('purchases::app.quotation-response.title') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; max-width: 32rem; margin: 4rem auto; padding: 0 1rem; color: #1f2937; }
        .card { border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 2rem; }
        button { font: inherit; padding: 0.6rem 1.5rem; border-radius: 0.375rem; border: 0; cursor: pointer; color: #fff; }
        .accept { background: #16a34a; }
        .decline { background: #dc2626; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('purchases::app.quotation-response.confirm.heading') }}</h1>
        <p>
            {{ __('purchases::app.quotation-response.confirm.body', [
                'order'  => $order->name,
                'vendor' => $order->partner?->name,
            ]) }}
        </p>
        <form method="POST" action="{{ url()->full() }}">
            @csrf
            <input type="hidden" name="expires" value="{{ request()->query('expires') }}">
            <input type="hidden" name="signature" value="{{ request()->query('signature') }}">
            <button type="submit" class="{{ $action === 'accept' ? 'accept' : 'decline' }}">
                {{ $action === 'accept'
                    ? __('purchases::app.quotation-response.confirm.accept-button')
                    : __('purchases::app.quotation-response.confirm.decline-button') }}
            </button>
        </form>
    </div>
</body>
</html>
