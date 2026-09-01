# Codex Prompt 17 — Comprehensive Automated Testing and Acceptance Suite

Build the test suite required to trust this system. Do not only write a testing document—add executable tests and fixtures.

## Backend tests

Use Pest or PHPUnit consistently. Cover:

- domain unit tests;
- API integration tests;
- database constraints;
- RBAC/scope matrix;
- applicant submission and reference generation;
- campaign versioning;
- document/evidence comparison;
- eligibility rules;
- hard-copy workflow;
- interview assignment/attendance;
- assessment calculation;
- selection/capping reproducibility;
- medical/training gates;
- notifications;
- exports;
- audit events;
- sync idempotency/conflicts.

## Frontend tests

Use Vitest + Vue Testing Library (or maintained equivalent) for components/stores and Playwright for critical journeys.

Critical Playwright journeys:

1. Applicant registration → dynamic form → upload synthetic evidence → review → submit → acknowledgement.
2. Verification officer opens same-viewport workbench → clicks evidence field → source highlight → records verified value/discrepancy.
3. Hard-copy receiving officer records receipt.
4. Centre coordinator schedules candidates.
5. Panel user downloads pack → browser goes offline → check-in and scores → reconnect → sync.
6. Two devices create protected conflict → authorised conflict resolution.
7. Panel head closes session.
8. HQ runs ranking/selection scenario → verifies quotas → certifies.
9. Medical officer records result → approved reserve replacement.
10. Training/PATS records reporting.

## Python/document tests

Use Pytest with synthetic generated fixtures. Cover preprocessing, OCR parser fallbacks, extraction schemas, field confidence, bounding boxes, timeouts and malformed files. Keep tests deterministic; where OCR engine variability makes exact text unstable, separate parser tests from limited OCR smoke tests.

## Contract and integration tests

Validate API/OpenAPI schema and Python worker contract. Add database migration-from-clean and upgrade tests where feasible.

## PWA/offline tests

Playwright must explicitly test browser offline mode, IndexedDB persistence, pending counters, retry/idempotency, expiry and conflict UI.

## Accessibility

Add automated axe checks on key applicant/staff pages and document manual screen-reader/keyboard checks.

## Test data

Create a synthetic fixture generator capable of producing thousands of applicants without real PII. Include deterministic random seed and scenarios for quota/skills/ties/discrepancies.

## Acceptance criteria mapping

Create automated or manual evidence for AC-01 through AC-10 and update `docs/testing/ACCEPTANCE_MATRIX.md`.

## CI

Make CI execute the reliable test subset on every merge/pull request. Keep heavier load/ZAP/OCR integration suites in scheduled/manual pipeline if runtime is excessive.

Run the full feasible suite and fix failures.
