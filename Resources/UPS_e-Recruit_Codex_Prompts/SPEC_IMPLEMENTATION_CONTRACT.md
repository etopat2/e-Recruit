# UPS e-Recruit — Non-Negotiable Implementation Contract

This document condenses the final specification into implementation rules that every phase must preserve.

## Product scope

UPS e-Recruit is a reusable **Recruitment, Selection & Training Intake Management System**, not a Warder/Wardress-only application. Recruitment Campaigns and Recruitment Posts must support Warder/Wardress, CPO, CASP and future categories without code changes to basic workflow rules.

All age rules, qualifications, required documents, LC-source policy, stages, assessment components, weights, pass marks, quotas, tie-break rules, special-skill rules and dates are configuration data scoped to campaign/post/version.

## Architecture

Keep infrastructure deliberately simple:

- Installable PWA frontend.
- Laravel modular monolith backend.
- PostgreSQL system of record.
- S3-compatible protected document storage.
- Redis/queue for background work.
- Small Python document-processing worker for OCR/image processing.
- Nginx/reverse proxy.
- Docker Compose for local/test/pilot; production may use approved infrastructure and multiple app instances.
- No microservice sprawl and no Kubernetes dependency.

## Applicant and reference rules

- One active application per NIN per applicable campaign/post unless an authorised exception exists.
- Human application reference format is configurable but should resemble `UPS/2027/RW/000381`.
- Do not encode interview centre in the permanent application number.
- Final online submission generates the reference, QR code and acknowledgement.
- Where required, acknowledgement instructs applicant to write the reference on top of the physical application letter and submit hard copies to an approved UPS receiving point.

## Document intelligence

The system must compare **all relevant evidence sources against each other**, not only form versus document:

`Entered data ↔ National ID ↔ academic evidence ↔ LC letter ↔ application letter ↔ skill/professional evidence ↔ other campaign documents`.

It must retain three layers:

1. Source data — what each source says.
2. Comparison data — where sources agree/differ.
3. Verified data — values formally accepted by authorised UPS reviewers.

OCR/document extraction is assistive and never by itself rejects an applicant.

Required statuses include `VERIFIED/CONSISTENT`, `PROBABLE MATCH`, `DISCREPANCY`, `UNREADABLE/LOW CONFIDENCE`, and `NOT AVAILABLE`.

- Name comparison: normalised, token/order aware; material differences require review.
- NIN: exact normalised match where present.
- DOB and grades: exact after normalisation.
- Missing fields are not mismatches.
- Store raw OCR text, structured values, confidence, engine/version, page/region coordinates and original upload.
- No UNEB API/integration is planned, now or later under this baseline.
- NIRA is only an optional future integration if lawful, approved and technically available; core identity verification must work without it.

## Same-viewport verification workbench

At officer-review viewport widths (target >=1024 px), the selected scanned document and captured/extracted data must be visible side-by-side in the **same viewport**.

The workbench must provide:

- scanned document/PDF/image preview;
- page thumbnails and navigation;
- zoom/pan/rotate/fit;
- extracted fields with confidence;
- entered value;
- values from other documents;
- comparison outcome;
- final verified value;
- source badges;
- discrepancy reporting;
- per-field reviewer actions;
- bounding-box highlight/focus on the source region when coordinates exist;
- click a cross-document value to switch to its document/page/region;
- source tabs without leaving the workbench;
- applicant-wide evidence matrix;
- preservation of original values/files after correction or replacement.

The workbench must support authorised offline cases and later sync.

## Hard-copy workflow

Support QR/reference lookup, receiving office/officer/date, configurable checklist and per-item status such as Match, Different Document, Missing, Unreadable, Original Required at Interview. Do not overwrite online evidence. Hard-copy reception must work offline for scoped packs and sync later.

## Eligibility

Campaign-specific rule engine returns PASS, FLAG/REVIEW or FAIL with explanation. OCR uncertainty creates review, not automatic failure. Eligibility must reference verified values and rule version. Equivalent/unusual qualifications can be routed to manual review.

## Geography and LC routing

Origin and residence use structured hierarchy: District → County → Subcounty → Parish → Village.

