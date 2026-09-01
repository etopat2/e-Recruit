# Codex Prompt 01 — Repository, Local Environment and CI Foundation

Implement the repository foundation for UPS e-Recruit. First inspect the current repo and preserve any correct work.

## Deliverables

Create a runnable local development stack containing:

- Laravel API/modular monolith under `apps/api` (or documented equivalent).
- Vue 3 + TypeScript + Vite PWA under `apps/web`.
- Python document worker under `services/document-worker`.
- PostgreSQL, Redis and MinIO in Docker Compose.
- Nginx config for a deployment-like local route, while retaining simple direct dev ports.
- Mailpit/MailHog or equivalent local email catcher.
- Optional ClamAV service or a safe abstraction with a development stub if running ClamAV is too heavy locally; production must support real malware scanning.

Create:

- root `README.md` with exact setup commands;
- `.editorconfig`, `.gitattributes`, `.gitignore`;
- `.env.example` files for each component;
- root `Makefile` or `justfile` with common commands (`up`, `down`, `install`, `migrate`, `seed`, `test`, `lint`, `build`, `reset`, `backup-dev`);
- deterministic Docker Compose network/volumes;
- health checks for database, Redis, MinIO, API and document worker;
- CI workflow that installs dependencies, runs backend/frontend/Python tests, lints and builds. A GitHub Actions workflow is acceptable; document how to port it to a government/self-hosted CI runner.

## Engineering requirements

- Do not put credentials in Compose files; use env vars and safe dev defaults only where clearly development-specific.
- Pin runtime/dependency versions.
- API `/health/live` and `/health/ready` endpoints must distinguish process health from dependency readiness.
- Python worker must expose a health endpoint if implemented as an internal HTTP worker.
- Ensure the frontend can reach API using environment configuration, not hard-coded localhost URLs.
- Configure CORS/CSRF/session handling appropriately for the chosen same-origin or split-dev arrangement.
- Prefer same-origin `/api` behind Nginx in production to reduce complexity.
- Configure object-storage buckets through a bootstrap script or documented command.

## PWA baseline

Set manifest name/short name, installability, icons placeholders clearly marked for replacement by authorised UPS brand assets, service-worker generation and a safe initial cache strategy. Do not cache authenticated API responses indiscriminately.

## Tests and verification

Add smoke tests proving:

- API boots and can connect to PostgreSQL;
- frontend builds;
- document worker boots;
- MinIO config can be initialised;
- Docker Compose config is valid;
- health endpoints return expected status.

Run all phase-appropriate tests and update `STATUS.md`, `TRACEABILITY.md` and `DECISIONS.md`.
