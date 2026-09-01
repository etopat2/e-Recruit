# Codex Prompt 12 — Generic Assessment, Written Imports, Oral Panel Scoring and Panel Closure

Implement the generic assessment engine.

## Configurable assessment components

Campaign/post configuration must support components such as:

- Written Examination;
- Oral Interview;
- Aptitude Test;
- Technical Test;
- Practical Assessment;
- Presentation;
- Physical Assessment;
- other approved component types.

Each definition can configure maximum mark, pass mark, weight, mandatory flag, assessor model and aggregation method.

## Assessor models

Support:

- single authorised scorer;
- multiple independent panel members;
- panel consensus;
- panel-head adjudication when policy allows.

For independent assessors, each member owns their own score record and cannot overwrite another member's score.

Add optional divergence threshold that flags unusually wide oral/interview score variance.

## Scoring UI

- Show assigned/check-in candidates only.
- Validate marks locally and server-side.
- Autosave safely online and create offline events when disconnected.
- Display incomplete assessment components.
- Do not reveal another independent assessor's score before submission if campaign policy requires blind scoring; make this configurable.

## Written score import

Provide CSV/XLSX import with template and validation. Reject/report:

- unknown application/reference;
- duplicate candidate rows;
- wrong campaign/centre;
- score outside max/min;
- invalid numeric values;
- already locked records without correction workflow.

Keep source import file metadata/hash, importer and error report.

## Aggregation

Implement deterministic aggregate calculation from configured weights/method. Store inputs and calculation version rather than only final number.

## Panel head closure

Panel Head reviews completeness and closes a session/batch. After closure, ordinary edits are blocked. Reopen/correction requires authorised workflow, reason and audit trail.

## Tests

Test multiple assessor isolation, aggregation, divergence, attendance gating, import validation, offline score event compatibility, closure locking and post-lock correction audit.
