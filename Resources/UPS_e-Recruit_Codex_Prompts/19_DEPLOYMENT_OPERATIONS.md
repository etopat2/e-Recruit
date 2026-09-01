# Codex Prompt 19 — Deployment, Production Configuration, Monitoring and Operations Runbook

Create production-ready deployment assets and exact operational documentation while keeping deployment simple.

## Deployment profiles

Support and document at least:

### Local/development
Docker Compose with API, web, document worker, PostgreSQL, Redis, MinIO, email catcher.

### Pilot/single-site production-like
Docker Compose on an approved Ubuntu/Linux VM/server using persistent volumes, Nginx TLS termination, real SMTP, approved object storage and backup target.

### National/scale-out
Same application architecture with one or more stateless API/web instances behind approved load balancer/reverse proxy and a protected PostgreSQL/object-store service. Do not require Kubernetes.

## Production Docker/build

- multi-stage production images;
- non-root processes where feasible;
- no dev dependencies in final images;
- immutable image tags plus release version/commit id;
- health checks;
- Laravel optimisation/cache commands appropriate to environment;
- frontend static assets built once;
- document worker model files/version handled reproducibly.

## Configuration

Create clear `.env.production.example` with descriptions but no secrets. Document:

- app URL/timezone;
- DB;
- Redis;
- S3/MinIO;
- SMTP;
- SMS adapter optional config;
- internal document-worker token/URL;
- session/cookie settings;
- encryption/app keys;
- trusted proxies;
- queue workers;
- file limits;
- logging;
- offline-pack expiry defaults;
- backup destination;
- brand asset paths.

## TLS and network

Provide Nginx config for HTTPS, secure headers, upload sizes, timeouts and `/api` routing. Support either government-issued certificate or ACME/Let's Encrypt where policy permits; do not hard-code one certificate strategy.

Keep PostgreSQL, Redis, MinIO admin endpoint and document worker off the public internet.

## Database deployment

Document safe migration procedure:

1. backup;
2. health check;
3. deploy compatible code;
4. run migrations;
5. warm/cache;
6. smoke test;
7. rollback decision.

For migrations that cannot be rolled back safely, require expand/migrate/contract strategy and document it.

## Queue workers and scheduler

Configure supervised/restarting Laravel queue workers and scheduler. Provide Docker Compose/systemd examples depending hosting model.

## Observability

Implement structured application logs with request/correlation ids and PII-conscious redaction. Add operational metrics or health endpoints for:

- request/error rate;
- queue depth/failed jobs;
- OCR pending/failures;
- storage health;
- DB health;
- offline unsynchronised packs/conflicts;
- notification failures.

Do not log full NIN, passwords, OTPs or document OCR payloads by default.

## Deployment scripts

Provide safe scripts or documented commands for:

- first install;
- upgrade;
- rollback;
- database backup;
- object-store backup;
- cache clear;
- queue restart;
- health verification.

## Runbooks

Create:

- `docs/deployment/DEPLOYMENT_GUIDE.md`
- `docs/deployment/ROLLBACK_GUIDE.md`
- `docs/operations/OPERATIONS_RUNBOOK.md`
- `docs/operations/BACKUP_RESTORE.md`
- `docs/operations/INCIDENT_RESPONSE.md`
- `docs/operations/OFFLINE_CENTRE_RUNBOOK.md`
- `docs/operations/OCR_FAILURE_RUNBOOK.md`

All commands must match the actual repository and Compose/service names.

Validate deployment from a clean environment where possible and record exact steps/results.
