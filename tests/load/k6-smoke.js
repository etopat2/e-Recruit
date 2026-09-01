import http from 'k6/http'
import { check, sleep } from 'k6'

const baseUrl = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/$/, '')
const apiToken = __ENV.API_TOKEN || ''

export const options = {
  scenarios: {
    public_browse: {
      executor: 'constant-vus',
      vus: Number(__ENV.PUBLIC_VUS || 5),
      duration: __ENV.DURATION || '30s',
      exec: 'publicBrowse',
    },
    authenticated_reads: {
      executor: 'constant-vus',
      vus: Number(__ENV.STAFF_VUS || 1),
      duration: __ENV.DURATION || '30s',
      exec: 'authenticatedReads',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1500', 'p(99)<3000'],
    checks: ['rate>0.99'],
  },
}

export function publicBrowse() {
  const live = http.get(`${baseUrl}/api/v1/health/live`, { tags: { endpoint: 'health-live' } })
  check(live, { 'live returns 200': (response) => response.status === 200 })

  const campaigns = http.get(`${baseUrl}/api/v1/campaigns`, { tags: { endpoint: 'campaign-list' } })
  check(campaigns, { 'campaigns return 200': (response) => response.status === 200 })
  sleep(0.5)
}

export function authenticatedReads() {
  if (!apiToken) {
    // Keep unauthenticated smoke useful; set API_TOKEN for officer/dashboard profiles.
    publicBrowse()
    return
  }
  const params = { headers: { Authorization: `Bearer ${apiToken}`, Accept: 'application/json' } }
  const applications = http.get(`${baseUrl}/api/v1/applications`, { ...params, tags: { endpoint: 'application-list' } })
  check(applications, { 'application list returns 200': (response) => response.status === 200 })

  const dashboard = http.get(`${baseUrl}/api/v1/reports/dashboard`, { ...params, tags: { endpoint: 'dashboard' } })
  check(dashboard, { 'dashboard returns 200 or scoped 403': (response) => [200, 403].includes(response.status) })
  sleep(0.5)
}
