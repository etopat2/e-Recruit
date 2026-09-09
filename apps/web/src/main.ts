import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import { installLimitedSelectViewport } from './lib/limitedSelectViewport'

createApp(App).use(createPinia()).use(router).mount('#app')
installLimitedSelectViewport()

window.addEventListener('load', () => {
  window.setTimeout(async () => {
    const { registerSW } = await import('virtual:pwa-register')
    registerSW({ immediate: true })
  }, 0)
}, { once: true })
