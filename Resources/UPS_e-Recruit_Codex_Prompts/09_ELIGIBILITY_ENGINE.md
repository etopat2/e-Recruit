# Codex Prompt 09 — Campaign Eligibility and Qualification Engine

Implement a deterministic, explainable eligibility engine that uses campaign rule versions and verified data.

## Rule execution model

Each rule must return:

- PASS;
- FLAG/REVIEW;
- FAIL;
- human-readable explanation;
- rule id/version;
- input value/evidence references;
- timestamp/run id.

OCR uncertainty alone must create FLAG/REVIEW, never FAIL.

## Rule categories

Implement configurable rules for at least:

- identity/NIN presence/format and required verification state;
- nationality/citizenship declaration requirements;
- age at campaign-configured cut-off date;
- academic level and subject/grade rules;
- number of credits/passes or qualification class;
- document completeness;
- hard-copy/original requirements at the relevant stage;
- LC source/location rule;
- professional registration/skill requirements;
- campaign declarations;
- manual-review route for equivalent/unusual qualifications.

Do not hard-code “18–30”, English, Maths or “no diploma/degree” globally. Those are campaign/post rules only.

## Data precedence

When a field requires verification, consume the latest valid UPS verified-value record. Preserve applicant-entered and OCR-extracted values as evidence, not as silently overwritten values.

## Re-evaluation

Eligibility must be re-runnable when:

- verified data changes;
- an appeal succeeds;
- a campaign rule version legitimately changes;
- required hard-copy/original status changes.

Historical runs must remain inspectable.

## Review queue

Build staff queue for FLAG/REVIEW cases, filterable by campaign/post/centre/district/reason. Provide an authorised resolve/re-run workflow.

## Tests

Implement parameterised tests for Warder/Wardress-like and CPO/CASP-like rules, cut-off age boundaries, academic rules, missing data, OCR low-confidence versus verified values, equivalent qualification review and re-evaluation history.
