/**
 * صيغة تاريخ واحدة للتطبيق.
 *
 * كانت الصيغتان تختلطان: `toLocaleDateString` في موضع و`slice(0, 10)`
 * في آخر، فيرى المستخدم «٣١‏/١٠‏/٢٠٢٦» هنا و«2026-10-31» هناك في
 * الشاشة نفسها. والأرقام لاتينية دائماً كما في بقية المبالغ والكميات —
 * أرقام الهندية تكسر محاذاة الجداول وتصعب مقارنتها بالعين.
 */

type Locale = string

function intl(locale: Locale, options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat {
  return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SY' : 'en-US', {
    numberingSystem: 'latn',
    ...options,
  })
}

/** يوم بلا وقت: 31-10-2026 */
export function formatDate(value: string | null | undefined, locale: Locale): string {
  if (!value) return '—'

  return intl(locale, { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(value))
}

/** يوم ووقت: لِما يُقرأ في سجلّ أو خطّ زمنيّ */
export function formatDateTime(value: string | null | undefined, locale: Locale): string {
  if (!value) return '—'

  return intl(locale, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

/** يوم وشهر مختصران: للمحادثات حيث السنة معلومة من السياق */
export function formatDayMonth(value: string | Date, locale: Locale): string {
  return intl(locale, { day: 'numeric', month: 'short' }).format(new Date(value))
}
