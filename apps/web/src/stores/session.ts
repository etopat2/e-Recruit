import { defineStore } from 'pinia'
import { api, jsonBody, setAuthToken } from '../lib/api'
import type { User } from '../types'

export const useSessionStore = defineStore('session', {
  state: () => ({ user: null as User | null, loading: false }),
  getters: {
    authenticated: (state) => state.user !== null,
    isApplicant: (state) => state.user?.user_type === 'applicant',
    isStaff: (state) => state.user !== null && state.user.user_type !== 'applicant',
  },
  actions: {
    async restore() {
      if (!localStorage.getItem('ups_auth_token')) return
      this.loading = true
      try {
        const response = await api<{ user: User }>('/auth/me')
        this.user = response.user
        cacheUser(response.user)
      } catch {
        if (!navigator.onLine) this.user = cachedUser()
        else { setAuthToken(null); localStorage.removeItem('ups_cached_user') }
      } finally {
        this.loading = false
      }
    },
    async login(identity: string, password: string, totpCode = '') {
      const response = await api<{ token: string; user: User; requires_mfa_enrolment?: boolean }>('/auth/login', {
        method: 'POST',
        ...jsonBody({ identity, password, device_name: navigator.userAgent.slice(0, 90), totp_code: totpCode || undefined }),
      })
      setAuthToken(response.token)
      this.user = response.user
      cacheUser(response.user)
      return response
    },
    async logout() {
      try {
        await api('/auth/logout', { method: 'POST' })
      } finally {
        setAuthToken(null)
        localStorage.removeItem('ups_cached_user')
        this.user = null
      }
    },
  },
})

function cacheUser(user: User): void {
  localStorage.setItem('ups_cached_user', JSON.stringify({ ...user, email: null, phone: null }))
}

function cachedUser(): User | null {
  try { return JSON.parse(localStorage.getItem('ups_cached_user') || 'null') as User | null } catch { return null }
}
