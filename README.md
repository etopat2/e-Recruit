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

For the verified local account inventory, demo credentials, every supported environment setting, MFA enrolment, and the complete test sequence, follow [testing.md](testing.md).

```sh
cp .env.example .env
cp apps/api/.env.example apps/api/.env
# Copy licensed tahoma.ttf and tahomabd.ttf into infra/fonts/ (not committed)
docker compose build
docker compose up -d
docker compose exec api php artisan key:generate --force
docker compose up -d --force-recreate api queue scheduler
docker compose exec api php artisan migrate --seed --force
docker compose exec api php artisan erecruit:import-uganda-administrative-units
docker compose exec api php artisan erecruit:import-ups-recruitment-geography
docker compose exec api php artisan erecruit:sync-education-institutions
```

Open `http://localhost:8080`. Mailpit is at `http://localhost:8026` and MinIO development console at `http://localhost:9011`.

The Uganda administrative import loads the canonical region-to-village hierarchy and explicitly excludes electoral constituencies. The second idempotent import loads the versioned UPS prison regions, district/city jurisdictions, recruitment centres, district routing and medical facilities derived from the supplied planning workbook, while retaining source hashes and ambiguity notes. Applicants can search for a village to populate its full administrative path or use cascading district-down selectors. Administrators maintain this reference data at `/staff/geography`. The institution synchronization imports the official NCHE, MoES TVET and MoES EMIS directories into indexed local tables so qualification-form searches do not wait on external services; the scheduler refreshes them weekly.

Official interview, medical-examination and final-successful-candidate lists are generated asynchronously from current workflow records at `/staff/recruitment-documents`. The PDFs use `Resources/logo.png` and require licensed Tahoma regular/bold files mounted through `TAHOMA_FONT_DIR`; the font binaries are intentionally excluded from Git.

Demo staff accounts are disabled by default. For an isolated development database only, set `SEED_DEMO_USERS=true` in `apps/api/.env`, reseed, and immediately change the documented development-only password `ChangeMe!2026`. The seeded technical account is `system_administrator@example.test`; privileged accounts require MFA and every demo account is forced to replace its password before application access. Technical account management is available at `/staff/users` and all mutations are audited.

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

Do not use development defaults in production. Supply secrets through the hosting platform, enable ClamAV, use TLS, configure real SMTP/SMS providers, import approved Uganda administrative data and campaign rules, and complete the external sign-offs listed in the [go-live checklist](docs/deployment/GO_LIVE_CHECKLIST.md).

Start with [Architecture](docs/ARCHITECTURE.md), [Security](docs/SECURITY.md), [Operations](docs/OPERATIONS.md), the [final test report](docs/testing/FINAL_TEST_REPORT.md), and the [final implementation report](FINAL_IMPLEMENTATION_REPORT.md).
