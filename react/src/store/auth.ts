import { create } from 'zustand'
import { api, getToken, setToken } from '@/lib/api'
import { useRealtime } from './realtime'
import type { AuthUser } from '@/types'

interface AuthState {
  user: AuthUser | null
  loading: boolean
  /** تحديث المستخدم بعد حفظ حسابه، فالاسم والصورة يظهران في الشريط */
  setUser: (user: AuthUser) => void
  hydrate: () => Promise<void>
  login: (email: string, password: string) => Promise<void>
  registerSupplier: (payload: Record<string, unknown>) => Promise<void>
  registerBuyer: (payload: Record<string, unknown>) => Promise<void>
  logout: () => Promise<void>
}

export const useAuth = create<AuthState>((set) => ({
  user: null,
  loading: true,

  setUser(user) {
    set({ user })
  },

  async hydrate() {
    if (!getToken()) {
      set({ user: null, loading: false })
      return
    }
    try {
      const { data } = await api.get<{ user: AuthUser }>('/auth/me')
      set({ user: data.user, loading: false })
      useRealtime.getState().subscribe(data.user)
    } catch {
      setToken(null)
      set({ user: null, loading: false })
    }
  },

  async login(email, password) {
    const { data } = await api.post<{ token: string; user: AuthUser }>('/auth/login', { email, password })
    setToken(data.token)
    set({ user: data.user })
    useRealtime.getState().subscribe(data.user)
  },

  async registerSupplier(payload) {
    const { data } = await api.post<{ token: string; user: AuthUser }>('/auth/register/supplier', payload)
    setToken(data.token)
    set({ user: data.user })
  },

  async registerBuyer(payload) {
    const { data } = await api.post<{ token: string; user: AuthUser }>('/auth/register/buyer', payload)
    setToken(data.token)
    set({ user: data.user })
  },

  async logout() {
    try {
      await api.post('/auth/logout')
    } catch {
      /* الرمز قد يكون منتهياً أصلاً */
    }
    setToken(null)
    set({ user: null })
    useRealtime.getState().teardown()
  },
}))

export const isSupplier = (user: AuthUser | null) => user?.tenant?.type === 'supplier'
export const isBuyer = (user: AuthUser | null) => user?.tenant?.type === 'buyer'
export const isPlatform = (user: AuthUser | null) => user?.tenant?.type === 'platform'
