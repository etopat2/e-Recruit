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
