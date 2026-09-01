# Uganda Prisons Service e-Recruit

UPS e-Recruit is a secure, campaign-configurable recruitment platform.

## Architecture

- `apps/api`: Laravel 13 modular application/API, PostgreSQL persistence, Redis cache/queue, private S3-compatible documents, Sanctum tokens, MFA, scoped RBAC, append-only hash-chained audit.
- `apps/web`: Vue 3 + TypeScript installable PWA for applicants and staff, including controlled offline field queues in IndexedDB.
- `services/document-worker`: bounded Python 3.12 FastAPI worker for document decoding, quality checks, deskewing, OCR, confidence, boxes and extracted fields. OCR is assistive and never an automatic rejection authority.
- `infra`: Nginx and operational scripts.
- `docs`: architecture, security, operations, acceptance and go-live evidence.

The supplied `Resources/logo.png` is the official in-app logo. Square favicon/PWA assets are derived from it under `apps/web/public/icons`.

## Development quick start

Requirements: Docker Desktop with Compose v2. The root ports are configurable in `.env.example`.

```sh
cp .env.example .env
cp apps/api/.env.example apps/api/.env
docker compose build
docker compose up -d
docker compose exec api php artisan key:generate --force
docker compose exec api php artisan migrate --seed --force
```

Open `http://localhost:8080`. Mailpit is at `http://localhost:8026` and MinIO development console at `http://localhost:9011`.

Demo staff accounts are disabled by default. For an isolated development database only, set `SEED_DEMO_USERS=true` in `apps/api/.env`, reseed, and immediately change the documented development-only password `ChangeMe!2026`. Privileged demo accounts still require MFA enrolment on first login.

## Quality gates

```sh
docker compose exec api php artisan test
docker compose exec api php vendor/bin/pint --test
docker compose exec web npm test
docker compose exec web npm run build
docker compose exec document-worker ruff check .
docker compose exec document-worker pytest -q
```

Run browser checks after installing Chromium once with `npx playwright install chromium`:

```sh
npm --prefix apps/web run test:e2e
```

## Non-negotiable controls

- One active application per applicant/campaign/post; final references are allocated only on successful submission and never encode interview centre.
- Uploaded originals are private and proxy-authorised; extension, MIME signature, size and malware checks happen before acceptance.
- Verified values are versioned human decisions with source evidence. No majority vote or OCR-only eligibility failure exists.
- IT administrators cannot make recruitment decisions; medical notes remain restricted; executive/auditor roles are read-only.
- Offline packs are user/device/scope/action/time bound; event UUIDs are idempotent and protected-field conflicts require explicit resolution.
- Selection stores input/policy/output fingerprints and cannot be certified with open sync conflicts or outstanding offline work.
- UNEB integration, payments, facial recognition, suitability AI, blockchain, USSD and native apps are outside v1 scope. NIRA remains an unimplemented, approval-dependent option.

## Operations and production

Do not use development defaults in production. Supply secrets through the hosting platform, enable ClamAV, use TLS, configure real SMTP/SMS providers, import approved Uganda administrative data and campaign rules, and complete the external sign-offs listed in [Go-live checklist](docs/implementation/GO_LIVE_CHECKLIST.md).

Start with [Architecture](docs/ARCHITECTURE.md), [Security](docs/SECURITY.md), [Operations](docs/OPERATIONS.md), and [Final acceptance](docs/implementation/FINAL_ACCEPTANCE_REPORT.md).
