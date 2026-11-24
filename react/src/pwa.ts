/// <reference types="vite-plugin-pwa/client" />
import { registerSW } from 'virtual:pwa-register'

/** يحدّث التطبيق المثبَّت وحده عند صدور نسخة جديدة، بلا زرّ إعادة تحميل. */
registerSW({ immediate: true })
