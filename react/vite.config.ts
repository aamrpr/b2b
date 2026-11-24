import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import path from 'node:path'
import { seoPlugin } from './vite.seo'

export default defineConfig({
  plugins: [
    seoPlugin(),
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: false,
      includeAssets: ['brand/mark.png', 'brand/pwa-192.png', 'brand/apple-touch-icon.png'],
      manifest: {
        id: '/',
        name: 'المستورد',
        short_name: 'المستورد',
        description: 'بضاعتك تبدأ من هون',
        lang: 'ar',
        dir: 'rtl',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        orientation: 'any',
        background_color: '#f5f7fa',
        theme_color: '#142D4E',
        categories: ['business', 'shopping'],
        icons: [
          { src: '/brand/pwa-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: '/brand/pwa-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          { src: '/brand/pwa-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,webp,ico,woff2}'],
        maximumFileSizeToCacheInBytes: 4 * 1024 * 1024,
        navigateFallback: 'index.html',
        navigateFallbackDenylist: [/^\/api\//, /^\/seo\.json/, /^\/sitemap\.xml/, /^\/robots\.txt/, /^\/storage\//, /^\/broadcasting/],
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/fonts\.(?:googleapis|gstatic)\.com\/.*/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'fonts',
              expiration: { maxEntries: 16, maxAgeSeconds: 60 * 60 * 24 * 365 },
            },
          },
        ],
      },
      devOptions: { enabled: true, type: 'module' },
    }),
  ],
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/seo.json': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/sitemap.xml': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/robots.txt': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
