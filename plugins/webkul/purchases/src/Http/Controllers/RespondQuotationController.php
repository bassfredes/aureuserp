<?php

namespace Webkul\Purchase\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Services\QuotationResponseService;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * Reached only through a signed link mailed to a vendor
 * (Intelligent-Integration-Suite#138, PR 1) — there is no authenticated
 * actor. GET renders a confirmation page and never writes; the actual
 * accept/decline mutation only happens on POST, through
 * QuotationResponseService, inside one locked transaction.
 */
class RespondQuotationController extends Controller
{
    public function show(Request $request, string $order, string $action): View|Response
    {
        $orderModel = Order::withoutGlobalScope(CompanyScope::class)->find((int) $order);

        if (! $orderModel) {
            return response()->view('purchases::quotation-response.result', [
                'status'  => 404,
                'outcome' => 'order_unavailable',
                'action'  => $action,
                'order'   => null,
                'idempotent' => false,
            ], 404);
        }

        return view('purchases::quotation-response.confirm', [
            'order'  => $orderModel,
            'action' => $action,
        ]);
    }

    public function respond(Request $request, string $order, string $action, QuotationResponseService $service): Response
    {
        $result = $service->respond((int) $order, $action);

        return response()->view('purchases::quotation-response.result', [
            'status'     => $result->status,
            'outcome'    => $result->outcome,
            'action'     => $action,
            'order'      => $result->order,
            'idempotent' => $result->idempotent,
        ], $result->status);
    }
}
