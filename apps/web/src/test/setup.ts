import '@testing-library/jest-dom/vitest'

Object.defineProperty(globalThis, 'crypto', {
  value: { ...globalThis.crypto, randomUUID: () => '9c8b676e-3eca-4b9e-a3b8-d972b7d6d25f' },
  configurable: true,
})
