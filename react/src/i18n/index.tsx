import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { ar } from './ar'
import { en } from './en'
import type { PluralForms } from './plural'

export type Locale = 'ar' | 'en'

const dictionaries = { ar, en } as const

type Dict = typeof ar

const STORAGE_KEY = 'b2b.locale'

function readStoredLocale(): Locale {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === 'ar' || stored === 'en') return stored
  } catch {
    /* الوضع الخاص أو تخزين محجوب — نتجاهل */
  }
  return 'ar'
}

interface I18nValue {
  locale: Locale
  dir: 'rtl' | 'ltr'
  t: Dict
  setLocale: (locale: Locale) => void
  /** اختيار نص من كائن ترجمات قادم من الـ API */
  pick: (value: unknown) => string
  /** عدد + اسم معدود بصيغته الصحيحة في لغة العرض */
  count: (value: number, forms: PluralForms) => string
}


const I18nContext = createContext<I18nValue | null>(null)

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(readStoredLocale)

  const dir = locale === 'ar' ? 'rtl' : 'ltr'

  useEffect(() => {
    document.documentElement.lang = locale
    document.documentElement.dir = dir
    try {
      localStorage.setItem(STORAGE_KEY, locale)
    } catch {
      /* لا شيء — تفضيل اللغة ليس بيانات حرجة */
    }
  }, [locale, dir])

  const setLocale = useCallback((next: Locale) => setLocaleState(next), [])

  const pick = useCallback(
    (value: unknown): string => {
      if (typeof value === 'string') return value
      if (value && typeof value === 'object') {
        const record = value as Record<string, string>
        return record[locale] ?? record.ar ?? record.en ?? ''
      }
      return ''
    },
    [locale],
  )

  const count = useCallback(
    (value: number, forms: PluralForms): string => {
      const tag = locale === 'ar' ? 'ar-SY' : 'en-US'
      const rule = new Intl.PluralRules(tag).select(value)
      const word = forms[rule as keyof PluralForms] ?? forms.other

      // «صنفان فرعيان» تحمل العدد في صيغتها، فإعادة الرقم معها تكرار
      const carriesNumber = locale === 'ar' && (rule === 'zero' || rule === 'one' || rule === 'two')

      return carriesNumber ? word : `${value.toLocaleString(tag, { numberingSystem: 'latn' })} ${word}`
    },
    [locale],
  )

  const value = useMemo<I18nValue>(
    () => ({ locale, dir, t: dictionaries[locale] as Dict, setLocale, pick, count }),
    [locale, dir, setLocale, pick, count],
  )

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
}

export function useI18n(): I18nValue {
  const context = useContext(I18nContext)
  if (!context) throw new Error('useI18n must be used inside I18nProvider')
  return context
}
