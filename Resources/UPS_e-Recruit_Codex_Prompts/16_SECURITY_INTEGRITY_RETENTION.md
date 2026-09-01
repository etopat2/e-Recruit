# Codex Prompt 16 — Security Hardening, Integrity Monitoring, Privacy, Retention and Audit Governance

Perform a dedicated security/governance implementation pass.

## Web/application security

Review and implement protections for:

- HTTPS-only production;
- secure cookies and session expiry;
- CSRF;
- XSS output encoding/content security policy where practical;
- SQL injection prevention through ORM/parameterised queries;
- IDOR/BOLA server-side policies;
- rate limiting;
- login/OTP abuse;
- password/TOTP secret protection;
- secure file download authorisation;
- SSRF protections around internal document worker/object URLs;
- deserialisation/JSON schema validation;
- upload bombs and oversized PDFs/images;
- queue/job idempotency;
- export abuse and large query limits.

## Integrity monitoring

Implement review flags/alerts for:

- duplicate NIN/repeated identifiers;
- identical document hashes across applicants;
- repeated academic index/certificate or professional certificate numbers;
- material name/DOB/NIN conflicts;
- post-closure score changes;
- unusually frequent score corrections;
- selected below cut-off via override;
- selection outside configured quota/jurisdiction;
- progression without required stage;
- unsynchronised centre data at ranking time;
- unusual export/access outside normal scope.

These are investigation indicators, not automated fraud accusations.

## Privacy

- Minimise data in offline packs and public lists.
- Mask NIN in routine operational/public views unless full access is specifically required.
- Separate/restrict medical data.
- Add applicant privacy notice configuration and acceptance record.
- Log sensitive exports.

## Retention and archive

Implement configurable retention policy metadata by campaign/record category, legal hold, archive status and controlled purge workflow. Purge must require privilege/approval and create irreversible-operation audit evidence. Do not add a casual “delete applicant” button for active recruitment data.

## Audit integrity

Review audit coverage for every sensitive write and ensure ordinary app users cannot edit/delete audit rows. Add correlation/request IDs and exportable audit report.

## Automated security checks

Add CI jobs/scripts where feasible:

- Composer audit;
- npm/pnpm audit with documented policy for non-exploitable dev findings;
- Python dependency scan;
- secret scanning (e.g. Gitleaks);
- container/image scan (e.g. Trivy);
- OWASP ZAP baseline against a test deployment;
- static/lint checks.

Do not blindly fail production for untriaged noisy low-risk findings; document severity policy.

## Tests

Add security regression tests for access control, masking, uploads, rate limits, audit protection and retention permissions.
