# Architecture

## System boundary

The platform is a modular monolith with one public origin. Nginx terminates the HTTP boundary and routes `/api` to Laravel and all other paths to the Vue PWA. PostgreSQL is the source of truth; Redis provides cache and queues; MinIO/S3 stores private originals and generated artefacts; the Python worker is reachable only by the API with an internal token.

```text
Browser/PWA -> Nginx -> Vue static application
                    -> Laravel API -> PostgreSQL
                                   -> Redis queue/cache
                                   -> private S3/MinIO
                                   -> bounded OCR worker -> Tesseract
                                   -> SMTP/SMS provider adapters
```

## Domain modules

1. Identity: applicant registration, staff authentication, token expiry, MFA/recovery, roles and scoped tasks.
2. Campaigns: templates, immutable versions, posts, stages, document requirements, geography, centres and mappings.
3. Applications: optimistic drafts, one-active constraint, atomic submission snapshot, references, QR/PDF acknowledgement and timeline.
4. Evidence: versioned originals, processing jobs, OCR provenance, pairwise comparisons, verified-value history and hard-copy reconciliation.
5. Decisions: eligibility rule runs, interview assignment, attendance, assessment definitions/scores, ranking, quota/skill selection and overrides.
6. Field operations: registered devices, expiring scoped packs, idempotent event outbox, acknowledgements and explicit protected-field conflict resolution.
7. Post-selection: restricted medical outcomes, final approvals, training invitations/reporting and strict reserve promotion.
8. Governance: notifications, helpdesk, appeals, purpose-bound exports, hash-chained audit, integrity flags, retention, legal holds and purge approvals.

## Invariants

- Campaign versions referenced by applications and runs are never mutated.
- Applicant declarations do not become verified values without an authorised evidence decision.
- References use `UPS/{year}/{post}/{sequence}` and do not depend on centre allocation.
- Official decisions preserve inputs, algorithm/rule version, explanation and actor.
- All offline writes are events; the server remains authoritative.
- Audit log model updates/deletes are blocked and every entry commits the previous hash.

## Failure modes

- OCR unavailable/low confidence: document remains reviewable; it does not reject the applicant.
- Object storage unavailable: upload/submission fails atomically with a retryable response.
- Draft version conflict: HTTP 409 returns the server record; silent last-write-wins is prohibited.
- Offline conflict: event is retained, surfaced and blocks panel/selection closure until resolved.
- Notification provider failure: retry with status/error history; the core transaction remains committed.
