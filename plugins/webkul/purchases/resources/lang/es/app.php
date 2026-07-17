<?php

return [
    'navigation' => [
        'settings' => [
            'label' => 'Configuración',
            'group' => 'Compras',
        ],
    ],

    'documents' => [
        'purchase-order-title' => 'Orden de compra n.º :name',
        'buyer' => 'Comprador',
        'order-reference' => 'Referencia del pedido',
        'order-deadline' => 'Fecha límite del pedido',
        'expected-arrival' => 'Llegada prevista',
        'description' => 'Descripción',
        'product' => 'Producto',
        'quantity' => 'Cantidad',
        'unit' => 'Unidad',
        'unit-price' => 'Precio unitario',
        'discount' => 'Descuento',
        'tax' => 'Impuesto',
        'taxes' => 'Impuestos',
        'amount' => 'Importe',
        'untaxed-amount' => 'Importe sin impuestos',
        'total' => 'Total',
        'quotation-valid-until' => 'Cotización válida hasta:',
        'payment-terms' => 'Condiciones de pago:',
        'agreement-validity' => 'Validez del acuerdo',
        'contact' => 'Contacto',
        'reference' => 'Referencia',
        'terms-and-conditions' => 'Términos y condiciones:',
        'additional-terms' => 'Términos adicionales:',
    ],

    'quotation-response' => [
        'title' => 'Solicitud de cotización',
        'confirm' => [
            'heading' => 'Confirmar su respuesta',
            'body' => 'Está a punto de responder a la solicitud de cotización :order de :vendor. Haga clic en el botón para confirmar.',
            'accept-button' => 'Confirmar aceptación',
            'decline-button' => 'Confirmar rechazo',
        ],
        'messages' => [
            'accepted' => 'La solicitud de cotización fue aceptada.',
            'declined' => 'La solicitud de cotización fue rechazada.',
            'already-recorded' => 'Su respuesta ya había sido registrada.',
            'conflict' => 'Esta respuesta no es compatible con el estado actual de la solicitud.',
            'link-invalid' => 'Este enlace no es válido o ha vencido.',
            'order-unavailable' => 'Esta solicitud de cotización no está disponible.',
        ],
    ],
];
