# UPS e-Recruit requirements traceability

Updated: 2026-09-08. Classifications use the vocabulary required by prompt 20. “Manual validation required” is not a waiver or production approval.

| Requirement / section | Implementation evidence | Verification evidence | Classification |
|---|---|---|---|
| Architecture baseline | Laravel modular API, Vue/TypeScript PWA, bounded Python worker, PostgreSQL, Redis, S3/MinIO, Nginx and development/production Compose | Clean build/migration, production images, health/readiness and OpenAPI contract | Implemented and tested |
| FR-CAM-001..008 / AC-01 | Versioned campaigns/posts/forms/stages, geography, centres, requirements, policy snapshots and publication guardrails; staff UI | Campaign/geography API tests, clean seed and browser configuration coverage | Implemented and tested |
| FR-APP-001..004 / AC-02 | Registration/access, IndexedDB draft, dynamic forms, resumable checksummed upload, atomic submit/reference, acknowledgement/QR/PDF and status/inbox | Laravel submission/reference tests plus complete Playwright applicant journey | Implemented and tested |
| FR-DOC-001..007 / AC-03 | Private immutable originals, MIME/signature/size/malware gate, bounded OCR worker, page quality, confidence, provenance, normalized source boxes and pairwise comparison; OCR never rejects | Worker tests, ingestion/comparison tests and verification browser journey | Implemented and tested; staging ClamAV validation required |
| FR-REV-001..010 / AC-04 | Same workbench shows application, evidence matrix and protected original; reviewer decisions are versioned and source focus persists | API policy tests and Playwright source-focus/highlight/decision journey | Implemented and tested; supervised verification UAT required |
| FR-HC-001..006 | Reference/applicant reconciliation, receipt capture, duplicate controls and downstream workflow | API tests and Playwright hard-copy journey | Implemented and tested |
| FR-ELG requirements | Versioned human-verified values drive configurable eligibility with explainable outcome; OCR is non-authoritative | Eligibility service/controller feature tests | Implemented and tested; official policy required |
| FR-INT-001..006 | Configurable centres/panels, capacity, deterministic assignment, invitation/check-in/attendance and panel closure | Scheduling/attendance/panel API tests and Playwright journeys | Implemented and tested; centre pilot required |
| FR-OFF-001..010 / AC-05..06 | User/device/scope/action/time-bound encrypted packs, PIN unlock, idempotent UUID outbox, optimistic versions, visible protected conflicts and supervisor resolution | Offline feature tests and browser tests including reload lock and two independent browser contexts | Implemented and tested; managed-device field drill required |
| FR-ASMT requirements | Versioned criteria, assignment, scoring, validation/import and closure gates | Assessment API/service tests and panel browser journey | Implemented and tested |
| FR-SEL-001..005 / AC-07 | Immutable ranking snapshots, explicit ties, quota/skill reservation, fingerprints, scenario/final runs, audited override, sync-conflict certification gate | Selection tests and Playwright selection/reserve recommendation/independent approval | Implemented and tested; official rules/signatories required |
| FR-TRN-001..004 / AC-08 | Restricted medical payload/policy, selected-and-fit approval gate, invitations, training reporting and strict reserve promotion | Final-selection/API policy tests and medical/PATS browser journeys | Implemented and tested; clinical/PATS UAT required |
| Notifications/helpdesk/reports | Queued templates/delivery states, applicant inbox, tickets/appeals and scoped dashboards/exports | Feature tests and browser status/inbox/helpdesk coverage | Implemented and tested; real provider/template approval required |
| Technical account administration | System-administrator-only directory, staff provisioning, role/status/scope updates, one-time password and MFA recovery, session revocation, optimistic versions, required reasons and last-administrator protection; recruitment decisions remain denied | Seven focused backend tests/54 assertions plus desktop/mobile provisioning and first-login browser journeys | Implemented and tested; production IAM roster and recovery ceremony approval required |
| Audit / AC-09 | PII-redacted append-oriented hash chain, correlation IDs, actor/scope/reason/approval metadata and integrity verification | Authentication, submission, offline, selection and audit feature assertions | Implemented and tested; external log-retention integration required |
| NFR-SEC-001..007 / AC-10 | RBAC/MFA, private uploads, encryption boundaries, security headers, rate limits, retention commands, backup/restore, incident/pilot/rollback runbooks and non-root minimized images | 53 backend tests, 30 browser tests, dependency/history/container scans, local restore and k6 production-path smoke | Implemented and tested; independent security/privacy, production restore/load and pilot sign-off required |

## Explicit specification decisions

- UNEB integration is excluded and has not been introduced.
- NIRA is deferred pending lawful approval and access.
- USSD and native mobile applications are deferred.
- Payments, facial recognition, suitability AI, blockchain, microservices and Kubernetes dependency are excluded.
- No v1.0 Must requirement is classified as missing. Remaining work is environment-, policy- or authority-dependent and is enumerated in `KNOWN_LIMITATIONS.md` and the go-live checklist.
