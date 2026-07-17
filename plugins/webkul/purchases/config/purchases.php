<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Quotation response link validity
    |--------------------------------------------------------------------------
    |
    | How long the signed accept/decline links sent to vendors in the RFQ
    | email remain valid, in minutes. No authoritative RFQ expiry date
    | exists on the Order model today, so this configured TTL is the only
    | validity window applied when the link is generated
    | (Intelligent-Integration-Suite#138, PR 1).
    |
    */
    'quotation_response_ttl_minutes' => env('PURCHASES_QUOTATION_RESPONSE_TTL_MINUTES', 10080),
];
