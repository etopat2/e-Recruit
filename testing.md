# UPS e-Recruit configuration and testing guide

Last verified: 2026-09-08 (Africa/Kampala)

This guide covers the Docker-based development environment, the currently present local users, development credentials, technical account administration, all application-specific environment settings, MFA enrolment, automated checks, and production configuration validation.

> **Testing only:** use synthetic data. Never reuse the documented demo password, local infrastructure defaults, test NINs, or recovery codes in staging or production. Plaintext passwords are never stored by the application and cannot be recovered from the database.

## 1. Current local users

The local PostgreSQL database was migrated, seeded, and queried on 2026-09-08. It currently contains two applicant records and six development staff accounts:

| Email | Name | Type | Status | Assigned roles | Password availability |
|---|---|---|---|---|---|
| Personal applicant (redacted from Git; use the local query below) | Redacted | Applicant | Active | None | Unknown and not recoverable; it does not match the demo password |
| `restore-drill@example.test` | SYNTHETIC Restore Drill Applicant | Applicant | Active | None | Not available; the restore drill generated and discarded a random 64-character password |
| `system_administrator@example.test` | System Administrator | System Administrator | Active | `system_administrator` | `ChangeMe!2026` (development only; forced change at first sign-in) |
| `hq_recruitment_administrator@example.test` | HQ Recruitment Administrator | HQ Recruitment Administrator | Active | `hq_recruitment_administrator` | `ChangeMe!2026` (development only; forced change at first sign-in) |
| `verification_officer@example.test` | Verification Officer | Verification Officer | Active | `verification_officer` | `ChangeMe!2026` (development only; forced change at first sign-in) |
| `panel_head@example.test` | Panel Head | Panel Head | Active | `panel_head` | `ChangeMe!2026` (development only; forced change at first sign-in) |
| `medical_officer@example.test` | Medical Officer | Medical Officer | Active | `medical_officer` | `ChangeMe!2026` (development only; forced change at first sign-in) |
| `auditor@example.test` | Auditor | Auditor | Active | `auditor` | `ChangeMe!2026` (development only; forced change at first sign-in) |

The application stores one-way password hashes. An existing password can be verified during login, but neither administrators nor database operators can decrypt or display it. Do not claim that a hash is a usable password.

The personal applicant email is deliberately excluded from this version-controlled file to avoid publishing live personal data to GitHub. Authorised local operators can see the complete current list with the following read-only query.

To list the current non-secret account inventory again:

```powershell
docker compose exec -T postgres psql -U erecruit -d erecruit -c "SELECT email, name, user_type, status, is_privileged, must_change_password, mfa_confirmed_at FROM users ORDER BY email;"
```

### Development staff credentials

These accounts are defined by `DatabaseSeeder` and have been explicitly enabled in the current isolated local database. On a fresh clone they do not exist until demo-user seeding is explicitly enabled. All start with the development-only password `ChangeMe!2026`; all must replace it before application access.

| Email | Password | Role | First-login security |
|---|---|---|---|
| `system_administrator@example.test` | `ChangeMe!2026` | Technical System Administrator | Enrol MFA, save recovery codes, then replace password |
| `hq_recruitment_administrator@example.test` | `ChangeMe!2026` | HQ Recruitment Administrator | Enrol MFA, then replace password |
| `verification_officer@example.test` | `ChangeMe!2026` | Verification Officer | Replace password |
| `panel_head@example.test` | `ChangeMe!2026` | Panel Head | Enrol MFA, then replace password |
| `medical_officer@example.test` | `ChangeMe!2026` | Medical Officer | Enrol MFA, then replace password |
| `auditor@example.test` | `ChangeMe!2026` | Auditor | Enrol MFA, then replace password |

Enable them only in an isolated development database:

1. Set `SEED_DEMO_USERS=true` in `apps/api/.env`.
2. Clear any cached configuration and rerun the idempotent seeder:

   ```powershell
   docker compose exec -T api php artisan optimize:clear
   docker compose exec -T api php artisan db:seed --force
   ```

3. Confirm the accounts with the inventory query above.
4. Return `SEED_DEMO_USERS=false` after seeding so later deployments cannot accidentally infer that demo accounts are desired.

The one-command environment override used to seed the current database was transient; the normal configuration remains disabled. The production Compose definition forcibly sets `SEED_DEMO_USERS=false`. Never change that production guard.

### Technical super-administrator account

