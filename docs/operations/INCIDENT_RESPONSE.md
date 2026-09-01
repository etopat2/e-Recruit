# Incident response

## Priorities

1. Protect life/safety and applicant confidentiality; stop further exposure or corrupt writes.
2. Preserve logs, audit chain, image digests, timestamps and correlation IDs; do not alter evidence casually.
3. Revoke affected tokens/devices/credentials and isolate the smallest affected service.
4. Restore authoritative DB/object services from tested evidence; reconcile queues and offline event ledgers.
5. Notify UPS security/privacy/legal and affected stakeholders through approved channels according to policy.

## Triage record

Record incident ID, reporter/time/timezone, systems/data classes, synthetic versus real data, last known good state, current release digests, correlation/application internal IDs, affected roles/scopes/devices, containment, owner and next update. Do not put full NIN, medical note, OCR payload or credential in the record.

## Specific containment

- Lost field device: revoke registered device and packs/tokens, identify last acknowledgement, preserve server event ledger and reconcile paper fallback.
- Suspected account compromise: disable account/tokens, force secret/MFA reset, inspect scope/audit/export/download history.
- Object exposure: block edge/storage policy, rotate storage credential, enumerate authorised access/audit and preserve object versions.
- Integrity alert: freeze affected decisions/configuration, verify chain/fingerprints and compare trusted backup; do not “repair” logs.
- Wrong selection/medical disclosure: stop publication/invitations, preserve decision inputs/approval references and notify accountable/privacy owners.

Close only after recovery validation, backlog/conflict reconciliation, root cause, control remediation, notification decision and accountable-owner review.
