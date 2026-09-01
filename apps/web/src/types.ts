export interface User {
  id: number
  name: string
  email: string | null
  phone: string | null
  user_type: string
  is_privileged: boolean
  mfa_confirmed: boolean
  scopes: Array<{ scope_type: string; scope_id: string | null; allowed_tasks: string[] }>
}

export interface CampaignPost {
  id: string
  code: string
  name: string
  description: string
  sections: Record<string, { required?: boolean } | boolean>
  hard_copy_required: boolean
}

export interface Campaign {
  id: string
  code: string
  name: string
  year: number
  status: string
  opens_at: string
  closes_at: string
  hard_copy_deadline_at: string | null
  privacy_notice: { version?: string; summary?: string }
  posts: CampaignPost[]
}

export interface ApplicationRecord {
  id: string
  reference: string | null
  status: string
  draft_data?: Record<string, unknown>
  entity_version: number
  submitted_at: string | null
  campaign: Campaign
  post: CampaignPost
  documents: Array<Record<string, unknown>>
  timeline: Array<Record<string, unknown>>
}
