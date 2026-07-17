<?php

namespace Webkul\Purchase\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Purchase\Enums\OrderState;
use Webkul\Purchase\Models\Order;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * Applies a vendor's accept/decline response to an RFQ, reached only
 * through a signed link (Intelligent-Integration-Suite#138, PR 1). The
 * signature itself is the capability — it authorizes exactly the order
 * id and action baked into it, nothing more — so this service does a
 * single-record lookup bypassing CompanyScope only for that exact key,
 * never a list or a client-suppplied filter, and never
 * forAllCompanies() (reserved for an authenticated super_admin bypass).
 *
 * Every mutation runs inside one transaction with the order row locked,
 * so two concurrent responses (e.g. a double-click, or a mail client
 * prefetching the link twice) can't race each other into an
 * inconsistent state.
 */
class QuotationResponseService
{
    public function respond(int $orderId, string $action): QuotationResponseResult
    {
        // The route constraint already limits {action} to accept|decline,
        // but this service is the actual authorization boundary — a
        // future caller reaching it directly (another controller, a
        // command, a test) must not be able to smuggle an unknown action
        // through as an implicit decline. Validate before the lookup or
        // any write (#138 audit, PR 1 review round 2).
        if (! in_array($action, ['accept', 'decline'], true)) {
            return new QuotationResponseResult(422, 'invalid_action');
        }

        return DB::transaction(function () use ($orderId, $action) {
            $order = Order::withoutGlobalScope(CompanyScope::class)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return new QuotationResponseResult(404, 'order_unavailable');
            }

            if ($order->state !== OrderState::SENT) {
                return new QuotationResponseResult(409, 'invalid_state', $order);
            }

            if (! $order->partner_id || ! $order->partner) {
                return new QuotationResponseResult(409, 'invalid_partner', $order);
            }

            // Both flags already true is a historical-invalid state this
            // service never produces itself — fail closed rather than
            // silently repairing it.
            if ($order->mail_reception_confirmed && $order->mail_reception_declined) {
                return new QuotationResponseResult(409, 'invalid_state', $order);
            }

            $wantsAccept = $action === 'accept';

            $alreadyRecordedSameAction = $wantsAccept
                ? $order->mail_reception_confirmed
                : $order->mail_reception_declined;

            if ($alreadyRecordedSameAction) {
                return new QuotationResponseResult(200, $wantsAccept ? 'accepted' : 'declined', $order, idempotent: true);
            }

            $recordedOppositeAction = $wantsAccept
                ? $order->mail_reception_declined
                : $order->mail_reception_confirmed;

            if ($recordedOppositeAction) {
                return new QuotationResponseResult(409, 'conflict', $order);
            }

            $order->update([
                'mail_reception_confirmed' => $wantsAccept,
                'mail_reception_declined'  => ! $wantsAccept,
            ]);

            $order->addMessage([
                'company_id'  => $order->company_id,
                'causer_type' => $order->partner->getMorphClass(),
                'causer_id'   => $order->partner->id,
                'body'        => $wantsAccept
                    ? __('purchases::app.quotation-response.messages.accepted')
                    : __('purchases::app.quotation-response.messages.declined'),
                'type' => 'comment',
            ]);

            return new QuotationResponseResult(200, $wantsAccept ? 'accepted' : 'declined', $order);
        });
    }
}
