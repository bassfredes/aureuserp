<?php

return [
    'navigation' => [
        'settings' => [
            'label' => 'الإعدادات',
            'group' => 'المشتريات',
        ],
    ],

    'documents' => [
        'purchase-order-title' => 'أمر الشراء رقم :name',
        'buyer' => 'المشتري',
        'order-reference' => 'مرجع الطلب',
        'order-deadline' => 'الموعد النهائي للطلب',
        'expected-arrival' => 'الوصول المتوقع',
        'description' => 'الوصف',
        'product' => 'المنتج',
        'quantity' => 'الكمية',
        'unit' => 'الوحدة',
        'unit-price' => 'سعر الوحدة',
        'discount' => 'الخصم',
        'tax' => 'الضريبة',
        'taxes' => 'الضرائب',
        'amount' => 'المبلغ',
        'untaxed-amount' => 'المبلغ بدون ضريبة',
        'total' => 'الإجمالي',
        'quotation-valid-until' => 'عرض السعر صالح حتى:',
        'payment-terms' => 'شروط الدفع:',
        'agreement-validity' => 'صلاحية الاتفاقية',
        'contact' => 'جهة الاتصال',
        'reference' => 'المرجع',
        'terms-and-conditions' => 'الشروط والأحكام:',
        'additional-terms' => 'شروط إضافية:',
    ],

    'quotation-response' => [
        'title' => 'طلب عرض سعر',
        'confirm' => [
            'heading' => 'تأكيد ردك',
            'body' => 'أنت على وشك الرد على طلب عرض السعر :order من :vendor. انقر على الزر أدناه للتأكيد.',
            'accept-button' => 'تأكيد القبول',
            'decline-button' => 'تأكيد الرفض',
        ],
        'messages' => [
            'accepted' => 'تم قبول طلب عرض السعر.',
            'declined' => 'تم رفض طلب عرض السعر.',
            'already-recorded' => 'تم تسجيل ردك مسبقاً.',
            'conflict' => 'هذا الرد غير متوافق مع الحالة الحالية للطلب.',
            'link-invalid' => 'هذا الرابط غير صالح أو منتهي الصلاحية.',
            'order-unavailable' => 'طلب عرض السعر هذا غير متاح.',
        ],
    ],
];
