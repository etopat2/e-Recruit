import Dexie, { type EntityTable } from 'dexie'

export interface LocalDraft {
  id: string
  entityVersion: number
  data?: Record<string, unknown>
  sealedData?: SealedValue
  updatedAt: string
  syncState: 'clean' | 'pending' | 'conflict'
}

export interface OfflineEvent {
  id: string
  packageId: string
  entity_type: string
  entity_id: string
  action_type: string
  payload_schema_version: number
  payload?: Record<string, unknown>
  sealedPayload?: SealedValue
  base_entity_version: number
  local_sequence: number
  local_timestamp: string
  state: 'pending' | 'accepted' | 'rejected' | 'conflict'
  error?: string
}

export interface SealedValue { ciphertext: string; iv: string }
export interface CachedOfflinePackage {
  id: string
  manifestFingerprint: string
  expiresAt: string
  sealedPayload: SealedValue
}

interface DeviceKeyRecord { id: 'device-key'; key: CryptoKey }

class ERecruitDatabase extends Dexie {
  drafts!: EntityTable<LocalDraft, 'id'>
  events!: EntityTable<OfflineEvent, 'id'>
  packages!: EntityTable<CachedOfflinePackage, 'id'>
  deviceKeys!: EntityTable<DeviceKeyRecord, 'id'>

  constructor() {
    super('ups-erecruit')
    this.version(1).stores({ drafts: 'id, syncState, updatedAt', events: 'id, packageId, state, local_sequence' })
    this.version(2).stores({
      drafts: 'id, syncState, updatedAt',
      events: 'id, packageId, state, local_sequence',
      packages: 'id, expiresAt',
      deviceKeys: 'id',
    })
  }
}

export const offlineDb = new ERecruitDatabase()

const encoder = new TextEncoder()
const decoder = new TextDecoder()
const toBase64 = (value: Uint8Array): string => btoa(Array.from(value, (byte) => String.fromCharCode(byte)).join(''))
const fromBase64 = (value: string): ArrayBuffer => Uint8Array.from(atob(value), (character) => character.charCodeAt(0)).buffer as ArrayBuffer

async function deviceKey(): Promise<CryptoKey> {
  const existing = await offlineDb.deviceKeys.get('device-key')
  if (existing) return existing.key
  const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
  await offlineDb.deviceKeys.put({ id: 'device-key', key })
  return key
}

export async function sealValue(value: unknown): Promise<SealedValue> {
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const encrypted = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, await deviceKey(), encoder.encode(JSON.stringify(value)))
  return { ciphertext: toBase64(new Uint8Array(encrypted)), iv: toBase64(iv) }
}

export async function openValue<T>(sealed: SealedValue): Promise<T> {
  const decrypted = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: fromBase64(sealed.iv) }, await deviceKey(), fromBase64(sealed.ciphertext))
  return JSON.parse(decoder.decode(decrypted)) as T
}

export async function putLocalDraft(draft: LocalDraft & { data: Record<string, unknown> }): Promise<void> {
  const { data, ...metadata } = draft
  await offlineDb.drafts.put({ ...metadata, sealedData: await sealValue(data) })
}

export async function getLocalDraft(id: string): Promise<LocalDraft | undefined> {
  const draft = await offlineDb.drafts.get(id)
  if (!draft) return undefined
  if (draft.data) return draft
  return draft.sealedData ? { ...draft, data: await openValue<Record<string, unknown>>(draft.sealedData) } : draft
}

export async function putOfflinePackage(packageRecord: Omit<CachedOfflinePackage, 'sealedPayload'>, payload: unknown): Promise<void> {
  await offlineDb.packages.put({ ...packageRecord, sealedPayload: await sealValue(payload) })
}

export async function getOfflinePackage<T>(id: string): Promise<(CachedOfflinePackage & { payload: T }) | undefined> {
  const packageRecord = await offlineDb.packages.get(id)
  if (!packageRecord) return undefined
  return { ...packageRecord, payload: await openValue<T>(packageRecord.sealedPayload) }
}

export async function purgeOfflineData(): Promise<void> {
  await offlineDb.transaction('rw', [offlineDb.drafts, offlineDb.events, offlineDb.packages, offlineDb.deviceKeys], async () => {
    await Promise.all([offlineDb.drafts.clear(), offlineDb.events.clear(), offlineDb.packages.clear(), offlineDb.deviceKeys.clear()])
  })
  localStorage.removeItem('ups_package_id')
  localStorage.removeItem('ups_device_record_id')
}
