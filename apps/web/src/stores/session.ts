import { defineStore } from 'pinia'
import { api, jsonBody, setAuthToken } from '../lib/api'
import type { User } from '../types'

export interface EmailOtpChallengePayload {
  challenge_id: string
  challenge_token: string
  masked_email: string
  expires_in: number
  resend_available_in: number
  delivery_status?: 'captured' | 'submitted'
  delivery_message?: string
}

export interface LoginResponse extends Partial<EmailOtpChallengePayload> {
  token?: string
  user?: User
  requires_mfa_enrolment?: boolean
  requires_email_otp?: boolean
  requires_password_change?: boolean
}

export const useSessionStore = defineStore('session', {
  state: () => ({ user: null as User | null, loading: false }),
  getters: {
    authenticated: (state) => state.user !== null,
    isApplicant: (state) => state.user?.user_type === 'applicant',
    isStaff: (state) => state.user !== null && state.user.user_type !== 'applicant',
    homePath: (state) => state.user?.user_type === 'system_administrator' ? '/staff/users' : '/dashboard',
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
    async login(identity: string, password: string, totpCode = '', recoveryCode = '') {
      const response = await api<LoginResponse>('/auth/login', {
        method: 'POST',
        ...jsonBody({ identity, password, device_name: navigator.userAgent.slice(0, 90), totp_code: totpCode || undefined, recovery_code: recoveryCode || undefined }),
      })
      if (response.token && response.user) {
        setAuthToken(response.token)
        this.user = response.user
        cacheUser(response.user)
      }
      return response
    },
    async logout() {
      try {
        await api('/auth/logout', { method: 'POST' })
      } finally {
        const { purgeOfflineData } = await import('../offline/database')
        await purgeOfflineData()
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
