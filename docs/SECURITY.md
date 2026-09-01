# Security model

## Identity and access

- Passwords use Laravel's configured one-way hash; NIN lookup uses keyed HMAC and the full NIN is encrypted.
- Privileged users require TOTP or a one-use hashed recovery code. First login issues a 15-minute enrolment token; confirmation rotates it to a full 12-hour token.
- Policy checks combine role, user type, campaign/post/region/centre/panel scope and allowed task. Applicant ownership is always checked server-side.
- Executive viewer and auditor are read-only. System administrators are explicitly excluded from decision tasks. Restricted medical notes are encrypted and returned only to medical roles.

## Input and document controls

- Form requests validate shapes, limits, foreign keys and enumerations.
- Files are constrained by campaign rule, extension, detected MIME signature, size and malware scan before protected storage.
- Storage keys are random/versioned; originals are never public URLs and downloads are proxy-authorised with `no-store` and `nosniff`.
- Worker inputs are bounded by bytes/pages/timeout and an internal token. OCR output is untrusted evidence.

## Integrity and privacy

- Sensitive changes call `AuditService`, which redacts passwords, tokens, secrets, NIN, OCR raw text and medical notes before writing a hash-linked record.
- Submission, campaign, eligibility, ranking, selection and offline artefacts use canonical SHA-256 fingerprints.
- Exports require an allowed role, explicit purpose, masking policy, private storage, checksum and expiry.
- Retention, legal-hold and purge tables require policy/approval workflow; production retention periods remain an external UPS legal input.

## Production hardening checklist

- Replace every development secret; use a secret manager and quarterly rotation.
- TLS 1.2+ and HSTS at the edge; restrict database, Redis, object storage and worker to private networks.
- Set `APP_ENV=production`, `APP_DEBUG=false`, `MALWARE_SCANNER=clamav`, secure cookies and trusted proxies/hosts.
- Enable database/object-store encryption, immutable backup retention and centralised alerting.
- Run dependency, container and DAST scans; resolve high/critical findings before release.
- Commission an independent penetration test and Data Protection Impact Assessment.

Report vulnerabilities privately to the authorised UPS security contact; do not place applicant information in tickets or source control.
