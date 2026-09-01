# OCR/document worker failure runbook

OCR is assistive. Low confidence, malformed evidence or worker failure must never become an automatic eligibility rejection.

1. Check API and worker readiness, queue/failed-job counts, token mismatch, file size/page limits, Tesseract availability and object access using synthetic files.
2. Keep the original private document and metadata. A failed job sets processing status to `failed`; a low-confidence/quality result becomes `review_required`.
3. Correct dependency/configuration, restart worker, then retry only identified `ProcessDocumentJob` entries. The job updates/creates one versioned extraction and replaces its fields transactionally.
4. Verification officers may review the original and make a sourced human decision even when OCR is unavailable. They must not fabricate OCR confidence or overwrite source provenance.
5. Escalate repeated decoder crash, malware signal, unexpected public access, checksum mismatch or unusually broad failure as security/operations incidents.

Record worker image/OCR engine version, job/document internal ID, error class (not OCR text), retry outcome and backlog clearance.
