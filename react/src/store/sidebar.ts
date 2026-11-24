import { create } from 'zustand'

const STORAGE_KEY = 'b2b.sidebar'

function readStored(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'collapsed'
  } catch {
    /* تخزين محجوب — نبدأ من العمود الكامل */
    return false
  }
}

interface SidebarState {
  /** مطوي إلى الأيقونات وحدها */
  collapsed: boolean
  toggle: () => void
}

/**
 * حالة طيّ العمود الجانبي.
 *
 * تُحفظ لأنّ اختيار المستخدم هنا دائم لا عابر: من يطوي العمود يريده
 * مطوياً في كل جلسة، لا أن يعيد طيّه عند كل تحميل.
 */
export const useSidebar = create<SidebarState>((set, get) => ({
  collapsed: readStored(),

  toggle() {
    const collapsed = !get().collapsed
    set({ collapsed })

    try {
      localStorage.setItem(STORAGE_KEY, collapsed ? 'collapsed' : 'expanded')
    } catch {
      /* التفضيل ليس بيانات حرجة */
    }
  },
}))
