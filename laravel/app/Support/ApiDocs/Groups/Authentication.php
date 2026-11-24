<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** التسجيل بدورين، والدخول، ومن أنا، والخروج. */
final class Authentication
{
    public static function paths(): array
    {
        return [
            '/auth/register/supplier' => [
                'post' => [
                    'tags' => ['المصادقة'],
                    'summary' => 'تسجيل مستورد',
                    'description' => <<<'MD'
                        ينشئ المستأجر من نوع `supplier` وأوّل مستخدمٍ فيه ودفتر
                        حساباته، ويعيد رمزاً صالحاً فوراً.

                        لكنّ الحساب يُنشأ **بانتظار المراجعة**
                        (`verification_status: pending`): لا يظهر في الدليل العامّ
                        ولا تصله طلبات الأسعار قبل أن تعتمده الإدارة من
                        `admin/suppliers/{tenant}/verify`. فالرمز يفتح الحساب،
                        والتوثيق يفتح السوق.

                        و`category_ids` إلزاميّ بصنفٍ واحد على الأقلّ، لأنّه هو ما
                        يحدّد أيّ طلبات أسعارٍ تصل هذا المستورد: مستوردٌ بلا صنف
                        حسابٌ لا يصله شيء.
                        MD,
                    'security' => [],
                    'requestBody' => Spec::body([
                        'company_name' => ['type' => 'string', 'maxLength' => 191, 'description' => 'اسم الشركة بالعربية.'],
                        'company_name_en' => ['type' => 'string', 'maxLength' => 191, 'nullable' => true, 'description' => 'اسمها بالإنجليزية — يظهر في المستندات والفواتير.'],
                        'contact_name' => ['type' => 'string', 'maxLength' => 191, 'description' => 'اسم المستخدم الأوّل.'],
                        'email' => ['type' => 'string', 'format' => 'email', 'description' => 'فريد على مستوى المنصة.'],
                        'phone' => ['type' => 'string', 'maxLength' => 30],
                        'password' => ['type' => 'string', 'minLength' => 8, 'format' => 'password'],
                        'password_confirmation' => ['type' => 'string', 'format' => 'password', 'description' => 'يجب أن يطابق `password`.'],
                        'city' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                        'base_currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'nullable' => true, 'description' => 'عملة دفتره. الافتراضي عملة المنصة.'],
                        'locale' => ['type' => 'string', 'enum' => ['ar', 'en'], 'nullable' => true],
                        'category_ids' => Spec::arrayOf(['type' => 'integer'], ['minItems' => 1, 'description' => 'أصنافه — صنفٌ واحد على الأقلّ.']),
                        'brands' => Spec::arrayOf(['type' => 'string'], ['nullable' => true, 'description' => 'الماركات التي يستوردها.']),
                    ], ['company_name', 'contact_name', 'email', 'phone', 'password', 'category_ids']),
                    'responses' => [
                        '201' => Spec::created(
                            Spec::object([
                                'message' => ['type' => 'string'],
                                'token' => ['type' => 'string'],
                                'user' => Spec::ref('User'),
                            ]),
                            'أُنشئ الحساب، ومعه رمزه وسطرٌ يقول إنّ التوثيق سيُراجَع.',
                        ),
                    ],
                ],
            ],

            '/auth/register/buyer' => [
                'post' => [
                    'tags' => ['المصادقة'],
                    'summary' => 'تسجيل تاجر جملة',
                    'description' => <<<'MD'
                        ينشئ المستأجر من نوع `buyer` وأوّل مستخدمٍ فيه. وهو
                        **فعّالٌ من لحظته** بلا مراجعة: المشتري لا يُوثَّق لأنّه لا
                        يُعرَض في الدليل — من يُوثَّق هو من يُعرَض.

                        وحقوله أقلّ من حقول المستورد: لا أصناف ولا ماركات ولا
                        عملة دفتر، لأنّ المشتري لا يُعرَض ولا يبيع ولا يُمسَك له
                        دفتر.
                        MD,
                    'security' => [],
                    'requestBody' => Spec::body([
                        'company_name' => ['type' => 'string', 'maxLength' => 191],
                        'contact_name' => ['type' => 'string', 'maxLength' => 191],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'phone' => ['type' => 'string', 'maxLength' => 30],
                        'password' => ['type' => 'string', 'minLength' => 8, 'format' => 'password'],
                        'password_confirmation' => ['type' => 'string', 'format' => 'password'],
                        'city' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                        'locale' => ['type' => 'string', 'enum' => ['ar', 'en'], 'nullable' => true],
                    ], ['company_name', 'contact_name', 'email', 'phone', 'password']),
                    'responses' => [
                        '201' => Spec::created(Spec::ref('AuthSession'), 'أُنشئ الحساب ومعه رمزه.'),
                    ],
                ],
            ],

            '/auth/login' => [
                'post' => [
                    'tags' => ['المصادقة'],
                    'summary' => 'تسجيل الدخول',
                    'description' => <<<'MD'
                        بريدٌ وكلمة مرور، فرمزٌ جديد. وكلّ دخولٍ يُصدر رمزاً
                        مستقلّاً، فجلسة الهاتف لا تُبطَل بدخولٍ من الحاسوب.

                        والحساب الموقوف يُرفض هنا لا بعد الدخول: من أوقفته الإدارة
                        لا يدخل ولو كانت كلمته صحيحة.

                        > **الحسابات التجريبية المحلّية** — كلمتها كلّها `password`:
                        > `admin@b2b.local` (إدارة)، `supplier0@b2b.local`
                        > و`supplier1@b2b.local` (مستوردان)، `buyer@b2b.local`
                        > (تاجر جملة).
                        MD,
                    'security' => [],
                    'requestBody' => Spec::body([
                        'email' => ['type' => 'string', 'format' => 'email', 'example' => 'buyer@b2b.local'],
                        'password' => ['type' => 'string', 'format' => 'password', 'example' => 'password'],
                    ], ['email', 'password']),
                    'responses' => [
                        '200' => Spec::ok(Spec::ref('AuthSession'), 'الرمز والمستخدم وشركته.'),
                    ],
                ],
            ],

            '/auth/me' => [
                'get' => [
                    'tags' => ['المصادقة'],
                    'summary' => 'من أنا',
                    'description' => <<<'MD'
                        المستخدم وشركته معاً — الدور، والعملة، وحالة التوثيق،
                        والشعار. تقرأ الواجهة من هذا الشكل في كلّ صفحة: منه تُبنى
                        القائمة الجانبية، وبه يُعرَف أيّ الأزرار تظهر.

                        وهو أيضاً فحص صلاحية الرمز عند بدء التطبيق: ٢٠٠ يعني أنّ
                        الجلسة قائمة، و٤٠١ يعني العودة إلى صفحة الدخول.
                        MD,
                    'x-no-forbidden' => true,
                    'responses' => [
                        '200' => Spec::ok(Spec::object(['user' => Spec::ref('User')]), 'صاحب الرمز.'),
                    ],
                ],
            ],

            '/auth/logout' => [
                'post' => [
                    'tags' => ['المصادقة'],
                    'summary' => 'تسجيل الخروج',
                    'description' => 'يُبطل **الرمز الحالي وحده**؛ ما أُصدر لأجهزةٍ أخرى يبقى عاملاً. والخروج من كلّ الأجهزة يقع عند تغيير كلمة المرور.',
                    'x-no-forbidden' => true,
                    'responses' => [
                        '200' => Spec::message('أُبطل الرمز.'),
                    ],
                ],
            ],
        ];
    }
}
