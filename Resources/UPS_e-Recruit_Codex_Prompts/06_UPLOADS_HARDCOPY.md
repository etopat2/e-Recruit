# Codex Prompt 06 — Secure Uploads, Camera Capture, Resumable Transfer and Hard-Copy Reception

Implement the secure document upload subsystem and physical hard-copy reception workflow.

## Upload requirements

- configured PDF/JPG/JPEG/PNG and approved formats only;
- validate extension, MIME type and actual file signature;
- enforce campaign file-size/count rules;
- preserve original bytes in protected object storage;
- compute SHA-256 checksum;
- generate safe preview derivatives rather than exposing originals directly;
- use signed/authorised object access or application proxy with scope checks;
- integrate malware scanning abstraction with a real production path (ClamAV or approved equivalent);
- never execute uploaded content;
- strip/handle dangerous metadata where appropriate for derivatives while preserving evidentiary original;
- version replacement uploads instead of destroying originals.

## Camera capture and quality UX

Implement browser camera/file capture with:

- edge/crop assistance where feasible;
- rotation;
- image compression for transfer;
- visible quality warnings for blur, clipping, glare/overexposure, low resolution and orientation.

If a quality check is not reliable enough client-side, run server/document-worker analysis immediately after upload and present the result.

## Resumable/retry-capable uploads

Implement a simple robust approach such as chunked uploads or retryable multipart upload. Persist upload session state and safely resume without duplicate document records.

## Hard-copy reception

Create staff/PWA workflow:

- QR scan or reference search;
- receiving office/unit, officer, date/time;
- configurable checklist;
- per item: Match, Different Document, Missing, Unreadable, Original Required at Interview;
- notes and discrepancy evidence;
- receipt generation and applicant notification;
- preserve comparison between physical and online evidence;
- support offline pack operation in later phase without redesigning data model.

## Tests

Include malicious filename/MIME tests, duplicate hash handling, replacement version preservation, access-control tests, large/retry upload tests where feasible and hard-copy checklist tests.

Add synthetic file fixtures only; no real IDs/certificates.
