# Operations handbook

## Service health

- API liveness: `/api/v1/health/live`
- API readiness: `/api/v1/health/ready` (database, cache and protected storage)
- Worker liveness/readiness: port 8001 internally at `/health/live` and `/health/ready`
- Edge health: `/healthz`

Alert on elevated 5xx/429, queue age, failed jobs, OCR latency/failure, object-store errors, database saturation, open sync conflicts, unsynchronised packs near expiry, notification retry exhaustion and audit-chain failure.

## Deployment sequence

1. Record image digests, database backup identifier, migration plan and rollback owner.
2. Deploy backward-compatible code and run `php artisan migrate --force` once.
3. Run config/route cache in production, start workers/scheduler, then switch health-checked traffic.
4. Exercise liveness/readiness, applicant login, protected download, queue processing and audit write.
5. Monitor the release window; roll application images back if needed. Database rollback requires an approved migration-specific procedure, never blind `migrate:rollback`.

## Background processes

Run at least one durable `php artisan queue:work --queue=default --tries=5 --backoff=5` process and one scheduler invocation each minute (`php artisan schedule:run`). Stop workers gracefully before image replacement and restart them after deployment.

## Interview allocation runbook

1. Confirm every validated candidate has an LC1-supported routing address. For an `origin_or_residence` post, the application must state whether its LC1 letter supports the place of origin or current residence; submission persists that district as the routing district.
2. Maintain an effective district-to-centre jurisdiction mapping and active scheduled centre sessions with open panels and sufficient capacity.
3. In **Operations → Allocate interview candidates**, select the recruitment post and prison region, then create a preview. The server assigns each district as an indivisible unit using the greedy longest-processing-time heuristic: districts are processed by candidate count, largest first, and assigned to the currently least-loaded eligible centre.
4. Review the version number, candidate total, per-centre loads, and district-to-centre table. Reference-data or candidate changes invalidate the preview fingerprint; generate a new version instead of editing the stored preview.
5. Commit only an accepted preview. Commit persists interview assignments and queues the existing protected invitation workflow. Once attendance, scoring, or invitation evidence exists, that allocation cannot be replaced through rerun.

The authoritative register is split between `interview_allocation_runs` (version, snapshots, fingerprints and commit evidence) and `interview_allocation_results` (one centre result per district). `interview_assignments.interview_allocation_run_id` provides the causal link from each operational assignment back to its committed run.

## Offline field-pack provisioning

1. Register the protected browser device; its internal device identity is deliberately not shown to field operators.
2. Select the pack purpose, then search and add only named records returned by the role- and scope-filtered directory. For medical work, also choose the matching named facility/date schedule.
3. Issue the 24-hour encrypted pack. The internal pack key remains hidden; use the candidate/application reference, panel name, document name, or centre context shown in the workspace.
4. Capture hard-copy checks through the configured document checklist and verification fields through extracted-field choices. The source document is linked as evidence automatically.
5. Review the structured current-server summary, queue events, reconnect, and synchronise. The outbox and conflict view use human record labels; technical event/entity identifiers and serialized payloads are not operator controls.
6. Resolve conflicts with a documented reason, complete the final sync, and allow the reconciled local pack to be purged. Never copy field-pack records into an unapproved external tool.

## Backup and restore

- PostgreSQL: encrypted daily full plus WAL/continuous recovery where supported.
- Object storage: versioning and immutable/off-site replication for originals and official artefacts.
- Configuration/secrets: separately backed up through the hosting platform; do not include secrets in repository archives.
- Redis is non-authoritative but durable queues must be drained or recovered before cutover.

Quarterly restore drill: restore database and object data into an isolated network, verify row counts/checksums, run audit-chain verification, open a protected document, replay an idempotent offline event and record achieved RPO/RTO.

## Incident priorities

1. Contain access and preserve evidence/audit logs.
2. Keep applicant data confidential; disable affected tokens/devices/providers.
3. Restore authoritative services using tested runbooks.
4. Reconcile queues/offline events and communicate through approved channels.
5. Complete root-cause, legal/privacy notification assessment and corrective actions.