Use `system_administrator@example.test` only for local technical-team testing:

1. Open `/access`, enter the documented development credential, and select **Sign in**.
2. Select **Begin MFA enrolment**. Add the displayed `otpauth://` URI to an authenticator and store the one-time recovery codes securely.
3. Enter the current six-digit code and select **Activate MFA**.
4. Replace `ChangeMe!2026` with a unique password of at least 12 characters containing upper- and lower-case letters and a number.
5. Open **Users** in the navigation or go directly to `/staff/users`.

The technical administrator can list all applicant and staff identities; create staff accounts; update names, contact values, roles and active/disabled status; replace staff scopes; issue one-time password resets; reset MFA; and revoke all sessions. Each mutation is audited and sensitive actions require a reason. A technical administrator cannot disable or demote their own account, remove the last active technical administrator, reset their own MFA/password through the administrative recovery controls, convert applicants into staff, or make recruitment decisions.

### Resetting an unknown local password

Prefer the audited **Users** screen described above: select the identity, enter an authorised reason, and choose **Issue temporary password**. Copy the generated value immediately; it is returned once, all existing sessions are revoked, and the user must replace it on next sign-in.

For emergency recovery of the only technical administrator in an isolated local environment, reset only an account for which you are authorised. The following PowerShell flow keeps the new password out of command history. It must contain at least 12 characters, upper- and lower-case letters, and a number.

```powershell
$resetEmail = Read-Host 'Local account email'
$securePassword = Read-Host 'New local-only password' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $resetPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    docker compose exec -T `
      -e ERECRUIT_RESET_EMAIL="$resetEmail" `
      -e ERECRUIT_RESET_PASSWORD="$resetPassword" `
    api php artisan tinker --execute="DB::transaction(function () { App\Models\User::where('email', getenv('ERECRUIT_RESET_EMAIL'))->firstOrFail()->update(['password' => getenv('ERECRUIT_RESET_PASSWORD'), 'must_change_password' => false, 'password_changed_at' => now()]); App\Models\User::where('email', getenv('ERECRUIT_RESET_EMAIL'))->firstOrFail()->tokens()->delete(); DB::table('sessions')->where('user_id', App\Models\User::where('email', getenv('ERECRUIT_RESET_EMAIL'))->value('id'))->delete(); });"
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    Remove-Variable resetPassword -ErrorAction SilentlyContinue
}
```

Do not reset the restore-drill identity for routine use; create a normal synthetic applicant through the registration screen instead.

## 2. Prerequisites

- Git.
- Docker Desktop or Docker Engine with Compose v2.
- At least 8 GB RAM available to Docker for the complete stack and browser tests.
- Node.js 22 when running frontend/contract checks directly on the host.
- Chromium installed by Playwright with `npx playwright install chromium`.
- An authenticator application for privileged staff MFA testing.

Host XAMPP Apache may already occupy port `8080`. Either stop Apache while testing e-Recruit or set `APP_PORT` in the root `.env` to an unused port such as `8088`.

## 3. First-time development configuration

Run these commands from the repository root.

1. Synchronise the repository:

   ```powershell
   git switch main
   git pull --ff-only origin main
   ```

2. Create local environment files if they do not already exist. Do not overwrite an existing `.env` without reviewing it:

   ```powershell
   Copy-Item .env.example .env
   Copy-Item apps/api/.env.example apps/api/.env
   Copy-Item services/document-worker/.env.example services/document-worker/.env
   ```

3. Replace the root development placeholders in `.env`. At minimum, use distinct local values for:

   - `POSTGRES_PASSWORD`
   - `REDIS_PASSWORD`
   - `MINIO_ROOT_PASSWORD`
   - `DOCUMENT_WORKER_TOKEN` (at least 12 characters; 32 random bytes is preferred)

4. Ensure `apps/api/.env` contains `APP_TIMEZONE=Africa/Kampala`, an empty `APP_KEY` on first start, and `SEED_DEMO_USERS=false` unless the optional demo accounts are intentionally required.

5. Build and start the services:

   ```powershell
   docker compose build
   docker compose up -d
   docker compose ps
   ```

6. Generate the Laravel encryption key. This writes only to the ignored local `apps/api/.env`:

   ```powershell
   docker compose exec -T api php artisan key:generate --force
   docker compose up -d --force-recreate api queue scheduler
   ```

   Docker reads `env_file` values when it creates a container. Recreating these three services is required so the API server, queue worker, and scheduler all receive the newly generated key. Do not generate another key when `APP_KEY` is already populated; preserve that key so existing encrypted records remain readable.

7. Create the schema and reference seed data:

   ```powershell
   docker compose exec -T api php artisan migrate --seed --force
   ```

8. Check readiness:

   ```powershell
   Invoke-RestMethod http://localhost:8080/api/v1/health/live
   Invoke-RestMethod http://localhost:8080/api/v1/health/ready
   ```

   Replace `8080` with the configured `APP_PORT`. The ready response must show `database`, `cache`, and `storage` as healthy.

9. Open the services:

   | Service | Default address |
   |---|---|
   | Application through same-origin nginx | `http://localhost:8080` |
   | Vite development server | `http://localhost:5173` |
   | API direct port | `http://localhost:8000/api/v1` |
   | Document worker | `http://localhost:8001` |
   | Mailpit inbox | `http://localhost:8026` |
   | MinIO console | `http://localhost:9011` |

