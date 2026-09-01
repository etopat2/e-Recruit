import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'
import { defineConfig } from 'vitest/config'

export default defineConfig(({ mode }) => ({
  plugins: [
    vue(),
    ...(mode === 'test' ? [] : [VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['brand/ups-logo.png', 'icons/favicon-32.png', 'icons/apple-touch-icon.png'],
      manifest: {
        name: 'Uganda Prisons Service e-Recruit',
        short_name: 'UPS e-Recruit',
        description: 'Secure recruitment applications and field operations for Uganda Prisons Service.',
        theme_color: '#68162c',
        background_color: '#f5f1e8',
        display: 'standalone',
        scope: '/',
        start_url: '/',
        icons: [
          { src: '/icons/app-icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/app-icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any maskable' },
        ],
      },
      workbox: {
        navigateFallback: '/index.html',
        runtimeCaching: [{
          urlPattern: ({ url }) => url.pathname.startsWith('/api/v1/campaigns'),
          handler: 'NetworkFirst',
          options: { cacheName: 'public-campaigns', networkTimeoutSeconds: 5 },
        }],
      },
    })]),
  ],
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: { '/api': { target: 'http://api:8000', changeOrigin: true } },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    exclude: ['e2e/**', 'node_modules/**', 'dist/**'],
    // A single worker is reliable on low-memory CI runners and mirrors the
    // low-end devices targeted by the PWA without changing test semantics.
    pool: 'threads',
    fileParallelism: false,
    maxWorkers: 1,
  },
}))
