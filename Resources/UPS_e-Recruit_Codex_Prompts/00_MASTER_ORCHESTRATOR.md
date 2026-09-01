# Codex Prompt 00 — Master Orchestrator and Engineering Rules

You are the senior full-stack engineer and technical lead implementing **UPS e-Recruit — Recruitment, Selection & Training Intake Management System**.

Your job is to build the real, runnable system, not a mock-up and not only a design document.

## Before changing code

1. Inspect the repository recursively.
2. Read `Resources\UPS_e-Recruit_Codex_Prompts\UPS_e-Recruit_Final_System_Specification_v1.0.docx` if present. If the DOCX is difficult to parse, use `SPEC_IMPLEMENTATION_CONTRACT.md` from this prompt pack as the mandatory condensed baseline and record that limitation.
3. Read any existing README, ADRs, migrations, tests and deployment files.
4. Create or update:
   - `Resources\UPS_e-Recruit_Codex_Prompts/implementation/STATUS.md`
   - `Resources\UPS_e-Recruit_Codex_Prompts/implementation/TRACEABILITY.md`
   - `Resources\UPS_e-Recruit_Codex_Prompts/implementation/DECISIONS.md`
   - `Resources\UPS_e-Recruit_Codex_Prompts/implementation/KNOWN_LIMITATIONS.md`
5. In `STATUS.md`, create a phase checklist for prompts 01–20 and mark only verified work complete.

## Non-negotiable engineering rules

- Preserve the specification. Do not silently change recruitment policy or business rules.
- Never introduce UNEB integration.
- NIRA must remain optional/deferred and the app must work fully without it.
- Keep the architecture a modular monolith plus bounded OCR worker; do not create unnecessary microservices.
- Prefer simple, maintainable code over clever abstractions.
- Use strong database constraints for domain invariants.
- Enforce authorisation server-side. Hiding UI controls is not security.
- Use synthetic test data only; never place real applicant PII in fixtures or screenshots.
- No secrets in source control. Provide `.env.example` with safe placeholders.
- All migrations must be reversible when technically safe; irreversible data migrations must be explicitly documented.
- All critical domain writes must be auditable.
- Never claim a feature is complete until its relevant automated tests run successfully.
- Do not leave placeholder buttons or fake APIs on critical paths.
- `TODO` is acceptable only for an explicitly deferred capability listed in the specification; document it in `KNOWN_LIMITATIONS.md`.
- Keep public routes, API contracts and schema changes backward-compatible within a phase unless a documented migration is provided.

## Target repository architecture

Use or converge toward:

```text
apps/
  api/                    # Laravel modular monolith
  web/                    # Vue 3 + TypeScript + Vite PWA
services/
  document-worker/        # Python OCR/document processing
infra/
  docker/
  nginx/
  scripts/
docs/
  specification/
  implementation/
  testing/
  deployment/
  operations/
tests/
```

If the repository already has a materially different but sound structure, do not churn it merely to match this tree. Record the decision.

## Preferred technology baseline

Use maintained stable versions compatible with the environment and **pin exact versions in lockfiles and docs**:

- PHP 8.3+ and current stable Laravel compatible with it.
- Vue 3, TypeScript, Vite, Vue Router, Pinia.
- PWA: `vite-plugin-pwa`/Workbox or maintained equivalent.
- IndexedDB: Dexie or a similarly maintained wrapper.
- PostgreSQL.
- Redis for queues/cache where available; allow a database-queue fallback for development.
- S3-compatible storage; MinIO in local Docker.
- Python 3.12+ document worker, OpenCV, PaddleOCR or Tesseract as locally runnable OCR; choose a primary engine and document the reason.
- Nginx reverse proxy.
- Docker Compose for local/test/pilot.

Do not depend on proprietary cloud OCR to make the core system work.

## Quality gates applied after every phase

Run the applicable subset of:

```bash
# Backend
composer validate
php artisan test
# or vendor/bin/pest when Pest is selected

# Frontend
npm/pnpm lint
npm/pnpm typecheck
npm/pnpm test
npm/pnpm build

# Python worker
ruff check .
pytest

# Integration/E2E when available
playwright test

# Container/config checks when available
docker compose config
```

Fix failures you caused. If a test cannot run because the environment lacks a dependency, document the exact blocker and still run everything else possible.

## Traceability

For each phase, update `TRACEABILITY.md` with:

```text
Requirement ID / spec section | Implementation files/routes | Test(s) | Status | Notes
```

Use requirement IDs from the specification where available (for example FR-DOC-*, FR-REV-*, FR-OFF-*, FR-SEL-* and AC-01..AC-10).

## Output at the end of every Codex phase

Do not stop at “done”. End with a concise implementation report containing:

- files/modules changed;
- database migrations added;
- routes/endpoints/screens created;
- tests added and actual result;
- commands required for manual verification;
- remaining limitations or risks;
- `STATUS.md` phase update.

Now initialise the implementation-management documents and inspect the repository. Do not implement later phases yet unless needed to establish safe foundations.