## 4. Development settings

### Root `.env`

The root file controls Docker Compose infrastructure and host ports.

| Setting | Required/default | Purpose |
|---|---|---|
| `COMPOSE_PROJECT_NAME` | `ups-erecruit` | Isolates container, network, and volume names |
| `POSTGRES_DB` | `erecruit` | PostgreSQL database name |
| `POSTGRES_USER` | `erecruit` | PostgreSQL application user |
| `POSTGRES_PASSWORD` | Required replacement | PostgreSQL password supplied to the API |
| `REDIS_PASSWORD` | Required replacement | Redis authentication password |
| `MINIO_ROOT_USER` | `erecruit-minio` | Local object-store access key |
| `MINIO_ROOT_PASSWORD` | Required replacement | Local object-store secret key |
| `MINIO_BUCKET` | `erecruit-private` | Private document bucket |
| `DOCUMENT_WORKER_TOKEN` | Required replacement | Shared API-to-worker authentication secret |
| `APP_PORT` | `8080` | Same-origin nginx host port |
| `WEB_PORT` | `5173` | Vite host port |
| `API_PORT` | `8000` | Direct API host port |
| `WORKER_PORT` | `8001` | Direct worker host port |
| `POSTGRES_PORT` | `54321` | PostgreSQL host port |
| `REDIS_PORT` | `63791` | Redis host port |
| `MINIO_API_PORT` | `9010` | MinIO API host port |
| `MINIO_CONSOLE_PORT` | `9011` | MinIO console host port |
| `MAILPIT_SMTP_PORT` | `1026` | Mailpit SMTP host port |
| `MAILPIT_UI_PORT` | `8026` | Mailpit web UI host port |

