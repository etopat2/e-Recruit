import { cleanup, render, screen } from '@testing-library/vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import VerificationWorkbenchView from './VerificationWorkbenchView.vue'

vi.mock('../components/SecureDocumentPreview.vue', () => ({
  default: { template: '<div aria-label="Protected document preview"></div>' },
}))

const applicationKey = '01RAWAPPLICATIONKEY0000000'
const documentKey = '01RAWDOCUMENTKEY000000000'
const districtKey = '01RAWDISTRICTKEY000000000'

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url.includes('verification-workbench')) {
      return new Response(JSON.stringify({
        application: {
          id: applicationKey,
          reference: 'UPS/2026/WRD/000321',
          applicant_name: 'Amina Nabirye',
          entered_data: {
            personal: { full_name: 'Amina Nabirye', date_of_birth: '2001-05-12', ugandan: true },
            origin: { district_id: districtKey, village: 'Kiwanga', parish: 'Namanve', district: 'Mukono', full_address: 'Kiwanga, Namanve, Mukono' },
            education: [{ level: 'UCE', institution: 'Namilyango College', result: 'Division One', completion_year: '2019' }],
            declaration: { accepted: true },
          },
        },
        documents: [{ id: documentKey, type: 'national_id', label: 'National ID', filename: 'amina-national-id.pdf', mime_type: 'application/pdf', version: 1, preview_url: `/api/v1/documents/${documentKey}/preview`, quality: { status: 'clear' }, fields: [] }],
        comparisons: [], verified_values: [],
        evidence_matrix: { dob: [{ document_id: documentKey, source_label: 'National ID - version 1', source_filename: 'amina-national-id.pdf', value: '08.02.1992', confidence: 0.98, page: 1 }] },
      }), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }
    return new Response(new Blob(['preview']), { status: 200, headers: { 'Content-Type': 'application/pdf' } })
  }))
  vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:protected-preview')
  vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('VerificationWorkbenchView', () => {
  it('renders typed evidence with human source labels and hides internal keys', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/applications/:id/verification', component: VerificationWorkbenchView }] })
    await router.push(`/applications/${applicationKey}/verification`)
    await router.isReady()
    const rendered = render(VerificationWorkbenchView, { global: { plugins: [router] } })

    expect(await screen.findByRole('heading', { name: 'Structured declared information' })).toBeInTheDocument()
    expect(screen.getAllByText('Amina Nabirye').length).toBeGreaterThan(0)
    expect(screen.getAllByText('12 May 2001')).toHaveLength(2)
    expect(screen.getByRole('button', { name: /8 Feb 1992.*Focus original source/i })).toBeInTheDocument()
    expect(screen.queryByText('2 Aug 1992')).not.toBeInTheDocument()
    expect(screen.getByText('Mukono, Namanve, Kiwanga')).toBeInTheDocument()
    expect(screen.getByText('Namilyango College')).toBeInTheDocument()
    expect(screen.getAllByText('amina-national-id.pdf')).toHaveLength(2)
    expect(screen.getByText('National ID - version 1')).toBeInTheDocument()
    expect(rendered.container.textContent).not.toContain(applicationKey)
    expect(rendered.container.textContent).not.toContain(documentKey)
    expect(rendered.container.textContent).not.toContain(districtKey)
  })
})
