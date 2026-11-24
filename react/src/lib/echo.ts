import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { apiOrigin, getToken } from './api'

declare global {
  interface Window {
    Pusher: typeof Pusher
    Echo?: Echo<'reverb'>
  }
}

window.Pusher = Pusher

let echo: Echo<'reverb'> | null = null

/**
 * عميل البث. يُنشأ مرة واحدة بعد تسجيل الدخول،
 * ويُصادَق على القنوات الخاصة بنفس رمز Sanctum المستخدم في الـ API.
 */
export function connectEcho(): Echo<'reverb'> | null {
  if (echo) return echo

  const key = import.meta.env.VITE_REVERB_APP_KEY
  if (!key) return null

  const token = getToken()
  if (!token) return null

  echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? '127.0.0.1',
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${apiOrigin}/api/broadcasting/auth`,
    auth: { headers: { Authorization: `Bearer ${token}` } },
  })

  window.Echo = echo

  return echo
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
  window.Echo = undefined
}

export function getEcho(): Echo<'reverb'> | null {
  return echo
}
