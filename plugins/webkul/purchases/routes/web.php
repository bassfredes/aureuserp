<?php

use Illuminate\Support\Facades\Route;
use Webkul\Purchase\Http\Controllers\RespondQuotationController;

Route::middleware(['web', 'signed'])->group(function () {
    // Same URI for both verbs, constrained to the two known actions
    // (Intelligent-Integration-Suite#138, PR 1): the confirmation form on
    // the GET page posts back to this exact path + query string, so the
    // signature validates identically for either verb — the signed
    // capability authorizes this order+action, not a specific HTTP method.
    Route::get('purchase/{order}/{action}', [RespondQuotationController::class, 'show'])
        ->where('action', 'accept|decline')
        ->name('purchases.quotations.respond');

    Route::post('purchase/{order}/{action}', [RespondQuotationController::class, 'respond'])
        ->where('action', 'accept|decline')
        ->name('purchases.quotations.respond.submit');
});
