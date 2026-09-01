import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const contractUrl = new URL('../../docs/api/openapi.json', import.meta.url)
const contract = JSON.parse(await readFile(contractUrl, 'utf8'))

test('OpenAPI contract has a version and unique operation identifiers', () => {
  assert.match(contract.openapi, /^3\.1\./)
  assert.equal(contract.info.title, 'UPS e-Recruit API')
  const operationIds = Object.values(contract.paths)
    .flatMap((path) => Object.values(path))
    .map((operation) => operation.operationId)
    .filter(Boolean)
  assert.equal(operationIds.length, new Set(operationIds).size)
})

test('OpenAPI contract covers the public and high-risk workflow boundaries', () => {
  const required = [
    '/v1/health/live',
    '/v1/campaigns',
    '/v1/auth/login',
    '/v1/applications/{application}',
    '/v1/applications/{application}/submit',
    '/v1/applications/{application}/verification-workbench',
    '/v1/offline/packages/{offlinePackage}/sync',
    '/v1/selection-runs/{selectionRun}/certify',
    '/v1/medical/results',
    '/v1/final-selections',
  ]
  for (const path of required) assert.ok(contract.paths[path], `missing ${path}`)
})
