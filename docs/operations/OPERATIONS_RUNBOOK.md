# Operations runbook

## Daily checks

Run `infra/scripts/health-check.sh`, then inspect the privileged `/api/v1/operations/metrics` endpoint for pending/failed queue jobs, document processing states, active/outstanding offline packs, open conflicts and failed notifications. Monitor 5xx/429, p95/p99 latency, DB connections/storage, Redis memory, MinIO capacity, worker latency and audit-integrity alerts.

Never paste NINs, OCR text, document images, passwords, OTPs or tokens into monitoring/tickets. Use application reference, internal ULID and correlation ID according to role.

## Queue and scheduler

```sh
docker compose --env-file .env.production -f docker-compose.production.yml ps
docker compose --env-file .env.production -f docker-compose.production.yml logs --since=30m queue scheduler
docker compose --env-file .env.production -f docker-compose.production.yml exec -T api php artisan queue:failed
docker compose --env-file .env.production -f docker-compose.production.yml exec -T api php artisan queue:retry <failed-job-uuid>
docker compose --env-file .env.production -f docker-compose.production.yml exec -T api php artisan queue:restart
```

Investigate dependency/root cause before retry. Document and notification jobs are idempotent at the record state boundary; confirm status and object consistency after retry.

## Common recovery

- API not ready/database: remove traffic, verify PostgreSQL health/capacity/credentials, do not repeatedly restart/migrate.
- Redis unavailable: remove traffic until cache/session/queue is healthy; PostgreSQL remains authoritative. Reconcile jobs after restoration.
- MinIO unavailable: block uploads, keep submitted data read-only if safe, restore storage and verify DB object paths/checksums.
- Worker unavailable: leave documents pending/failed for review; restart worker, validate health/token, then retry selected jobs.
- SMTP unavailable: recruitment transactions remain committed; restore provider and retry notifications. Use approved alternate communication only.
- Offline backlog: contact named package owner, sync or revoke/expire in controlled fashion; do not certify selection with outstanding work/conflicts.

## Maintenance

Use maintenance mode for consistency-impacting work, stop workers gracefully, back up, record release/change references, perform the smallest approved action, validate health and workflows, then reopen traffic. Never run `migrate:fresh`, `db:wipe`, volume removal or synthetic-data generation in production.
