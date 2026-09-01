self.addEventListener('push', (event) => {
  let payload = { title: 'UPS e-Recruit update', body: 'Sign in to view your latest recruitment update.', url: '/dashboard' }
  try { payload = { ...payload, ...(event.data ? event.data.json() : {}) } } catch { /* retain minimum non-sensitive message */ }
  event.waitUntil(self.registration.showNotification(payload.title, {
    body: payload.body,
    icon: '/icons/app-icon-192.png',
    badge: '/icons/favicon-32.png',
    data: { url: payload.url || '/dashboard' },
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL(event.notification.data?.url || '/dashboard', self.location.origin).href
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
    const existing = clients.find((client) => client.url === target)
    return existing ? existing.focus() : self.clients.openWindow(target)
  }))
})
