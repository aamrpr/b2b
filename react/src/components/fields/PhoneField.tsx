import { useEffect, useRef } from 'react'
import intlTelInput from 'intl-tel-input'
import type { Iti } from 'intl-tel-input'
import 'intl-tel-input/styles'
import { useI18n } from '@/i18n'
import { controlClass } from '@/components/ui'

/**
 * حقل هاتف دوليّ (intl-tel-input).
 *
 * المستورد السوري يراسل مورّداً تركياً وشاحناً لبنانياً، والرقم يُكتب
 * بصيغٍ شتّى: 09، 9639، 00963. والحقل يوحّدها إلى E.164 قبل الإرسال،
 * ويعرض العلم ليعرف الكاتب أيّ بلدٍ فهمه الحقل من رقمه.
 *
 * والمكتبة تعمل على DOM مباشرة لا React، فالتركيب في useEffect مرّة
 * واحدة، والقيمة تُكتب في حقلٍ مخفيّ باسم الحقل كي يلتقطها FormData
 * كما تلتقط بقية الحقول — فالنماذج هنا غير مضبوطة بالحالة.
 */
export function PhoneField({
  name,
  defaultValue = '',
  required = false,
  id,
}: {
  name: string
  defaultValue?: string
  required?: boolean
  id?: string
}) {
  const { locale } = useI18n()
  const inputRef = useRef<HTMLInputElement>(null)
  const hiddenRef = useRef<HTMLInputElement>(null)
  const itiRef = useRef<Iti | null>(null)

  useEffect(() => {
    const input = inputRef.current
    if (!input) return

    const iti = intlTelInput(input, {
      /*
       * أدوات التحقّق 200 كيلوبايت: تُحمَّل عند أوّل حقل هاتف يُعرَض لا
       * مع أوّل صفحة. والحقل يعمل قبل وصولها — يتحقّق بعده.
       */
      loadUtils: () => import('intl-tel-input/utils'),
      initialCountry: 'sy',
      // الجوار التجاري أولاً، ثمّ البحث عن البقية
      countryOrder: ['sy', 'tr', 'lb', 'jo', 'iq', 'ae', 'sa', 'eg', 'cn'],
      separateDialCode: true,
      uiTranslations: locale === 'ar' ? { searchPlaceholder: 'ابحث عن بلد' } : undefined,
    })

    itiRef.current = iti

    const sync = () => {
      if (!hiddenRef.current) return

      // الرقم الدوليّ إن صحّ، وإلّا ما كتبه المستخدم كما هو ليصحّحه الخادم
      let value = input.value

      try {
        if (iti.isValidNumber()) value = iti.getNumber() ?? input.value
      } catch {
        /*
         * التحقّق يرمي قبل وصول أدواته — وهي مؤجَّلة عمداً. والحقل يعمل
         * قبلها: يُرسَل ما كُتب، ويُصحَّح حين تصل. ورميةٌ غير ملتقَطة هنا
         * كانت تُسقط التطبيق كلّه لا هذا الحقل وحده.
         */
      }

      hiddenRef.current.value = value
    }

    sync()
    input.addEventListener('input', sync)
    input.addEventListener('countrychange', sync)

    return () => {
      input.removeEventListener('input', sync)
      input.removeEventListener('countrychange', sync)
      iti.destroy()
    }
  }, [locale])

  return (
    <>
      {/* الحقل الظاهر للكتابة، والمخفيّ هو ما يُرسل */}
      <input
        ref={inputRef}
        id={id}
        type="tel"
        dir="ltr"
        defaultValue={defaultValue}
        className={controlClass}
        required={required}
      />
      <input ref={hiddenRef} type="hidden" name={name} defaultValue={defaultValue} />
    </>
  )
}
