# UPS e-Recruit — Codex Implementation Prompt Pack

This pack is designed to drive a coding agent such as OpenAI Codex through a **full, staged implementation** of the Uganda Prisons Service (UPS) e-Recruit platform from the accompanying final specification.

## Included source of truth

- `UPS_e-Recruit_Final_System_Specification_v1.0.docx` — functional and technical baseline.
- `SPEC_IMPLEMENTATION_CONTRACT.md` — condensed non-negotiable implementation contract for the coding agent.

## Recommended execution order

Run the prompts in numeric order. Start with `00_MASTER_ORCHESTRATOR.md`, then execute `01` through `20`. Each prompt is intentionally written so the coding agent must **inspect existing work, implement real code, run tests, fix failures, and update documentation** rather than only generating plans.

Do not skip a phase merely because part of it already exists. The agent should inspect, preserve correct work, fill gaps, and demonstrate the phase quality gates.

## Working model

Use a monorepo with a simple, maintainable architecture:

- `apps/api` — Laravel modular monolith.
- `apps/web` — Vue 3 + TypeScript + Vite PWA.
- `services/document-worker` — small Python document/OCR worker.
- PostgreSQL — system of record.
- S3-compatible object storage (MinIO locally).
- Redis — queues/cache where appropriate.
- Nginx — reverse proxy in deployment.
- Docker Compose — local/dev/test/pilot deployment. Kubernetes is **not** required.

The coding agent may adjust low-level package choices if a safer or better-maintained equivalent is clearly justified, but it must not change the agreed domain behaviour without recording a specification deviation.

## Rules for using the prompts

1. Place this pack inside or beside the repository.
2. Put the specification in `docs/specification/UPS_e-Recruit_Final_System_Specification_v1.0.docx` in the repo; the first prompt instructs Codex to copy it there if needed.
3. Paste `00_MASTER_ORCHESTRATOR.md` into Codex first.
4. Continue sequentially with prompts `01`–`20`.
5. After each phase, require all applicable automated tests to pass and inspect `docs/implementation/STATUS.md`.
6. Do not accept “implemented” unless the agent provides working routes/screens/migrations/tests and can run them.
7. Do not use real applicant PII in test fixtures. Use synthetic data only.
8. Keep credentials, SMTP secrets, SMS keys, S3 keys and production certificates outside source control.

## Expected final outputs from the repository

The finished repository should include application source, migrations, seeders, automated tests, Docker assets, `.env.example`, CI, security and backup scripts, sample synthetic test data, deployment and rollback instructions, administrator and operator guidance, offline/PWA test instructions, OCR test fixtures, and a final traceability report mapping the specification requirements to implementation and tests.
