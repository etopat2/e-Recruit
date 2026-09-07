# Security and privacy review

Reviewed against prompts 16 and 20 on 2026-09-08. This is an implementation review, not an independent penetration-test attestation.

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
| Dependencies | Composer, npm and pip audits; Gitleaks full-history scan; Trivy OS/language scan of all three final images | Pass at release-candidate commit; repeat in CI/deployment |
| Passive DAST | OWASP ZAP 2.17.0 baseline: 0 failures, 1 CSP warning category, 59 passive rules passed | Pass with accepted inline-style rationale; authenticated external DAST required |
| Privacy governance | Retention, legal hold, purge approval and purpose-bound export records | Implemented; UPS policy/DPIA values required |

## Required external assurance

An authorised assessor must complete penetration testing, authenticated DAST, infrastructure/container scanning and a Data Protection Impact Assessment. High/critical findings, public document/NIN exposure, MFA bypass, cross-scope access, or an unverified restore are release blockers. Test only synthetic data and use the private UPS vulnerability channel.

The remaining passive warning is `style-src 'unsafe-inline'`, retained for the dynamically positioned source-evidence overlay. The policy still restricts scripts and all other high-risk resource boundaries. This exception must be reviewed during the independent assessment and migrated to nonce/hash-compatible styling when practical.
