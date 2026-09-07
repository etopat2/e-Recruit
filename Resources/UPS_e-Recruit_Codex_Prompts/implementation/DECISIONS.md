# UPS e-Recruit Architecture Decisions

## ADR-001 — Specification baseline

The full DOCX specification and `SPEC_IMPLEMENTATION_CONTRACT.md` are the implementation contract. Campaign policy is data, never a hidden source-code constant.

## ADR-002 — Greenfield monorepo

Use `apps/api` (Laravel modular monolith), `apps/web` (Vue 3 + TypeScript PWA), `services/document-worker` (Python 3.12), `infra`, `docs`, and `tests`. This follows the prescribed low-operational-complexity architecture.

## ADR-003 — Container-first runtime

The host XAMPP PHP is 8.0.30 and cannot satisfy the PHP 8.3+ baseline. Development, CI and deployment use pinned Docker images. Host Node is suitable; the worker is pinned to Python 3.12 even though a newer host interpreter is also installed.

## ADR-004 — Same-origin production boundary

Nginx exposes the frontend and `/api` on one origin. PostgreSQL, Redis, MinIO administrative endpoints and the document worker remain internal in production profiles.

## ADR-005 — Evidence authority

OCR produces source evidence and comparison suggestions only. Authorised reviewer-versioned verified values drive eligibility. No majority vote or OCR-only failure is allowed.

## ADR-006 — Brand assets

`Resources/logo.png` is the application logo. A square favicon/PWA icon is derived from it without replacing or distorting the original.

## ADR-007 — Offline data at rest

An offline field pack is encrypted before IndexedDB persistence with a non-exportable Web Crypto key derived from the operator PIN and pack salt. A reload returns to a locked state; pack identity, user, device, scope, actions and expiry are validated independently by the server. Browser storage is treated as controlled temporary data, not a system of record.

## ADR-008 — Production artifact separation

Composer/compilers and Node build dependencies remain in build stages. The API, web and worker production stages run as unprivileged users and contain only runtime application files and libraries. OS packages are refreshed during release builds and fixed HIGH/CRITICAL findings block acceptance.

## ADR-009 — Release truthfulness

Completion of the v1.0 software scope permits a staging/pilot release-candidate decision, not an automatic production-ready claim. Authoritative policy/data, approved infrastructure/credentials, independent security/privacy/accessibility checks, target load/restore evidence, field/clinical UAT and named accountable signatures remain explicit external GO gates.
