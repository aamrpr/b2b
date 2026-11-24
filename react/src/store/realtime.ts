import { create } from 'zustand'
import { api } from '@/lib/api'
import { connectEcho, disconnectEcho } from '@/lib/echo'
import type { AuthUser, Notification as AppNotification, IncomingMessage, ReadNotice, TypingNotice } from '@/types'

interface RealtimeState {
  notifications: AppNotification[]
  unreadNotifications: number
  unreadConversations: number
  /** رسائل وصلت لحظياً، تلتقطها شاشة المحادثة المفتوحة */
  lastMessage: IncomingMessage | null
  lastTyping: TypingNotice | null
  lastRead: ReadNotice | null
  connected: boolean
  /** صاحب الاشتراك الحالي — يمنع تكرار المستمعين */
  subscribedFor: number | null

  subscribe: (user: AuthUser) => void
  teardown: () => void
  refreshCounts: () => Promise<void>
  loadNotifications: () => Promise<void>
  markRead: (id: string) => Promise<void>
  markAllRead: () => Promise<void>
  setUnreadConversations: (count: number) => void
  consumeMessage: () => void
  consumeTyping: () => void
  consumeRead: () => void
}

export const useRealtime = create<RealtimeState>((set, get) => ({
  notifications: [],
  unreadNotifications: 0,
  unreadConversations: 0,
  lastMessage: null,
  lastTyping: null,
  lastRead: null,
  connected: false,
  subscribedFor: null,

  subscribe(user) {
    const echo = connectEcho()
    if (!echo || !user.tenant) return

    // نداء ثانٍ لنفس المستخدم يربط مستمعاً إضافياً على القناة نفسها،
    // فتُحسب الرسالة الواحدة مرّتين في العدّاد ويتكرّر الإشعار في القائمة
    if (get().subscribedFor === user.id) return

    // الإشعارات على قناة المستخدم، والرسائل على قناة الشركة
    echo
      .private(`App.Models.User.${user.id}`)
      .notification((payload: AppNotification['data'] & Record<string, unknown>) => {
        const notification: AppNotification = {
          id: String(payload.id ?? crypto.randomUUID()),
          event: String(payload.event ?? ''),
          icon: String(payload.icon ?? 'bell'),
          title: payload.title as AppNotification['title'],
          body: payload.body as AppNotification['body'],
          action_url: (payload.action_url as string | null) ?? null,
          data: payload,
          read_at: null,
          created_at: new Date().toISOString(),
        }

        set((state) => ({
          notifications: [notification, ...state.notifications].slice(0, 50),
          unreadNotifications: state.unreadNotifications + 1,
        }))
      })

    echo
      .private(`tenant.${user.tenant.id}`)
      .listen('.message.sent', (message: IncomingMessage) => {
        set((state) => ({
          lastMessage: message,
          unreadConversations: state.unreadConversations + 1,
        }))
      })
      .listen('.conversation.typing', (event: Omit<TypingNotice, 'at'>) => {
        set({
          lastTyping: { ...event, at: Date.now() },
        })
      })
      .listen('.conversation.read', (event: ReadNotice) => {
        set({ lastRead: event })
      })

    set({ connected: true, subscribedFor: user.id })

    void get().refreshCounts()
  },

  teardown() {
    disconnectEcho()
    set({
      notifications: [],
      unreadNotifications: 0,
      unreadConversations: 0,
      lastMessage: null,
      lastTyping: null,
      lastRead: null,
      connected: false,
      subscribedFor: null,
    })
  },

  async refreshCounts() {
    try {
      const [notifications, conversations] = await Promise.all([
        api.get<{ count: number }>('/notifications/unread-count'),
        api.get<{ conversations: number }>('/conversations/unread-count'),
      ])
      set({
        unreadNotifications: notifications.data.count,
        unreadConversations: conversations.data.conversations,
      })
    } catch {
      /* غير متصل — العدّادات تبقى كما هي */
    }
  },

  async loadNotifications() {
    const { data } = await api.get<{ data: AppNotification[]; unread_count: number }>('/notifications')
    set({ notifications: data.data, unreadNotifications: data.unread_count })
  },

  async markRead(id) {
    await api.post(`/notifications/${id}/read`)
    set((state) => ({
      notifications: state.notifications.map((n) =>
        n.id === id ? { ...n, read_at: new Date().toISOString() } : n,
      ),
      unreadNotifications: Math.max(0, state.unreadNotifications - 1),
    }))
  },

  async markAllRead() {
    await api.post('/notifications/read-all')
    const now = new Date().toISOString()
    set((state) => ({
      notifications: state.notifications.map((n) => ({ ...n, read_at: n.read_at ?? now })),
      unreadNotifications: 0,
    }))
  },

  setUnreadConversations(count) {
    set({ unreadConversations: count })
  },

  consumeMessage() {
    set({ lastMessage: null })
  },

  consumeTyping() {
    set({ lastTyping: null })
  },

  consumeRead() {
    set({ lastRead: null })
  },
}))