Region counts, centre counts, districts, jurisdiction and panels are reference data and never hard-coded. Campaign controls LC source policy: Origin only, Residence only, Origin or Residence, or another approved rule.

Verified LC source + verified recruitment district → effective-dated jurisdiction mapping → interview centre → panel/session.

An unmapped district must go to an admin-resolution queue; never silently route by guessed “nearest” district.

## Offline PWA

Offline operation is core, not optional.

- Explicit scoped data packs; ordinary panel users may never download a national dataset.
- Pack types may include panel, verification, centre coordinator, medical and hard-copy reception.
- Use service worker + IndexedDB or equivalent.
- Show online/offline state, last sync, pending events, conflicts, pack expiry.
- Queue local events with globally unique event IDs.
- Sync must be idempotent.
- Use optimistic/entity versions for protected data.
- Do not silently use last-write-wins for score/status/verification conflicts.
- Independent fields may merge; conflicting protected fields create a `sync_conflict` requiring authorised resolution.
- Record registered device, pack owner/scope, issue/expiry, last sync, outstanding events and revocation.
- Protect local data with practical browser-compatible encryption/unlock controls and minimal-data packs; document limitations honestly.
- Ranking certification must warn/block when required panel data remain unsynchronised unless an audited exceptional override is explicitly permitted.

## Assessment

Assessment is generic, not limited to written/oral columns. Configure component type/name, maximum, pass mark, weight, assessor mode, aggregation and divergence threshold. Support independent panel-member scores, panel-head closure/locking, controlled reopen/correction, and validated written score imports.

## Ranking, quotas and selection

Ranking and official selection runs must be reproducible and versioned. Support configured ranking scope and quotas by authorised policy dimensions such as national total, region, district, gender and verified special-skill reservation.

A skill cannot influence selection until evidence is VERIFIED. Do not silently add AI or hidden bonus marks. Manual promotion/demotion/replacement must require reason, actor, timestamp and approval; preserve pre-override and post-override results.

Store selection-run inputs, rule versions, score formula, threshold, quotas, skill rules, tie-break policy, output and fingerprint/hash where practical.

## Medical and training

Use minimum necessary medical data. General recruitment users normally see only outcome. Support Fit, Not Fit, Deferred, Further Assessment Required, No Show. Reserve replacements require approved workflow. Final list derives from approved selection + required medical result + final approval. Never publish full NIN by default.

Training module supports invitation, Expected/Reported/Verified/Admitted/Late/Documentation Incomplete/No Show/Withdrawn/Replacement and controlled export/API handoff to downstream HR where approved.

## Notifications and applicant service

Support email; SMS through an approved gateway where configured; in-portal notifications; PWA push if supported. Log delivery attempts/retries. Secure tracking may use authenticated login/OTP/challenge; application number alone must not expose sensitive status.

Include structured helpdesk/enquiry and campaign-policy-dependent appeals with evidence and downstream recalculation when an approved appeal changes verified data.

## Security and governance

- HTTPS/TLS.
- MFA for privileged staff.
- Server-side role and scope enforcement on every protected endpoint.
- Least privilege by national/region/centre/panel/campaign/post/stage/task.
- Restricted medical access.
- Strict upload validation, malware scan and checksums.
- Protected object storage and encrypted backups.
- Append-only/immutable-in-practice audit logging for sensitive writes.
- Audit score corrections, overrides, publications, exports, offline events and conflict resolutions.
- Never hard-delete active-cycle evidence through normal operational UI.
- Data retention and purge are controlled and auditable.

## Explicit exclusions

Do not implement UNEB integration, AI candidate suitability scoring, facial recognition, blockchain, payment processing, native mobile apps, microservice architecture or a Kubernetes requirement. USSD and NIRA remain optional/deferred capabilities, not prerequisites.

## Testing baseline

The final repository must have meaningful automated tests for backend, frontend, document worker, APIs, RBAC/scope, PWA offline behaviour, idempotent sync/conflicts, ranking/capping reproducibility, upload security, exports and critical user journeys. Include performance/load, security scanning, backup/restore and offline field-drill instructions.