Compose passes the root PostgreSQL, Redis, MinIO, and worker secrets into the API container. These values override duplicate `DB_PASSWORD`, `REDIS_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, and `DOCUMENT_WORKER_TOKEN` entries in `apps/api/.env` while running through Compose.

Changing a password in `.env` does not rewrite credentials already stored inside an existing PostgreSQL/Redis/MinIO data volume. For a disposable development environment only, recreating volumes with `docker compose down -v` will permanently delete all local e-Recruit data. Back up anything needed before using that command.

### `apps/api/.env`

These are the e-Recruit-specific API settings. Standard Laravel driver alternatives exist, but are not required for the supported Compose architecture.

| Setting | Development value | Purpose |
|---|---|---|
| `APP_NAME` | `UPS e-Recruit` | Application and notification name |
| `APP_ENV` | `local` | Enables local behavior |
| `APP_KEY` | Generated, never committed | Encrypts NINs, MFA secrets, recovery-code data, and application values |
| `APP_DEBUG` | `true` locally only | Detailed local errors; must be false in production |
| `APP_URL` | Same-origin application URL | Absolute link and artifact URL generation |
| `APP_TIMEZONE` | `Africa/Kampala` | Application date/time interpretation |
| `SEED_DEMO_USERS` | `false` by default | Explicit switch for the six demo staff users, including the technical administrator |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | `en` | UI/server locale fallbacks |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | `stack`, `single`, `debug` | Local logging |
| `DB_CONNECTION` | `pgsql` | Supported relational database driver |
| `DB_HOST`, `DB_PORT` | `postgres`, `5432` | Internal Compose database address |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Match root settings outside Compose | Database credentials |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | `database`, `120` | Session persistence and idle minutes |
| `SESSION_SECURE_COOKIE` | `false` locally | Must be true behind production HTTPS |
| `QUEUE_CONNECTION` | `database` | Durable queued notification/document jobs |
| `CACHE_STORE` | `redis` | Cache and throttling backend |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | `redis`, `6379`, matching secret | Redis connection |
| `FILESYSTEM_DISK`, `DOCUMENT_DISK` | `s3` | Private S3-compatible document storage |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Match MinIO credentials | Object-store credentials |
| `AWS_DEFAULT_REGION` | `us-east-1` | S3 signing region |
| `AWS_BUCKET` | `erecruit-private` | Private object bucket |
| `AWS_ENDPOINT` | `http://minio:9000` | Internal MinIO endpoint |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` | Required by the supported MinIO layout |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` | `smtp`, `mailpit`, `1025` | Local captured email |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Synthetic sender | Notification sender identity |
| `APPLICATION_REFERENCE_PATTERN` | `UPS/{year}/{post}/{sequence}` | Final reference format |
| `APPLICATION_REFERENCE_DIGITS` | `6` | Reference sequence padding |
| `DOCUMENT_MAX_BYTES` | `15728640` | Maximum accepted document bytes |
| `DOCUMENT_WORKER_URL` | `http://document-worker:8001` | Internal OCR worker URL |
| `DOCUMENT_WORKER_TOKEN` | Match root token | Worker request authentication |
| `DOCUMENT_WORKER_TIMEOUT_SECONDS` | `60` | API wait limit for worker calls |
| `MALWARE_SCANNER` | `development` locally | Development signature checks; production requires `clamav` |
| `CLAMAV_HOST`, `CLAMAV_PORT` | `clamav`, `3310` | ClamAV service connection |
| `OFFLINE_PACK_EXPIRY_HOURS` | `24` | Default offline-pack validity |
| `OFFLINE_PACK_MAXIMUM_RECORDS` | `1500` | Server-side offline-pack size cap |
| `SMS_DRIVER`, `PUSH_DRIVER` | `null` | Disables unconfigured external channels |
| `SMS_BASE_URL`, `SMS_TOKEN`, `PUSH_BASE_URL`, `PUSH_TOKEN` | Empty locally | Optional approved-provider settings |
| `PUSH_VAPID_PUBLIC_KEY` | Empty locally | Optional web-push public key |
| `SMS_TIMEOUT_SECONDS`, `PUSH_TIMEOUT_SECONDS` | `15` | Provider HTTP timeouts |

Losing `APP_KEY` makes encrypted fields unreadable. Back it up in the approved secret manager. Use `APP_PREVIOUS_KEYS` during a controlled key rotation; never commit current or previous keys.

### `services/document-worker/.env`

| Setting | Default | Purpose |
|---|---|---|
| `DOCUMENT_WORKER_TOKEN` | Required, minimum 12 characters | Must match the API token |
| `OCR_ENGINE` | `tesseract` | OCR implementation identifier |
| `TESSERACT_CMD` | `tesseract` | Executable path/name |
| `OCR_TIMEOUT_SECONDS` | `45` | Per-document OCR timeout, allowed 5–120 |
| `MAX_DOCUMENT_BYTES` | `15728640` | Worker input limit; keep aligned with API limit |
| `MAX_PDF_PAGES` | `20` | PDF page limit, allowed 1–100 |
| `MIN_IMAGE_WIDTH` | `640` | Minimum quality width |
| `MIN_IMAGE_HEIGHT` | `480` | Minimum quality height |

### Web build setting

`VITE_API_BASE_URL` is optional. Leave it unset for the supported same-origin `/api/v1` boundary. Set it only for an explicitly approved split-origin development deployment with corresponding CORS and CSP review.

## 5. Privileged MFA setup

For each privileged development account:

1. Open `/access` and sign in with its email and `ChangeMe!2026`.
2. Select **Begin MFA enrolment** when prompted.
3. Open the displayed `otpauth://` provisioning URI in an authenticator application.
4. Save the one-time recovery codes outside the browser. They are shown only during enrolment.
5. Enter the current six-digit code and activate MFA.
6. When prompted, replace the development-only temporary password before entering the application.
7. On later logins, supply the new password and current authenticator code together.

Do not share authenticator secrets or recovery codes between testers. Reset MFA only through an authorised, audited support process.

