# Deployment guide

## Profiles

- Development: root `docker-compose.yml`; published database/Redis/MinIO/Mailpit ports are for the isolated developer host only.
- Pilot/single site: `docker-compose.production.yml` on an approved Linux VM, with persistent volumes and only edge port 8080 exposed to an approved TLS ingress/load balancer.
- National: use the same immutable API/web/worker images with multiple stateless API/web instances behind the approved load balancer. PostgreSQL and object storage must be protected managed/HA services or an approved redundant deployment. Kubernetes is not required.

## First staging install

```sh
git clone <approved-repository-url> ups-erecruit
cd ups-erecruit
cp .env.production.example .env.production
chmod 600 .env.production
# Replace every placeholder using the approved secret store and set RELEASE_VERSION to the signed commit/tag.
docker compose --env-file .env.production -f docker-compose.production.yml config --quiet
docker compose --env-file .env.production -f docker-compose.production.yml build --pull
docker compose --env-file .env.production -f docker-compose.production.yml up -d postgres redis minio minio-init document-worker
docker compose --env-file .env.production -f docker-compose.production.yml run --rm api php artisan migrate --force
docker compose --env-file .env.production -f docker-compose.production.yml run --rm api php artisan db:seed --force
docker compose --env-file .env.production -f docker-compose.production.yml up -d
BASE_URL=https://staging.example.invalid infra/scripts/health-check.sh
```

Do not run `db:seed` against production unless the reviewed production seeder is part of the change. Demo users remain disabled unless `SEED_DEMO_USERS=true`; that option is forbidden in production.

## Release upgrade

1. Approve release digest, migration review, backup identifier, owner and rollback window.
2. Run `infra/scripts/backup.sh` and copy its encrypted result off-host.
3. Pull/build the new immutable `RELEASE_VERSION`; never reuse an existing tag.
4. Put Laravel in maintenance mode, stop queue/scheduler, deploy compatible images, run `php artisan migrate --force`, cache config/routes/views, restart services and run health/smoke tests.
5. Re-enable traffic and monitor 5xx, latency, queue age, OCR, storage, notification and offline-conflict metrics.

Exact commands:

```sh
export COMPOSE_FILE=docker-compose.production.yml
infra/scripts/backup.sh
docker compose --env-file .env.production -f "$COMPOSE_FILE" pull
docker compose --env-file .env.production -f "$COMPOSE_FILE" up -d --no-deps api web document-worker
docker compose --env-file .env.production -f "$COMPOSE_FILE" exec -T api php artisan migrate --force
docker compose --env-file .env.production -f "$COMPOSE_FILE" exec -T api php artisan optimize
docker compose --env-file .env.production -f "$COMPOSE_FILE" up -d queue scheduler nginx
BASE_URL="$APP_URL" infra/scripts/health-check.sh
```

Use expand/migrate/contract for destructive or incompatible schema evolution: release additive schema, backfill and verify, switch reads/writes, then remove old schema only in a later approved release.

## TLS and network

`infra/nginx/prod.conf` is the internal edge. Terminate TLS 1.2+ using government-issued certificates or policy-approved ACME at the upstream ingress, set/replace `X-Forwarded-Proto`, add HSTS there, and allow only that ingress to reach port 8080. PostgreSQL, Redis, MinIO, the MinIO console and the document worker have no production host ports.

## Configuration ownership

The secret store supplies APP key, DB/Redis/object credentials, worker token and SMTP credentials. Operations owns URLs, trusted proxies, log sink, backup target and release tag. Recruitment owns campaign/reference/geography/centre/policy data. Security owns TLS, secret rotation, managed devices, malware scanner and audit-log retention. `Resources/logo.png` is copied into the immutable build; favicon/PWA derivatives live under `apps/web/public/icons`.
