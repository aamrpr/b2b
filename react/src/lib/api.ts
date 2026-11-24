import axios from 'axios'
import { toast } from '@/store/toast'

const TOKEN_KEY = 'b2b.token'

export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string | null) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* التخزين محجوب — الجلسة تبقى في الذاكرة فقط */
  }
}

/** فارغ في التطوير فيبقى الطلب على نفس المصدر. وفي النشر يشير إلى واجهة البرمجة. */
export const apiOrigin = (import.meta.env.VITE_API_ORIGIN ?? '').replace(/\/$/, '')

export const api = axios.create({
  baseURL: `${apiOrigin}/api/v1`,
  headers: { Accept: 'application/json' },
})

// اللغة تُرسل مع كل طلب فيردّ الخادم بالنصوص المناسبة
api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`

  let locale = 'ar'
  try {
    locale = localStorage.getItem('b2b.locale') ?? 'ar'
  } catch {
    /* تجاهل */
  }
  config.headers['X-Locale'] = locale

  return config
})

api.interceptors.response.use(
  (response) => {
    /*
     * كل متحكّم في الخادم يردّ `message` بعد الفعل الناجح، وبلغة الطلب.
     * فبدل أن تقول كل صفحة «تمّ» بنفسها — وأكثرها لم يكن يقول شيئاً —
     * يُعرض ما قاله الخادم مرّة واحدة من هنا. والقراءة لا خبر لها، فلا
     * إشعار لها.
     */
    const method = response.config.method?.toLowerCase()
    const message = (response.data as { message?: unknown } | undefined)?.message

    // بعض الردود تسمّي المورد نفسه message، مثل رسالة المحادثة.
    // والإشعار يعرض نصاً فقط، وعرض كائنٍ يُسقط الصفحة.
    if (typeof message === 'string' && message !== '' && method && method !== 'get') toast(message)

    return response
  },
  (error) => {
    if (error?.response?.status === 401) {
      setToken(null)

      /*
       * الرمز المنتهي كان يترك المستخدم في صفحةٍ تفشل طلباتها واحداً
       * واحداً بلا تفسير. فيُنقل إلى الدخول مرّة واحدة — والعلامة في
       * الرابط لتقول له لماذا خرج.
       */
      if (!window.location.pathname.startsWith('/login')) {
        window.location.replace('/login?expired=1')
      }
    }
    return Promise.reject(error)
  },
)

/** رسالة خطأ صالحة للعرض من استجابة Laravel */
export function errorMessage(error: unknown, fallback: string): string {
  const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response
  const data = response?.data
  if (data?.errors) {
    const first = Object.values(data.errors)[0]
    if (first?.[0]) return first[0]
  }
  return data?.message ?? fallback
}
