# Codex Prompt 20 — Final System Audit, Acceptance, Pilot and Go-Live Gate

Perform a final implementation audit against the complete UPS e-Recruit specification. This phase is not a superficial cleanup. Find missing functionality, implement gaps, run tests and produce a go-live evidence pack.

## Step 1 — Requirements traceability audit

Read the final specification and `SPEC_IMPLEMENTATION_CONTRACT.md`. Review `TRACEABILITY.md` line by line. For every requirement and AC-01..AC-10, classify:

- Implemented and tested;
- Implemented, manual validation required;
- Partially implemented;
- Deferred by explicit specification;
- Missing.

Implement any missing **Must** requirement unless it is genuinely impossible in the current environment; document concrete blockers rather than vague statements.

## Step 2 — Clean-environment build

From a clean clone or clean dependency/cache state:

- copy example env files;
- start dependencies;
- install PHP/Node/Python dependencies;
- migrate from empty database;
- seed synthetic demo/test data;
- build frontend;
- start services;
- run health checks.

Fix undocumented setup steps.

## Step 3 — Full automated test pass

Run:

- backend tests;
- frontend unit/component tests;
- document-worker tests;
- Playwright E2E;
- offline tests;
- lint/typecheck/build;
- security dependency scans;
- migration tests;
- selected k6 smoke/load profile;
- OWASP ZAP baseline if the environment supports it.

Produce `docs/testing/FINAL_TEST_REPORT.md` with actual commands, counts and failures/waivers.

## Step 4 — Acceptance criteria AC-01..AC-10

Provide evidence for each:

- AC-01 campaign configurability;
- AC-02 applicant save/resume/submit/reference;
- AC-03 multi-source document intelligence without OCR auto-rejection;
- AC-04 same-viewport verification and source highlighting;
- AC-05 offline pack work and no duplicate sync;
- AC-06 visible protected-field conflict;
- AC-07 reproducible ranking/capping + overrides;
- AC-08 medical/training privacy;
- AC-09 sensitive-action auditability;
- AC-10 backup restore + security + pilot/offline drill readiness.

## Step 5 — Security/privacy review

Verify:

- no secrets committed;
- no real PII in test data;
- public endpoints do not leak NIN/documents;
- staff routes enforce scope server-side;
- MFA enabled for privileged roles;
- offline pack scope/expiry works;
- medical isolation works;
- logs are PII-conscious;
- uploads are protected;
- audit records cover high-risk actions.

## Step 6 — Pilot guide

Create `docs/deployment/PILOT_PLAN.md` for a controlled pilot at selected recruitment centre(s), without inventing which centre UPS must choose. Include:

- devices/network preparation;
- synthetic rehearsal;
- staff account/MFA setup;
- pack download/offline drill;
- QR check-in rehearsal;
- scoring and sync rehearsal;
- conflict exercise;
- backup before pilot;
- support contacts/roles placeholders;
- incident escalation;
- daily sync reconciliation;
- post-pilot review and sign-off.

## Step 7 — Go-live checklist

Create `docs/deployment/GO_LIVE_CHECKLIST.md` with explicit sign-off boxes/owners for infrastructure, security, backups, data/reference configuration, campaign rules, templates, centre mappings, panel users, notification gateway, document worker, load test, accessibility, offline drill, training, helpdesk, incident response and rollback.

## Step 8 — Final handover

Create/update:

- `README.md`;
- administrator guide;
- applicant/user guide where appropriate;
- verification officer guide;
- panel/centre offline guide;
- medical user guide;
- PATS/training guide;
- technical operations runbook;
- `FINAL_IMPLEMENTATION_REPORT.md`.

The final report must clearly separate:

1. complete implemented functionality;
2. explicitly deferred capabilities (e.g. optional future NIRA, USSD);
3. environment-dependent integrations requiring production credentials/contracts (SMTP/SMS, official brand assets, approved hosting certificates);
4. any known residual risk.

Do not state “production ready” unless the tested evidence supports it. End with the exact commands a deployment engineer should use to run the final release in a clean staging environment.
