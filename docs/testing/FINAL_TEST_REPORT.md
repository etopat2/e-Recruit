# Final test report

Date: 2026-09-08 (Africa/Kampala)
Environment: Windows 11, Docker Desktop Linux containers, local synthetic data only
Scope: final engineering acceptance for the UPS e-Recruit v1.0 release candidate

## Result

The implemented release candidate passes the automated functional, browser, contract, dependency, container, restore and developer-workstation load gates listed below. It is suitable for deployment to a controlled staging/pilot environment. Production release remains **NO-GO** until the external approvals and target-environment exercises in `docs/deployment/GO_LIVE_CHECKLIST.md` are signed.

## Automated gates

| Gate | Command/profile | Result |
|---|---|---|
| Laravel feature/unit suite | `docker compose exec -T api php artisan test --compact` | PASS: 54 tests, 332 assertions, 66.29s |
| PHP formatting | `docker compose exec -T api vendor/bin/pint --test --format agent` | PASS: current API tree |
| Composer manifest | `docker compose exec -T api composer validate --strict` | PASS |
| OpenAPI contract | `node --test tests/contract/openapi.test.mjs` | PASS: 2 tests |
| Web lint | `npm run lint` in `apps/web` | PASS |
| Web type check | `npm run typecheck` in `apps/web` | PASS |
| Web unit/component | `npm run test:unit -- --run` in `apps/web` | PASS: 2 files, 2 tests |
| Web production build/PWA | `npm run build` in `apps/web` | PASS: 68 modules, 28 precache entries, 342.12 KiB |
| Playwright journeys | `npm run test:e2e` in `apps/web` | PASS: 30/30 across desktop and mobile projects, 2.1m |
| Worker lint | `docker compose exec -T document-worker ruff check .` | PASS |
| Worker tests | `docker compose exec -T document-worker pytest -q` | PASS: 5 tests; 6 dependency deprecation warnings |
| Worker dependency consistency | `docker compose exec -T document-worker pip check` | PASS |
| Python dependency audit | `python -m pip_audit -r requirements.runtime.txt` in an ephemeral final worker container | PASS: no known vulnerabilities |
| JavaScript dependency audit | `npm audit --audit-level=high` | PASS: 0 vulnerabilities |
| PHP dependency audit | `docker compose exec -T api composer audit --locked --no-interaction` | PASS: no advisories |
| Git history secret scan | `gitleaks v8.30.1 git /repo --redact` | PASS: 18 commits, about 2.40 MB, no leaks |
| API production image | production multi-stage build plus runtime smoke | PASS: Laravel 13.29.0; UID/GID 33; required extensions loaded |
| Web production image | production multi-stage build plus runtime smoke | PASS: nginx 1.28.0; UID/GID 101; HTTP 200 |
| Worker production image | production build plus two-process runtime/OCR smoke | PASS: UID 10001; health HTTP 200; Tesseract 5.3.0 |
| Container vulnerability scan | Trivy 0.74.0, fixed HIGH/CRITICAL, OS and language packages | PASS: API 0, web 0, worker 0; worker includes 161 OS packages |
| Production-path k6 smoke | 10 public + 2 staff-profile VUs for 60s through nginx/PHP-FPM | PASS: 1,628 requests/checks, 0% failures, p95 452.61 ms, p99 below 1.14 s maximum |
| Clean schema/seed | isolated empty PostgreSQL database, `migrate --seed --force` | PASS: 98 public tables, 19 roles, 16 permissions, 1 synthetic campaign |
| Backup/restore | `backup.sh` then guarded isolated `restore-test.sh` | PASS locally; details in `docs/operations/RESTORE_DRILL.md` |
| Service health | `docker compose ps`, `/api/v1/health/live`, `/api/v1/health/ready` | PASS: API, worker, PostgreSQL, Redis, MinIO, nginx and web healthy; readiness database/cache/storage all true |
| Security headers | GET through nginx | PASS: CSP, nosniff, SAMEORIGIN, strict-origin referrer and permissions policy present |
| Passive DAST | OWASP ZAP 2.17.0 baseline against the production-path fixture | PASS gate: 0 failures, 1 warning category, 59 passive rules passed |

The Playwright suite covers public accessibility, authentication, enforced temporary-password replacement, technical staff provisioning/recovery, applicant registration/draft/upload/review/submission/acknowledgement, applicant status/inbox, verification source focusing and decisions, hard-copy receipt, scheduling/attendance, panel closure, scoring, selection certification, medical processing, strict reserve recommendation/approval, PATS intake, encrypted offline lock/reload/reconciliation, and a real two-browser-context protected-field conflict/resolution.

ZAP's only warning is the deliberate `style-src 'unsafe-inline'` CSP allowance used to position the normalized source-evidence rectangle over scanned pages. Scripts, objects, framing, base URLs and form targets remain constrained. Replacing that runtime style binding with nonce/hash-compatible classes is tracked as defence-in-depth work; the warning does not waive the independent authenticated test.

## Diagnostic findings corrected during this pass

- A k6 attempt against `host.docker.internal:8080` reached the host XAMPP Apache service, not the Compose ingress. The committed PHP-FPM/nginx test fixture was then used on the Compose network.
- A first production-image campaign request used a stale value from the ignored local `apps/api/.env`. The benchmark was rerun against the Compose development database credential; the final run passed. No local environment file was modified or committed.
- Trivy initially found two fixed `libssh2` HIGH advisories in stale runtime layers. The API runtime was separated from build tooling and all runtime OS packages were refreshed. The document worker was rebuilt from refreshed OS indexes.
- Gitleaks initially matched two deliberately fake Stripe examples under duplicated Laravel guidance. `.gitleaks.toml` narrowly allows only those two documentation paths; the full history rescan returned no leaks.
- The development Laravel built-in server serialized requests over a Windows bind mount and was not accepted as performance evidence. The final recorded result uses the production nginx and PHP-FPM images.

## Manual and external gates

The following cannot be truthfully completed on a developer workstation and are not waived:

- independent penetration testing, DPIA/privacy approval, and manual keyboard/screen-reader/zoom acceptance;
- production-like deadline-surge, 50k/150k data, OCR throughput and soak testing on approved hardware;
- approved TLS ingress, immutable registry digests, monitoring/on-call and a production-target restore meeting stakeholder RPO/RTO;
- EICAR/ClamAV staging validation, real SMTP/SMS contracts, and failure/retry observation;
- authoritative Uganda geography, campaign/ranking/quota rules, communication templates and accountable owner approvals;
- supervised centre/panel/medical/PATS/offline field pilot and formal GO signatures.

These gates are tracked explicitly in the acceptance matrix and go-live checklist. No production-readiness claim is made until they pass.
