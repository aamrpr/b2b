<?php

return [
    /* اللغات المدعومة — إضافة لغة هنا تكفي، لا تغيير في الجداول */
    'locales' => array_filter(array_map('trim', explode(',', (string) env('SUPPORTED_LOCALES', 'ar,en')))),

    'rtl_locales' => ['ar', 'fa', 'he', 'ur'],

    /* عملة دفتر المنصة */
    'platform_currency' => env('PLATFORM_BASE_CURRENCY', 'SYP'),

    'accounting' => [
        /* لحظة استحقاق العمولة: confirmed | shipped | delivered */
        'commission_trigger' => env('COMMISSION_TRIGGER', 'delivered'),

        /* وضع التحصيل: wallet (بوابة دفع) | manual (نقد/حوالة خارج المنصة) */
        'collection_mode' => env('COLLECTION_MODE', 'manual'),

        'default_commission_rate' => (float) env('DEFAULT_COMMISSION_RATE', 5),
    ],

    'rfq' => [
        /* أقصى عدد طلبات عروض أسعار تصل للمستورد الواحد يومياً */
        'daily_broadcast_cap' => (int) env('RFQ_DAILY_CAP', 20),

        /* صلاحية الطلب الافتراضية بالساعات — السوق سريع التقلّب */
        'default_validity_hours' => (int) env('RFQ_VALIDITY_HOURS', 72),
    ],

    'docs' => [
        /*
         * صفحة الوثيقة ومواصفتها: مفتوحة في التطوير، ومغلقة في الإنتاج
         * إلّا بطلب صريح — وثيقةٌ للمطوّرين لا صفحةٌ للسوق.
         */
        'enabled' => (bool) env('API_DOCS_ENABLED', env('APP_ENV') !== 'production'),
    ],
];
