# Codex Prompt 02 — Core Domain Model, Database Constraints and Seed Data

Implement the foundational database/domain model from the final specification. Do not yet build every UI.

## Domains to model

Implement migrations/models/repositories or domain services for:

- recruitment templates;
- recruitment campaigns;
- recruitment posts;
- campaign stages;
- campaign rule/config versions;
- campaign document requirements;
- configurable assessment definitions;
- applicants;
- applications;
- application status history;
- applicant origin/residence addresses;
- education records and subject results;
- employment history;
- professional registrations;
- special-skill categories and applicant skills;
- document metadata and versions;
- administrative units hierarchy;
- prison regions;
- recruitment centres;
- district-centre mappings with effective/campaign scope;
- panels and panel membership;
- user scopes;
- audit log foundation.

Later-phase tables may be stubbed only when needed for foreign keys, but prefer real migrations when their shape is already clear.

## Database principles

- Use stable internal IDs (UUID/ULID or well-managed bigint) and separate human references.
- Add explicit foreign keys and useful unique constraints.
- One active application per NIN per applicable campaign/post, unless an authorised exception record exists. Enforce as strongly as PostgreSQL permits; if a partial unique index is needed, implement it.
- Administrative units use a hierarchical parent relationship plus level/type and effective dates/status where practical.
- Do not hard-code region/centre counts.
- Campaign settings must be versionable; a published/used version must remain reconstructable.
- Store timestamps in UTC; render to configured local timezone.
- Use enums carefully. Prefer database-backed lookup/status values when campaign configurability may expand them; use constrained enums only for truly fixed technical states.

## Human application reference service

Implement a concurrency-safe reference allocator that can produce configurable references such as `UPS/2027/RW/000381` without using centre code. It must not issue duplicate references under concurrent submissions.

Do not issue a final reference for drafts.

## Seed data

Create synthetic seeders for development/testing:

- sample administrative hierarchy;
- a few regions/centres/district mappings clearly labelled synthetic/demo;
- skill categories;
- a Warder/Wardress-like campaign template;
- a materially different CPO/CASP-like template to prove configurability;
- synthetic users/applicants only.

Do not claim seed geography is authoritative Uganda production data unless it comes from an approved dataset.

## Tests

Write database/domain tests for:

- application uniqueness rule;
- campaign/post configurability;
- campaign version preservation;
- reference generation under concurrent calls where feasible;
- hierarchy integrity;
- effective district-centre mapping uniqueness/conflict rules;
- status-history append behaviour.

Run tests and update implementation traceability.
