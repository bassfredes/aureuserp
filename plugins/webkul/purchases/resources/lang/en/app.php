<?php

return [
    'navigation' => [
        'settings' => [
            'label' => 'Settings',
            'group' => 'Purchase',
        ],
    ],

    'documents' => [
        'purchase-order-title' => 'Purchase Order #:name',
        'buyer' => 'Buyer',
        'order-reference' => 'Order Reference',
        'order-deadline' => 'Order Deadline',
        'expected-arrival' => 'Expected Arrival',
        'description' => 'Description',
        'product' => 'Product',
        'quantity' => 'Quantity',
        'unit' => 'Unit',
        'unit-price' => 'Unit Price',
        'discount' => 'Discount',
        'tax' => 'Tax',
        'taxes' => 'Taxes',
        'amount' => 'Amount',
        'untaxed-amount' => 'Untaxed Amount',
        'total' => 'Total',
        'quotation-valid-until' => 'Quotation Valid Until:',
        'payment-terms' => 'Payment Terms:',
        'agreement-validity' => 'Agreement Validity',
        'contact' => 'Contact',
        'reference' => 'Reference',
        'terms-and-conditions' => 'Terms & Conditions:',
        'additional-terms' => 'Additional Terms:',
    ],

    'quotation-response' => [
        'title' => 'Request for Quotation',
        'confirm' => [
            'heading' => 'Confirm your response',
            'body' => 'You are about to respond to request for quotation :order from :vendor. Click the button below to confirm.',
            'accept-button' => 'Confirm acceptance',
            'decline-button' => 'Confirm decline',
        ],
        'messages' => [
            'accepted' => 'The RFQ has been accepted.',
            'declined' => 'The RFQ has been declined.',
            'already-recorded' => 'Your response has already been recorded.',
            'conflict' => 'This response is not compatible with the current state of the request.',
            'link-invalid' => 'This link is invalid or has expired.',
            'order-unavailable' => 'This request for quotation is not available.',
        ],
    ],
];