## 6. Automated test sequence

### Configuration and health

```powershell
docker compose config --quiet
docker compose ps
Invoke-RestMethod http://localhost:8080/api/v1/health/live
Invoke-RestMethod http://localhost:8080/api/v1/health/ready
```

### Laravel/API

```powershell
docker compose exec -T api composer validate --strict
docker compose exec -T api vendor/bin/pint --test
docker compose exec -T api vendor/bin/phpunit --configuration=phpunit.xml
docker compose exec -T api composer audit --locked --no-interaction
```

Tests use isolated test configuration and synthetic factories. They must not point at the development or production database.

### OpenAPI contract

```powershell
node --test tests/contract/openapi.test.mjs
```

### Vue/PWA

```powershell
npm --prefix apps/web ci
npm --prefix apps/web run lint
npm --prefix apps/web run typecheck
npm --prefix apps/web run test:unit
npm --prefix apps/web run build
npx --prefix apps/web playwright install chromium
npm --prefix apps/web run test:e2e
npm --prefix apps/web audit --audit-level=high
```

The Playwright suite uses mocked synthetic API traffic and exercises both desktop and mobile projects. It does not modify the live development database.

### Document worker

```powershell
docker compose exec -T document-worker ruff check .
docker compose exec -T document-worker pytest -q
docker compose exec -T document-worker pip check
```

## 7. Manual journey checklist

Use separate browser profiles for independent actors and use only synthetic identities.

1. Technical administrator: complete MFA and password replacement, create a staff identity, change its role/status/scopes, reset its password/MFA, revoke its sessions, and inspect the corresponding audit entries.
2. Applicant: register, save/resume a draft, upload allowed documents, review, submit, download acknowledgement, view status/inbox, and create a helpdesk ticket.
3. HQ administrator: configure/clone/publish a campaign, import geography, create schedules, run selection scenarios, and inspect operational reports.
4. Verification officer: focus the protected original and OCR source highlight, compare evidence, and record a reasoned versioned decision.
5. Panel head: enrol MFA, record/aggregate scoring, reconcile offline work, close the panel, and confirm post-close immutability.
6. Medical officer: enrol MFA and verify restricted medical notes are invisible to non-medical roles.
7. Auditor: enrol MFA, verify the audit hash chain, inspect integrity flags, and confirm decision actions remain forbidden.
8. Offline field mode: issue a scoped pack, choose a local PIN, reload to verify it locks, sync idempotently, and resolve a protected-field conflict with an independent authorised account.
9. Confirm messages appear in Mailpit and private documents cannot be opened without a valid authenticated API token.

The six demo accounts do not represent every operational role. Tests for hard-copy receiving, attendance, Council approval, training-school processing and other roles use isolated factories. Create additional named staff accounts through the audited technical administration screen; do not assign several human testers to one shared credential.

## 8. Production configuration

Start from `.env.production.example`; store the real file outside Git with mode `0600` or equivalent access controls. Values marked `INJECT_...` or `REPLACE_...` must be supplied by the approved platform before deployment.

### Release and application

| Setting | Requirement |
|---|---|
| `COMPOSE_PROJECT_NAME` | Unique deployment name |
| `RELEASE_VERSION` | Immutable approved commit/tag; never `latest` |
| `API_IMAGE`, `WEB_IMAGE`, `WORKER_IMAGE` | Approved registry repositories |
| `APP_NAME` | Approved display name |
| `APP_URL` | Public HTTPS origin |
| `APP_KEY` | Secret generated with `php artisan key:generate --show` |
| `APP_PREVIOUS_KEYS` | Optional comma-separated keys during controlled rotation |
| `APP_TIMEZONE` | `Africa/Kampala` unless formally changed |
| `TRUSTED_PROXIES` | Exact approved ingress CIDR(s), comma-separated |
| `LOG_LEVEL` | Normally `info`; central stderr collection is required |
| `EDGE_PORT` | Internal/published ingress port selected by hosting |

### Data, cache, objects, and backup

| Setting | Requirement |
|---|---|
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | Dedicated database and injected secret |
| `REDIS_PASSWORD` | Independent injected Redis secret |
| `MINIO_ROOT_USER`, `MINIO_ROOT_PASSWORD`, `MINIO_BUCKET` | Private object-store credentials/bucket |
| `BACKUP_ROOT` | Approved encrypted off-host backup destination for operational scripts |

### Documents and OCR

