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
