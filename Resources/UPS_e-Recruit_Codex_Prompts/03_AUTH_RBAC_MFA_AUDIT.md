# Codex Prompt 03 — Authentication, RBAC, Scope Enforcement, MFA and Audit Foundation

Implement production-grade authentication and authorisation for applicants and staff.

## User classes and roles

Support at minimum:

- Applicant
- Assisted Application Officer
- Hard-Copy Receiving Officer
- Verification Officer / Data Clerk
- Panel Member
- Panel Head
- Centre Coordinator / Regional Recruitment Officer
- Written Examination Officer
- Medical Officer
- Training/PATS Officer
- HQ Recruitment Administrator / Prisons Council Secretariat
- Executive Viewer
- Auditor / Inspector
- System / IT Administrator

Use RBAC plus explicit scopes for national/region/centre/panel/campaign/post/stage/task. A role alone must not grant access to every record.

## Applicant auth

Implement secure registration/login/recovery using phone/NIN plus password/PIN or another secure credential. Support verification by email link and/or OTP when configured. Do not make SMS the only recovery method.

Rate-limit registration, login, OTP verification, password reset and status lookup.

## Staff auth

- Require MFA for privileged staff and admin accounts.
- Prefer TOTP authenticator support; allow an approved fallback/recovery-code process.
- Store recovery codes securely.
- Support session revocation, account disable, role/scope changes and audit them.
- Use secure cookies/session settings, CSRF protection and re-authentication for highly sensitive actions where appropriate.

## Server-side authorisation

Add policies/middleware/services proving:

- panel member cannot see another panel candidate;
- region user cannot see another region unless explicitly scoped;
- medical notes are restricted;
- executive/auditor is read-only;
- IT administrator cannot silently exercise recruitment-decision powers merely because they manage the system;
- exports obey the same scope/masking rules.

## Audit foundation

Create append-oriented audit logging for sensitive actions. Store actor, action, entity, entity id, before/after where suitable, timestamp, session/device, request/correlation id, reason and approval reference where required.

Protect audit logs from ordinary operational delete/update routes. If technical administrators can alter the underlying database, document that the application provides tamper-evidence/limited immutability rather than claiming impossible absolute immutability.

## Tests

Create a role/scope access matrix test suite. Include negative tests for IDOR/BOLA-style access attempts.

Test MFA enrolment/recovery, disabled-user access, scope changes and audit events.

Run security-focused unit/integration tests and update traceability.
