# Acceptance criteria matrix

Classification is intentionally evidence-based. “Manual validation required” means the software boundary exists and automated lower-level tests pass, but an authorised UPS pilot or production dependency is still required.

| Criterion | Implementation evidence | Verification evidence | Classification |
|---|---|---|---|
| AC-01 Campaign configurability | `CampaignController`, campaign/post/version, requirements, stages, geography, centres and policy snapshot tables; staff configuration UI | Migration-from-clean, public campaign API, controller validation and publication guardrails | Implemented and tested |
| AC-02 Save/resume/submit/reference | Versioned drafts, IndexedDB fallback, atomic submission snapshot, idempotency key, locked post-submit state, reference sequence, QR/PDF | `ApplicationSubmissionTest`, `ApplicationReferenceServiceTest`, frontend unit and browser tests | Implemented and tested |
| AC-03 Multi-source document intelligence | Private versioned documents, bounded worker, source/provenance/confidence/boxes, pairwise comparison; no OCR rejection path | Worker Pytest, `EvidenceComparisonServiceTest`, malware-signature feature test | Implemented and tested; ClamAV integration validation required in staging |
| AC-04 Same-viewport verification | Protected evidence preview and application/evidence matrix in one workbench; extracted-field selection and human verified-value/discrepancy decisions | Frontend build/unit coverage and API policies; keyboard/source-highlight pilot script | Implemented; manual validation required |
| AC-05 Offline work/no duplicate sync | Device-bound, scoped, expiring manifest; IndexedDB outbox; UUID acknowledgement ledger | `OfflineSyncTest` proves duplicate UUID changes a score once | Implemented and tested |
| AC-06 Protected conflict visibility | Optimistic entity version, conflict row, protected fields, blocked panel/selection gates, authorised resolution | Service/API conflict tests and offline conflict UI; two-device field drill remains required | Implemented; manual field validation required |
| AC-07 Reproducible ranking/capping | Ranking snapshots, explicit tie policy, quota/skill reservation service, input/output fingerprints, scenario/final run and audited override | `SelectionServiceTest`; selection certification blocks unsynchronised work | Implemented and tested; official UPS rules required |
| AC-08 Medical/training privacy | Restricted medical policy/payload, certified+selected+Fit Council gate, invitations/reporting, reserve promotion | `FinalSelectionApprovalTest`, medical policies, training gate | Implemented and tested; clinical-role UAT required |
| AC-09 Sensitive action auditability | Redacted append-oriented hash chain, request correlation IDs, approval references and integrity review | Authentication, submission and final-selection feature assertions; chain verification endpoint | Implemented and tested |
| AC-10 Backup/security/pilot readiness | Consistent DB/object backup, isolated destructive restore script, secure headers, operations/security/pilot runbooks | Compose validation and health automation | Implemented; restore, DAST, load, penetration and pilot drills require target environment sign-off |

Explicitly deferred by the approved scope: UNEB integration; future NIRA integration pending lawful approval/access; USSD and native apps. Excluded items are not acceptance failures.
