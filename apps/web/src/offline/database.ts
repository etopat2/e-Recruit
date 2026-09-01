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
interface WrappedPackKeyRecord { id: 'pack-key'; ciphertext: string; iv: string; salt: string; iterations: number; version: 1 }

class ERecruitDatabase extends Dexie {
  drafts!: EntityTable<LocalDraft, 'id'>
  events!: EntityTable<OfflineEvent, 'id'>
  packages!: EntityTable<CachedOfflinePackage, 'id'>
  deviceKeys!: EntityTable<DeviceKeyRecord, 'id'>
  packKeys!: EntityTable<WrappedPackKeyRecord, 'id'>

  constructor() {
    super('ups-erecruit')
    this.version(1).stores({ drafts: 'id, syncState, updatedAt', events: 'id, packageId, state, local_sequence' })
    this.version(2).stores({
      drafts: 'id, syncState, updatedAt',
      events: 'id, packageId, state, local_sequence',
      packages: 'id, expiresAt',
      deviceKeys: 'id',
    })
    this.version(3).stores({
      drafts: 'id, syncState, updatedAt',
      events: 'id, packageId, state, local_sequence',
      packages: 'id, expiresAt',
      deviceKeys: 'id',
      packKeys: 'id',
    })
  }
}

export const offlineDb = new ERecruitDatabase()
let unlockedPackKey: CryptoKey | null = null

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

export async function offlineUnlockState(): Promise<{ configured: boolean; unlocked: boolean }> {
  return { configured: Boolean(await offlineDb.packKeys.get('pack-key')), unlocked: unlockedPackKey !== null }
}

export async function configureOfflineUnlock(pin: string): Promise<void> {
  validatePin(pin)
  if (await offlineDb.packKeys.get('pack-key')) throw new Error('An offline unlock credential is already configured on this browser.')
  if ((await offlineDb.events.where('state').equals('pending').count()) > 0 || (await offlineDb.packages.count()) > 0) throw new Error('Reconcile or revoke legacy offline packs before configuring a new unlock credential.')
  const salt = crypto.getRandomValues(new Uint8Array(16)); const iv = crypto.getRandomValues(new Uint8Array(12)); const iterations = 310_000
  const generated = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt'])
  const raw = await crypto.subtle.exportKey('raw', generated)
  const wrapped = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, await pinKey(pin, salt, iterations), raw)
  await offlineDb.packKeys.put({ id: 'pack-key', ciphertext: toBase64(new Uint8Array(wrapped)), iv: toBase64(iv), salt: toBase64(salt), iterations, version: 1 })
  unlockedPackKey = await crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt'])
}

export async function unlockOfflineData(pin: string): Promise<void> {
  validatePin(pin)
  const wrapped = await offlineDb.packKeys.get('pack-key')
  if (!wrapped) throw new Error('No offline unlock credential is configured on this browser.')
  try {
    const raw = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: fromBase64(wrapped.iv) }, await pinKey(pin, new Uint8Array(fromBase64(wrapped.salt)), wrapped.iterations), fromBase64(wrapped.ciphertext))
    unlockedPackKey = await crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt'])
  } catch {
    throw new Error('The offline unlock PIN is incorrect.')
  }
}

export function lockOfflineData(): void { unlockedPackKey = null }

export async function sealOfflineValue(value: unknown): Promise<SealedValue> {
  if (!unlockedPackKey) throw new Error('Unlock field mode before storing protected offline data.')
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const encrypted = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, unlockedPackKey, encoder.encode(JSON.stringify(value)))
  return { ciphertext: toBase64(new Uint8Array(encrypted)), iv: toBase64(iv) }
}

export async function openOfflineValue<T>(sealed: SealedValue): Promise<T> {
  if (!unlockedPackKey) throw new Error('Unlock field mode before opening protected offline data.')
  const decrypted = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: fromBase64(sealed.iv) }, unlockedPackKey, fromBase64(sealed.ciphertext))
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
  await offlineDb.packages.put({ ...packageRecord, sealedPayload: await sealOfflineValue(payload) })
}

export async function getOfflinePackage<T>(id: string): Promise<(CachedOfflinePackage & { payload: T }) | undefined> {
  const packageRecord = await offlineDb.packages.get(id)
  if (!packageRecord) return undefined
  return { ...packageRecord, payload: await openOfflineValue<T>(packageRecord.sealedPayload) }
}

export async function purgeOfflineData(): Promise<void> {
  await offlineDb.transaction('rw', [offlineDb.drafts, offlineDb.events, offlineDb.packages, offlineDb.deviceKeys, offlineDb.packKeys], async () => {
    await Promise.all([offlineDb.drafts.clear(), offlineDb.events.clear(), offlineDb.packages.clear(), offlineDb.deviceKeys.clear(), offlineDb.packKeys.clear()])
  })
  lockOfflineData()
  localStorage.removeItem('ups_package_id')
  localStorage.removeItem('ups_device_record_id')
}

async function pinKey(pin: string, salt: Uint8Array<ArrayBuffer>, iterations: number): Promise<CryptoKey> {
  const material = await crypto.subtle.importKey('raw', encoder.encode(pin), 'PBKDF2', false, ['deriveKey'])
  return crypto.subtle.deriveKey({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, material, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
}

function validatePin(pin: string): void {
  if (!/^\d{6,12}$/.test(pin)) throw new Error('Use a private 6–12 digit offline PIN.')
}
