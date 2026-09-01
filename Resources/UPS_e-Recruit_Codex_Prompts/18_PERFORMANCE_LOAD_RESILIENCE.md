# Codex Prompt 18 — Performance, Load, Resilience, Backup and Disaster-Recovery Validation

Implement and document operational validation for national recruitment load without over-engineering infrastructure.

## Performance/load tests

Use k6 or a maintained equivalent. Create scripts for:

- anonymous campaign/public pages;
- applicant login/form autosave;
- final submission/reference issuance;
- concurrent document upload initiation;
- status lookup;
- officer application-list filtering;
- attendance check-in;
- offline sync batch ingestion;
- dashboard queries;
- selection-run execution on large synthetic datasets.

Do not benchmark OCR synchronously; test OCR queue throughput separately because processing is asynchronous.

Generate synthetic datasets at configurable scales such as 10k, 50k and 150k applicants where the environment permits. Record dataset size and hardware so results are meaningful.

## Performance targets

Use the specification baseline: typical non-file server actions should target <1.5s under normal expected load and the system should be tested for deadline surges. Establish reasonable p95/p99 targets per endpoint in `docs/testing/PERFORMANCE.md`.

Add indexes/query optimisations discovered during tests; prove improvements with explain plans or before/after metrics when practical.

## Queue resilience

Test failure/retry behaviour for OCR, email/SMS, PDF and export jobs. Add dead-letter/failed-job operational procedures and admin visibility where appropriate.

## Backup

Create scripts/runbook for:

- PostgreSQL logical backup and restore;
- object-storage backup/snapshot strategy;
- encryption at rest/in backup destination;
- configuration/secret backup procedure without placing secrets in repo;
- consistency procedure linking DB metadata and stored objects.

## RPO/RTO

Provide configurable target recommendations and require UPS/hosting stakeholders to approve actual production values. Do not invent an official RPO/RTO as policy.

## Restore drill

Create `docs/operations/RESTORE_DRILL.md` with a test environment procedure:

1. seed synthetic application/documents;
2. take DB/object backup;
3. destroy/reset test stack;
4. restore;
5. verify counts/checksums/key workflow data;
6. run smoke tests.

Automate as much as safely possible in `infra/scripts/restore-test.sh` or equivalent.

## Resilience

Demonstrate app behaviour when Redis, document worker, SMTP/SMS and MinIO are temporarily unavailable. Critical application records should not be lost; asynchronous work should become pending/failed with recovery steps.
