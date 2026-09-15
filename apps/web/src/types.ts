export interface User {
  id: number
  name: string
  email: string | null
  phone: string | null
  user_type: string
  status: string
  is_privileged: boolean
  mfa_method?: 'authenticator' | 'email' | null
  mfa_confirmed: boolean
  must_change_password: boolean
  scopes: Array<{ scope_type: string; scope_id: string | null; allowed_tasks: string[] }>
}

export interface CampaignPost {
  id: string
  code: string
  name: string
  description: string
  sections: Record<string, { required?: boolean } | boolean>
  lc_source_policy?: 'origin' | 'residence' | 'origin_or_residence' | 'custom'
  hard_copy_required: boolean
}

export interface CampaignStage {
  stage_code: string
  name: string
  sequence: number
  required: boolean
}

export interface ApplicationTimelineEvent {
  status: string
  at: string
  reason?: string | null
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
  stages?: CampaignStage[]
  timeline: ApplicationTimelineEvent[]
}
