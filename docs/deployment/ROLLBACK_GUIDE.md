# Rollback guide

## Decision

The release commander may roll back application images when smoke checks, error/latency thresholds, security controls, data integrity or a critical journey fails. Freeze campaign/configuration edits and queue consumers, record correlation IDs and preserve logs before changing state.

## Compatible-code rollback

Set `RELEASE_VERSION` to the previously approved immutable tag and run:

```sh
docker compose --env-file .env.production -f docker-compose.production.yml stop queue scheduler
docker compose --env-file .env.production -f docker-compose.production.yml up -d --no-deps api web document-worker
docker compose --env-file .env.production -f docker-compose.production.yml exec -T api php artisan optimize:clear
docker compose --env-file .env.production -f docker-compose.production.yml exec -T api php artisan optimize
docker compose --env-file .env.production -f docker-compose.production.yml up -d queue scheduler nginx
BASE_URL="$APP_URL" infra/scripts/health-check.sh
```

Do not run blind `migrate:rollback`. If the new migration is backward-compatible, leave it until the later contract release. If data/schema restoration is required, keep the site unavailable and execute the migration-specific approved recovery or the tested backup restore. Reconcile jobs, notifications and offline acknowledgements before reopening traffic.

## Evidence

Record incident/change reference, old/new image digests, backup identifier, migration state, start/end time, smoke output, queue counts, open conflicts, data reconciliation and approvers. A rollback is not complete merely because containers are healthy.
