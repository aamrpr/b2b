import { create } from 'zustand'

export interface Toast {
  id: number
  message: string
  tone: 'success' | 'danger'
}

interface ToastState {
  toasts: Toast[]
  show: (message: string, tone?: Toast['tone']) => void
  dismiss: (id: number) => void
}

let nextId = 1

/**
 * إشعار لحظيّ.
 *
 * كان الإجراء ينجح بصمت: ترفع صورة أو تغيّر حالة منتج فلا يقول شيءٌ
 * إنّ شيئاً حدث، فيعيد المستخدم الكرّة ظنّاً أنّ النقرة ضاعت.
 *
 * والحالة هنا في مخزنٍ لا في سياق React: الطفرات تستدعيه من ملفاتٍ
 * بعيدة عن الشجرة، ولا يُعقل تمرير دالّةٍ عبر عشر طبقات لقول «تمّ».
 */
export const useToast = create<ToastState>((set) => ({
  toasts: [],

  show(message, tone = 'success') {
    const id = nextId++

    set((state) => ({ toasts: [...state.toasts, { id, message, tone }] }))

    // الإشعار يرحل وحده: ما يبقى على الشاشة يصير أثاثاً لا خبراً
    setTimeout(() => set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) })), 4000)
  },

  dismiss(id) {
    set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) }))
  },
}))

/** نداء مختصر من داخل الطفرات: toast('تم الحفظ') */
export function toast(message: string, tone: Toast['tone'] = 'success') {
  if (typeof message !== 'string' || message.trim() === '') return

  useToast.getState().show(message, tone)
}
