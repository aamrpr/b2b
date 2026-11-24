import { create } from 'zustand'

export type ThemeMode = 'system' | 'light' | 'dark'

const STORAGE_KEY = 'b2b.theme'

function readStored(): ThemeMode {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === 'light' || stored === 'dark' || stored === 'system') return stored
  } catch {
    /* تخزين محجوب — نتبع تفضيل النظام */
  }
  return 'system'
}

/**
 * يُطبَّق على <html> لا على <body>: الـ CSS يقرأ `data-theme`
 * من الجذر، و"النظام" يُزيل السمة فيعود استعلام الوسائط هو الحَكَم.
 */
function apply(mode: ThemeMode) {
  const root = document.documentElement

  if (mode === 'system') {
    root.removeAttribute('data-theme')
  } else {
    root.setAttribute('data-theme', mode)
  }

  const dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#0b1a2e' : '#142D4E')
}

interface ThemeState {
  mode: ThemeMode
  /** الوضع الفعلي بعد حسم "النظام" */
  resolved: () => 'light' | 'dark'
  setMode: (mode: ThemeMode) => void
  toggle: () => void
  init: () => void
}

export const useTheme = create<ThemeState>((set, get) => ({
  mode: readStored(),

  resolved() {
    const { mode } = get()

    if (mode !== 'system') return mode

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  },

  setMode(mode) {
    apply(mode)
    set({ mode })

    try {
      localStorage.setItem(STORAGE_KEY, mode)
    } catch {
      /* التفضيل ليس بيانات حرجة */
    }
  },

  /** نهاري ⟷ ليلي مباشرةً؛ الزر لا يدور على ثلاث حالات. */
  toggle() {
    get().setMode(get().resolved() === 'dark' ? 'light' : 'dark')
  },

  init() {
    apply(get().mode)
  },
}))
