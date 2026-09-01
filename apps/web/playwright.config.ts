import { defineConfig, devices } from '@playwright/test'

const localBrowser = process.platform === 'win32' ? { channel: 'msedge' as const } : {}

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  retries: 1,
  reporter: 'line',
  use: { baseURL: 'http://127.0.0.1:4173', trace: 'retain-on-failure' },
  webServer: { command: 'npm run preview -- --host 127.0.0.1', url: 'http://127.0.0.1:4173', reuseExistingServer: true },
  projects: [
    { name: 'chromium-desktop', use: { ...devices['Desktop Chrome'], ...localBrowser } },
    { name: 'chromium-mobile', use: { ...devices['Pixel 5'], ...localBrowser } },
  ],
})
