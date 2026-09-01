# Codex Prompt 11 — Offline PWA Data Packs, Event Sync, Conflict Resolution and Device Controls

Implement the full offline capability. This is a core acceptance requirement.

## Registered devices

Create device-enrolment workflow for authorised staff:

- device id/public identifier;
- owner/user;
- label/platform info;
- enrolment timestamp;
- last seen/sync;
- status Active/Revoked;
- audit events.

Require authenticated/MFA-protected setup for sensitive staff devices.

## Scoped offline packs

Implement explicit download of role-scoped/time-limited packs:

- Panel pack;
- Verification pack;
- Centre coordinator pack;
- Medical pack;
- Hard-copy reception pack.

Pack manifest must include pack id, version, user/device/scope, issue time, expiry, server versions and permitted schemas/actions.

Ordinary panel users must never receive national data. Include only minimum necessary PII and document previews.

## Browser storage

Use service worker + IndexedDB (Dexie or equivalent). Implement a local repository abstraction so online and offline screens use the same domain shape.

Protect offline data pragmatically:

- minimise stored fields;
- derive/wrap an encryption key using Web Crypto and an offline unlock credential/device context;
- never store plaintext unlock PIN;
- inactivity lock;
- pack expiry;
- local purge after confirmed sync/expiry/logout policy;
- revocation enforced on next connection.

Document browser security limitations honestly; do not claim disconnected remote wipe.

## Offline event queue

Each mutation must create an event containing at least:

- globally unique UUID;
- pack id;
- application/entity id;
- event/action type;
- payload schema version;
- actor/user;
- panel/scope when applicable;
- device id;
- local timestamp;
- downloaded/base entity version;
- local sequence;
- sync state.

Supported offline actions include candidate search, QR check-in, attendance, hard-copy checklist, verification actions, panel scores/notes and panel-head review/closure where authorised.

## Server sync API

Implement batch push/pull protocol with:

- idempotency: unique event id processed once;
- transaction boundaries;
- per-event acknowledgement/error;
- optimistic version checks;
- server-authoritative permissions rechecked at sync time;
- schema-version validation;
- clock timestamps treated as evidence, not trusted ordering source;
- pull of server changes since pack/server cursor where appropriate.

## Conflict rules

- Different independent fields can merge.
- Same protected score, eligibility decision, candidate status, verified value or panel closure collision creates `sync_conflict`.
- Never silently last-write-wins protected fields.
- Build authorised conflict-resolution UI with local/server values, actors, timestamps, reason and resolution audit.

## User-visible sync state

Prominently show:

- Online / Offline;
- last successful sync;
- pending events;
- failed events;
- conflicts;
- pack expiry;
- `Sync Now` button.

Do not rely solely on background sync; explicit sync must work.

## HQ Offline Operations dashboard

Show centre/device, pack, last sync, pending count, conflicts, expiry/revocation and risk alerts.

## Ranking safeguard

Expose a server check that ranking/selection certification can use to detect outstanding required offline data. Do not certify silently with missing panel data.

## Automated tests

This phase requires substantial tests:

- Playwright goes offline after pack download and can search/check-in/score;
- reconnect syncs changes;
- same event retried twice is applied once;
- conflicting edit creates conflict;
- independent field events merge;
- expired/revoked pack cannot sync unauthorised new changes;
- cross-scope events are rejected server-side;
- local pending counter is accurate;
- large reasonable pack performance benchmark;
- purge workflow.

Update FR-OFF and AC-05/AC-06 traceability.
