# Codex Prompt 07 — OCR, Document Quality, Structured Extraction and Multi-Source Cross-Validation

Implement the document intelligence subsystem. It must be useful without UNEB or NIRA integrations.

## Python document worker

Implement a bounded worker/service that can:

- receive an internal authorised document-processing job;
- render PDFs to page images where needed;
- preprocess image: rotate/orientation, deskew, denoise, contrast, crop guidance;
- calculate quality indicators: blur, glare/overexposure, low resolution, clipping where feasible;
- OCR text and word/line bounding boxes;
- classify supported document type or verify expected type;
- extract structured fields per document profile;
- return raw OCR text, structured field values, confidence, page number and bounding polygon/box;
- record OCR engine and model/version.

Prefer locally runnable OCR such as PaddleOCR as primary with a documented fallback. Do not call public cloud OCR by default.

## Document profiles

Implement versioned extraction profiles for at least:

### National ID
Names, NIN, DOB, sex and other reliable printed fields.

### Academic/UCE-like document
Candidate name, index/certificate number, year, subject-grade rows and other identity fields when present.

### LC recommendation
Name, NIN if present, village/parish/subcounty/district/date and officials where legible. Treat handwriting conservatively.

### Application letter
Attempt only reliable fields; handwriting is review-assisted.

### Skill/professional certificate
Name, qualification/skill, institution/body, certificate/registration number, dates.

Make extraction rules configurable/versioned enough to evolve without rewriting historical evidence.

## Multi-source comparison engine

Implement comparison across **all sources**:

`entered data ↔ National ID ↔ academic docs ↔ LC letter ↔ application letter ↔ skill/professional docs ↔ other evidence`.

Persist source values independently. Produce comparison records and applicant-wide evidence matrix.

Rules:

- Name: normalise case/spacing/punctuation; token/order-aware; abbreviations may become PROBABLE MATCH; never silently equate material differences.
- NIN: normalise formatting and require exact match where present.
- DOB: compare exact normalised date.
- Academic grades: exact normalised grade.
- Location: structured comparison where extracted confidently.
- Missing/not-present is NOT AVAILABLE, not mismatch.
- Low confidence is UNREADABLE/LOW CONFIDENCE, not automatic failure.

Do not use majority vote as the final verified value.

## Verified-data model

Create explicit verified-value records with:

- field/schema key;
- verified value;
- source/evidence references;
- verification method;
- reviewer;
- timestamp;
- notes/reason;
- superseded relationship/version when corrected.

Eligibility later consumes verified values where required.

## Security and reliability

- OCR worker is internal-only; authenticate internal calls.
- Jobs are asynchronous and retryable with idempotency.
- Handle worker outage gracefully: application upload remains recorded with processing pending.
- Sanitise parser inputs and impose CPU/time/file limits against decompression/image bombs.

## Tests

Create synthetic images/PDF fixtures designed specifically for test use. Test:

- exact match;
- reordered names;
- abbreviated name probable match;
- NIN mismatch;
- DOB conflict across three documents;
- grade mismatch;
- unreadable OCR;
- missing field;
- duplicate job idempotency;
- bounding-box persistence;
- no automatic rejection caused by OCR failure.

Update FR-DOC and AC-03 traceability.
