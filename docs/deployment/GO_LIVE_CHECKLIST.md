# Go-live checklist

No unchecked release blocker may be inferred as approved. Add evidence links/change references next to each item.

## Engineering evidence recorded 2026-09-08

- [x] Clean migration/seed and full automated API/web/worker/contract suite passed.
- [x] Desktop/mobile end-to-end suite passed 26/26, including source highlighting and true two-context offline conflict resolution.
- [x] Dependency and Git-history secret scans passed after documented narrow false-positive handling.
- [x] Final API, web and document-worker runtime images run unprivileged and have zero fixed HIGH/CRITICAL Trivy findings.
- [x] Local production-path k6 smoke passed at 12 VUs: 1,628 requests, 0% failures, p95 452.61 ms.
- [x] Local isolated database/object restore completed with matching counts and SHA-256 evidence.

These are engineering prerequisites only. They do not check or supersede any owner-controlled item below. Evidence: `docs/testing/FINAL_TEST_REPORT.md` and `docs/operations/RESTORE_DRILL.md`.

## Infrastructure and security

- [ ] Operations owner: immutable image digests/tag approved and production Compose/config reviewed.
- [ ] Hosting owner: capacity, private network, DNS and TLS certificate/renewal tested.
- [ ] Security owner: all placeholders/defaults replaced; secret rotation and trusted proxy configuration verified.
- [ ] Security owner: dependency/container/DAST scans and independent penetration test have no unresolved high/critical issue.
- [ ] Privacy owner: DPIA, retention/legal-hold/purge and export policy approved.
- [ ] Operations owner: encrypted off-host backup and isolated restore drill meet stakeholder-approved RPO/RTO.
- [ ] Operations owner: monitoring/alerts, log retention, on-call, incident and rollback exercises passed.

## Recruitment data and rules

- [ ] Recruitment owner: campaign dates/timezone, posts, stages, age cutoff and document/hard-copy rules signed.
- [ ] Data owner: authoritative administrative hierarchy, jurisdictions, regions, centres and reference prefixes imported/reconciled.
- [ ] Recruitment/Council: eligibility, assessments, tie breaks, quotas, skills, fallback and override authorities signed.
- [ ] Communications: privacy notice, declaration, acknowledgement, invitations, result/helpdesk/appeal templates approved.
- [ ] Brand owner: supplied logo, derived favicon/PWA treatment and final colours approved.

## People and journeys

- [ ] IAM owner: production staff accounts/scopes, MFA, recovery and leaver/device revocation tested.
- [ ] Centre owner: devices, panel users, pack expiry, QR/manual check-in and two-device conflict drill passed.
- [ ] Verification lead: same-viewport evidence/source highlight, discrepancy and verified-value process signed.
- [ ] Medical lead: restricted-note isolation and Fit/final-approval gate signed.
- [ ] PATS/training lead: invitation, reporting and reserve replacement signed.
- [ ] Helpdesk lead: tickets, appeals, approved support contacts and incident escalation rehearsed.
- [ ] Accessibility owner: axe and manual keyboard/screen-reader/zoom evidence accepted.

## Capacity and dependencies

- [ ] Performance owner: production-like load/deadline surge and OCR queue throughput meet agreed thresholds.
- [ ] Messaging owner: real SMTP and any approved SMS adapter success/failure/retry monitoring tested.
- [ ] Security owner: ClamAV/malware EICAR test and protected-object access test passed.
- [ ] Operations owner: PostgreSQL/object store health, capacity, backup and storage lifecycle alerts passed.
- [ ] Release commander: final smoke, queue/offline reconciliation and rollback decision window confirmed.

## Approval

- [ ] Recruitment accountable owner — name/date/reference:
- [ ] ICT/Operations accountable owner — name/date/reference:
- [ ] Security and Privacy accountable owner — name/date/reference:
- [ ] Pilot owner — name/date/reference:
- [ ] Release commander GO decision — name/date/reference:
