# Optional Codex Prompt — Single Master Build Prompt

Use this only when you want Codex to manage the entire implementation itself across multiple iterations. The phased prompts are still safer and easier to review.

You are responsible for implementing the entire UPS e-Recruit system in this repository according to `UPS_e-Recruit_Final_System_Specification_v1.0.docx` and `SPEC_IMPLEMENTATION_CONTRACT.md`.

Read both documents first. Build a staged plan matching prompts 01–20 in this pack, record it in `docs/implementation/STATUS.md`, then execute the phases in order. Do not stop after planning. At the end of each phase, run applicable tests, fix failures, update traceability and continue only when the phase quality gate is met.

Critical requirements that must never be lost during implementation:

- generic campaign/post architecture supporting Warder/Wardress, CPO, CASP and future categories;
- Vue installable PWA + Laravel modular monolith + PostgreSQL + S3-compatible storage + queue + bounded Python OCR worker;
- no UNEB integration;
- multi-source form ↔ ID ↔ document ↔ document cross-validation;
- source/comparison/verified data layers;
- OCR is assistive, never the sole disqualifier;
- same-viewport scanned-document + captured-data verification workbench with field-to-source highlighting;
- hard-copy reference and reconciliation workflow;
- structured geography and configurable LC routing;
- secure offline scoped packs, IndexedDB, event queue, idempotent sync and explicit conflict resolution;
- generic assessment and panel closure;
- reproducible ranking, quotas, skill reservation, capping and audited manual override;
- medical privacy, reserve replacement and training reporting;
- notifications, tracking, appeals/helpdesk, reports/exports;
- RBAC + scope + MFA + audit + upload security + retention;
- executable automated tests, load/security tests and backup/restore drills;
- Docker-based deployment/runbooks with no Kubernetes requirement.

Do not introduce fake placeholder implementations on critical workflows. Use synthetic data only. Do not store secrets. Do not call a phase complete until its tests pass or a concrete environment blocker is documented.

When the repository is fully implemented, execute the full final acceptance prompt and produce a final handover report.