| Setting | Requirement |
|---|---|
| `DOCUMENT_WORKER_TOKEN` | Independent random 32-byte secret shared only by API/worker |
| `DOCUMENT_WORKER_TIMEOUT_SECONDS` | API timeout, default `60` |
| `DOCUMENT_MAX_BYTES` | API and worker byte limit, default `15728640` |
| `OCR_ENGINE`, `TESSERACT_CMD` | Approved engine/executable, defaults to Tesseract |
| `OCR_TIMEOUT_SECONDS` | Worker processing timeout, default `45` |
| `MAX_PDF_PAGES` | Default `20` |
| `MIN_IMAGE_WIDTH`, `MIN_IMAGE_HEIGHT` | Default `640` × `480` |
| `MALWARE_SCANNER` | Must be `clamav` for production |
| `CLAMAV_HOST`, `CLAMAV_PORT` | Internal scanner address, defaults `clamav:3310` |

### Recruitment and offline behavior

| Setting | Requirement |
|---|---|
| `APPLICATION_REFERENCE_PATTERN` | Approved format; default `UPS/{year}/{post}/{sequence}` |
| `APPLICATION_REFERENCE_DIGITS` | Approved sequence padding, default `6` |
| `OFFLINE_PACK_EXPIRY_HOURS` | Approved field-pack lifetime, default `24` |
| `OFFLINE_PACK_MAXIMUM_RECORDS` | Approved device capacity/risk limit, default `1500` |
| `SESSION_LIFETIME` | Approved idle duration in minutes, default `120` |
| `SESSION_DOMAIN` | Usually empty for host-only cookies |
| `SESSION_SAME_SITE` | `lax` for the supported same-origin deployment |

### Email, SMS, and push

| Setting | Requirement |
|---|---|
| `MAIL_HOST`, `MAIL_PORT` | Approved SMTP endpoint, normally port `587` |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Injected SMTP credentials |
| `MAIL_SCHEME` | `smtp` for normal SMTP/STARTTLS negotiation; validate TLS with the provider |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Approved UPS sender identity |
| `SMS_DRIVER`, `SMS_BASE_URL`, `SMS_TOKEN`, `SMS_TIMEOUT_SECONDS` | Keep driver `null` until an approved provider is configured |
| `PUSH_DRIVER`, `PUSH_BASE_URL`, `PUSH_TOKEN`, `PUSH_VAPID_PUBLIC_KEY`, `PUSH_TIMEOUT_SECONDS` | Keep driver `null` until an approved provider is configured |

Validate the production file without starting services:

```powershell
docker compose --env-file .env.production -f docker-compose.production.yml config --quiet
```

Then follow `FINAL_IMPLEMENTATION_REPORT.md` and `docs/deployment/GO_LIVE_CHECKLIST.md`. A valid configuration does not constitute production approval: independent security/privacy/accessibility assessment, authoritative geography and recruitment rules, ClamAV/EICAR validation, production-scale load/restore evidence, messaging-provider tests, managed-device field trials, and accountable signatures remain mandatory.

## 9. Troubleshooting

- **The browser shows the XAMPP page:** Apache owns the selected host port. Stop Apache or change root `APP_PORT`, then recreate nginx with `docker compose up -d --force-recreate nginx`.
- **Database authentication fails after changing `.env`:** the existing PostgreSQL volume still has the old credential. Restore the old value, alter the database role deliberately, or recreate only disposable local data after backup.
- **`APP_KEY` error:** if `APP_KEY` is already populated in `apps/api/.env`, do not replace it; run `docker compose up -d --force-recreate api queue scheduler`. If it is empty on a new installation, run `docker compose exec -T api php artisan key:generate --force` once and then recreate those services. Confirm `/api/v1/health/ready` reports `checks.encryption.ok: true`.
- **Worker returns 401/403:** `DOCUMENT_WORKER_TOKEN` differs between the API and worker.
- **Uploads fail storage readiness:** confirm MinIO is healthy, `minio-init` succeeded, the bucket names match, and anonymous access is disabled.
- **Privileged user receives an MFA error:** complete first-login enrolment and provide a current TOTP or unused recovery code.
- **Configuration change appears ignored:** run `docker compose exec -T api php artisan optimize:clear`; recreate containers when changing Compose-provided environment values.
- **Queue-backed action remains pending:** inspect `docker compose logs queue` and verify database/Redis/provider connectivity.
