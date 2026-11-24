export interface Money {
  amount: number
  currency: string
  decimals: number
  formatted: string
}

/**
 * كل المبالغ تصل من الـ API كأعداد صحيحة بالوحدة الصغرى.
 * التنسيق يتم هنا فقط — لا حساب على الأرقام العشرية في الواجهة.
 */
export function formatMoney(money: Money | null | undefined, locale = 'ar'): string {
  if (!money) return '—'

  const value = money.amount / 10 ** money.decimals

  return new Intl.NumberFormat(locale === 'ar' ? 'ar-SY' : 'en-US', {
    style: 'currency',
    currency: money.currency,
    maximumFractionDigits: money.decimals,
    numberingSystem: 'latn',
  }).format(value)
}

export function formatAmount(minor: number, currency: string, decimals = 2, locale = 'ar'): string {
  return formatMoney({ amount: minor, currency, decimals, formatted: '' }, locale)
}

export function toMinor(value: string | number, decimals = 2): number {
  return Math.round(Number(value || 0) * 10 ** decimals)
}
