<?php

return [

    /*
     * الواجهة على نطاق والتطبيق على آخر، فطلبات المتصفح للواجهة
     * ولبطاقة المشاركة تحتاج إذناً صريحاً. الصور تُحمَّل كوسائط لا كطلبات.
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'seo.json', 'sitemap.xml', 'robots.txt'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
