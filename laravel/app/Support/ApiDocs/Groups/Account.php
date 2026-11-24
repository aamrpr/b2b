<?php

namespace App\Support\ApiDocs\Groups;

use App\Support\ApiDocs\Spec;

/** كلّ مستخدم يدير حسابه، وصاحب الشركة يدير بياناتها وشعارها. */
final class Account
{
    public static function paths(): array
    {
        return [
            '/me' => [
                'put' => [
                    'tags' => ['الحساب'],
                    'summary' => 'تعديل معلوماتي',
                    'description' => <<<'MD'
                        الاسم والبريد والهاتف ولغة الواجهة. البريد فريدٌ على
                        مستوى المنصة، ويُستثنى صاحبه من الفحص كي لا يرفض حفظ
                        بريده هو.

                        و`locale` ليست تفضيلاً للعرض فقط: بها تُرسَل الإشعارات
                        والبُرد إلى صاحبها.
                        MD,
                    'requestBody' => Spec::body([
                        'name' => ['type' => 'string', 'maxLength' => 191],
                        'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 191],
                        'phone' => ['type' => 'string', 'maxLength' => 30, 'nullable' => true],
                        'locale' => ['type' => 'string', 'enum' => ['ar', 'en'], 'nullable' => true],
                    ], ['name', 'email']),
                    'responses' => [
                        '200' => Spec::message('حُفظت المعلومات.', ['user' => Spec::ref('User')]),
                    ],
                ],
            ],

            '/me/password' => [
                'put' => [
                    'tags' => ['الحساب'],
                    'summary' => 'تغيير كلمة المرور',
                    'description' => <<<'MD'
                        تُطلَب الكلمة الحالية مع الجديدة: من تَرك جهازه مفتوحاً لا
                        ينبغي أن يفقد حسابه بنقرتين.

                        والتبديل **يُنهي الجلسات الأخرى ويُبقي الحالية**: من
                        غيّرها لأنّها تسرّبت يريد إخراج من سرّبها، ولا يريد أن
                        يُخرِج نفسه من الصفحة التي هو فيها.

                        والكلمة الحالية الخاطئة تعود ٤٢٢ تحت الحقل
                        `current_password`.
                        MD,
                    'requestBody' => Spec::body([
                        'current_password' => ['type' => 'string', 'format' => 'password'],
                        'password' => ['type' => 'string', 'minLength' => 8, 'format' => 'password'],
                        'password_confirmation' => ['type' => 'string', 'format' => 'password'],
                    ], ['current_password', 'password']),
                    'responses' => [
                        '200' => Spec::message('تغيّرت كلمة المرور، وبقيت هذه الجلسة وحدها.'),
                    ],
                ],
            ],

            '/me/avatar' => [
                'post' => [
                    'tags' => ['الحساب'],
                    'summary' => 'رفع صورة المستخدم',
                    'description' => 'صورةٌ حتى ٢ ميغابايت. تُستبدل السابقة ويُحذف ملفّها، فلا تتراكم الصور المهجورة على القرص.',
                    'requestBody' => Spec::form([
                        'avatar' => Spec::file('صورة، حتى 2048 كيلوبايت.'),
                    ], ['avatar']),
                    'responses' => [
                        '200' => Spec::message('حُفظت الصورة.', ['user' => Spec::ref('User')]),
                    ],
                ],
                'delete' => [
                    'tags' => ['الحساب'],
                    'summary' => 'حذف صورة المستخدم',
                    'description' => 'تعود الواجهة إلى أوّل حرفٍ من الاسم في دائرةٍ ملوّنة.',
                    'responses' => [
                        '200' => Spec::message('حُذفت الصورة.', ['user' => Spec::ref('User')]),
                    ],
                ],
            ],

            '/my/company' => [
                'put' => [
                    'tags' => ['الحساب'],
                    'summary' => 'تعديل بيانات الشركة',
                    'description' => <<<'MD'
                        الاسم المعروض بلغتيه، والنبذة، والمدينة، والهاتف،
                        والواتساب.

                        وهذه ليست بيانات داخلية: الاسم والنبذة والشعار هي ما
                        يراه المشتري في الدليل، وما يُطبع في ترويسة **كلّ عرض
                        سعر** يصدر عن هذه الشركة.
                        MD,
                    'requestBody' => Spec::body([
                        'name' => Spec::translatable('191'),
                        'description' => Spec::translatable('2000') + ['nullable' => true, 'description' => 'النبذة — ولا حقل إلزاميّ فيها.'],
                        'city' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                        'phone' => ['type' => 'string', 'maxLength' => 30, 'nullable' => true],
                        'whatsapp' => ['type' => 'string', 'maxLength' => 30, 'nullable' => true],
                    ], ['name']),
                    'responses' => [
                        '200' => Spec::message('حُفظت بيانات الشركة.', ['user' => Spec::ref('User')]),
                    ],
                ],
            ],

            '/my/company/logo' => [
                'post' => [
                    'tags' => ['الحساب'],
                    'summary' => 'رفع شعار الشركة',
                    'description' => 'صورةٌ حتى ٢ ميغابايت. يظهر في بطاقة الدليل وفي ترويسة كلّ عرض سعر.',
                    'requestBody' => Spec::form([
                        'logo' => Spec::file('صورة، حتى 2048 كيلوبايت.'),
                    ], ['logo']),
                    'responses' => [
                        '200' => Spec::message('حُفظ الشعار.', ['user' => Spec::ref('User')]),
                    ],
                ],
                'delete' => [
                    'tags' => ['الحساب'],
                    'summary' => 'حذف شعار الشركة',
                    'description' => 'تعود البطاقة إلى الحرف الأوّل من اسم الشركة.',
                    'responses' => [
                        '200' => Spec::message('حُذف الشعار.', ['user' => Spec::ref('User')]),
                    ],
                ],
            ],
        ];
    }
}
