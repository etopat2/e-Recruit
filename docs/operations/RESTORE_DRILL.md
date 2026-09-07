# Restore drill evidence

## Completed local isolated drill

- Drill/change reference: final engineering acceptance restore drill
- Date and environment: 2026-09-02 UTC; Docker Desktop; isolated temporary PostgreSQL/MinIO targets; synthetic data only
- Operator: automated guarded scripts under engineering supervision
- Source release commit: `8569894`
- Backup location: ignored local `backups/acceptance/20260902T051416Z`
- Backup created: `2026-09-02T05:31:52.931131053Z`
- Restore verified: `2026-09-02T05:38:03.164978700Z`
- Observed artifact-to-verification window: approximately 6 minutes 11 seconds
- Source/restored database counts: applications 1, documents 1, audit events 0
- Database SHA-256: `4d3cf9c2506c08c3ca8dc7b486c497b844bc1f2d3cb1b53aa82d1d36926259a3`
- Object manifest SHA-256: `0cf9d9c053d539bf409bc1beb745eb564a0e6b44109c89df92b0140aac69e8c2`
- Restored document/object SHA-256: `1d79eb2dd74d3904b99f1fd43fc92f0a3794eff38fddc694babc830216914f17` on both database metadata and restored object
- Verification: guarded restore completed into new targets, counts and object hashes matched, application services returned healthy, and the isolated stack was removed afterward
- Data-loss observation: zero for the quiesced synthetic fixture; this is not a production RPO claim
- Exceptions: none in the local drill

This proves the committed procedure on an isolated developer environment. It does **not** satisfy the production go-live gate: Operations and Security must repeat it against the approved encrypted off-host backup target, measure from declared incident start, verify representative audit/offline workflows, and sign the stakeholder-approved RPO/RTO.

## Target-environment evidence template

Use `BACKUP_RESTORE.md`, `infra/scripts/backup.sh` and the guarded `infra/scripts/restore-test.sh`.

- Drill/change reference:
- Date, operators and isolated host:
- Release commit/image digests:
- Synthetic seed/count and generated object count:
- Backup start/end and SHA-256 manifest:
- Failure/reset start:
- Restore health achieved:
- Workflow verification completed:
- Source/restored DB counts and object hashes:
- Audit/offline idempotency verification:
- Achieved recovery duration and data-loss window:
- Exceptions/remediation owner/date:
- Operations, Security and Recruitment sign-off:
