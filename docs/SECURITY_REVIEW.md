# Security and privacy review

Reviewed against prompt 16/20 on 2026-09-01. This is an implementation review, not an independent penetration-test attestation.

| Control | Evidence | Result |
|---|---|---|
| Secret handling | Example placeholders only; runtime `.env` ignored; production Compose requires injected secrets | Pass, deployment verification required |
| Authentication | Hashed passwords, throttled registration/login, expiring Sanctum tokens, privileged TOTP/recovery flow | Pass |
| Authorisation | Policies plus role, ownership and geographic/task scope; IT admin excluded from decisions | Pass; matrix UAT required |
| Sensitive identity | Full NIN encrypted; keyed fingerprint for lookup; NIN hidden from model serialization and audit | Pass |
| Medical isolation | Restricted response fields and role policy; Council sees Fit gate, not clinical notes | Pass |
| Documents | Signature/type/size/malware gate, private random keys, authorised proxy, no-store/nosniff | Pass; staging ClamAV test required |
| Offline data | User/device/scope/action/expiry-bound package, idempotent UUID ledger, explicit conflicts | Pass; managed-device encryption/pilot drill required |
| Audit | Redacted hash-linked events, correlation IDs, approval references, integrity flags | Pass; external log retention required |
| Browser boundary | Same-origin architecture, CSP, frame policy, permissions policy, secure-cookie production setting | Pass; TLS/HSTS supplied by approved ingress |
| Logging | JSON stderr in production; no request-body logging; audit redaction deny-list | Pass; central sink configuration required |
| Dependencies | Composer/npm/pip locks and CI audits | Automated scan must be rerun at release |
| Privacy governance | Retention, legal hold, purge approval and purpose-bound export records | Implemented; UPS policy/DPIA values required |

## Required external assurance

An authorised assessor must complete penetration testing, authenticated DAST, infrastructure/container scanning and a Data Protection Impact Assessment. High/critical findings, public document/NIN exposure, MFA bypass, cross-scope access, or an unverified restore are release blockers. Test only synthetic data and use the private UPS vulnerability channel.
