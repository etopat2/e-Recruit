# Codex Prompt 13 — Ranking, Quotas, Capping, Skill Reservations, Overrides and Reserve Lists

Implement the most sensitive selection logic as deterministic, reproducible domain services with strong tests.

## Ranking

Support authorised ranked views by configured dimensions such as:

- district;
- region;
- centre;
- gender;
- post;
- verified skill category;
- national view.

Use approved aggregate score and configured tie-break rules. Persist a ranking run or immutable snapshot sufficient to reproduce results.

## Quota configuration

Support:

- national vacancy total;
- region allocations;
- district allocations where policy uses them;
- male/female or other legally approved policy dimensions;
- verified special-skill reserved slots/sub-quotas;
- minimum score for skill reservation;
- fallback/unfilled-quota rule;
- reserve/waiting-list size and replacement policy.

Validate that child allocations do not exceed parent totals unless an explicitly supported policy says otherwise.

## Special-skill state

Maintain explicit progression such as:

`CLAIMED → DOCUMENT_UPLOADED → OCR_IDENTITY_CHECKED → VERIFIED`.

Only VERIFIED skill evidence may influence selection.

Do not add hidden bonus marks. If a campaign explicitly configures score bonus policy, make it visible and versioned; otherwise use reservations/sub-quotas or controlled manual substitution.

## Selection/capping run

Implement a versioned run that stores:

- input candidate dataset/snapshot id;
- assessment formula/version;
- qualifying threshold;
- quota configuration/version;
- skill reservation rules;
- tie-break policy/version;
- unresolved offline-data check;
- operator;
- timestamp;
- selected/reserve/not-selected outcomes;
- deterministic fingerprint/hash over canonicalised run inputs/output where practical.

Re-running creates a new run; never overwrite previous official scenarios.

## Scenario mode and certification

Allow authorised HQ users to simulate multiple selection scenarios without publishing. Certification/finalisation requires explicit action and must warn/block when required assessments remain unsynchronised or unresolved conflicts exist.

## Manual override

Implement promotion/demotion/replacement workflow requiring:

- candidate affected;
- replaced candidate where applicable;
- reason code;
- free-text justification;
- operator;
- timestamp;
- approval based on role policy.

Display a visible manual-adjustment indicator. Preserve pre-override and post-override lists.

## Reserve list

Store ordered reserve candidates per applicable bucket and provide a recommendation service for vacancies due to medical failure, withdrawal or no-show. Recommendation requires approval before promotion.

## Tests

Create extensive deterministic tests for:

- simple merit ranking;
- exact-score ties with configured tie-break order;
- district/gender quota selection;
- verified skill reservation;
- unverified skill excluded;
- unfilled quota fallback;
- reserve ordering;
- rerun version preservation;
- identical inputs produce identical fingerprint/output;
- manual override does not erase original result;
- outstanding offline data blocks certification;
- cross-scope admin cannot run selection.

Update FR-SEL and AC-07 traceability.
