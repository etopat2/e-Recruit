# Codex Prompt 08 — Same-Viewport Document Review and Discrepancy Workbench

Implement the specification-critical verification workbench.

## Desktop/officer layout

At viewport widths >=1024 px, render a split view in one viewport:

### Left pane — scanned evidence

- PDF/image document viewer;
- selected document page;
- page thumbnails;
- previous/next;
- zoom, pan, rotate, fit-width/fit-page;
- optional brightness/contrast for review derivative only;
- OCR bounding-box overlay;
- highlighted source region for selected field.

### Right pane — captured/extracted/review data

For each field show:

- field name;
- applicant-entered value;
- selected document extracted value;
- OCR confidence;
- values from all other evidence sources;
- comparison status;
- final verified value if one exists;
- source badges and document/page reference;
- reviewer actions.

Actions per field:

- Verify/accept;
- Flag discrepancy;
- Correct/establish verified value with mandatory reason;
- Mark OCR extraction incorrect;
- Request clearer/replacement upload;
- Mark unreadable/low confidence;
- Mark not present.

## Cross-document evidence matrix

Include a compact applicant-wide matrix for fields such as Name, NIN, DOB, grades, district/location and campaign-specific attributes.

Clicking any source value in the matrix must:

1. select its document;
2. select the relevant page;
3. scroll/focus the relevant OCR region when coordinates exist;
4. visually highlight it.

Do not open a new browser window to perform normal review.

## Source tabs and replacement history

Allow switching among ID, academic evidence, LC letter, application letter, skills/professional documents and other evidence without leaving the workbench. Show document version/replacement history and retain original files.

## Responsive behaviour

- >=1024px: side-by-side split view.
- narrower tablet: same-page top/bottom split or controlled collapsible panes.
- small phone: allow limited triage but warn/restrict full verification if the viewport cannot safely support it.
- keyboard-accessible controls and shortcuts for common safe actions.

## Offline design readiness

Make viewer/review state serialisable so authorised downloaded cases can operate from IndexedDB later. Do not require a server round trip for every UI interaction; final local review events will be queued in the offline phase.

## Audit

Every verify/correct/discrepancy/replacement request must create auditable domain events preserving original source values.

## Tests

Use component and Playwright tests to prove:

- same viewport split at desktop width;
- field click highlights the correct region;
- cross-source click switches document/page;
- discrepancy can be recorded;
- original data remains visible after verified-value correction;
- tablet layout remains same-page;
- keyboard navigation works for critical actions.

Capture synthetic test screenshots only if useful. Update FR-REV and AC-04 traceability.
