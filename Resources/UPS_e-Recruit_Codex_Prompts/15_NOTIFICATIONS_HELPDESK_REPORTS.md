# Codex Prompt 15 — Notifications, Applicant Tracking, Helpdesk/Appeals, Reports and Official Documents

Implement communication and reporting modules.

## Notification engine

Support channels:

- email via SMTP/gateway;
- SMS via pluggable approved gateway adapter where configured;
- in-portal notifications;
- PWA push where supported.

The core app must continue working if SMS or push is unavailable.

Implement templates scoped/versioned by campaign/post/event. Log attempts, provider response metadata, delivered/failed state where available, retry count and next retry.

Triggers should include submission, hard-copy receipt/query, verification query/result, interview invite/reminder/reschedule, medical invite/reminder, final result available, training invite/reminder and approved reserve replacement.

## Secure tracking

Provide authenticated applicant status timeline. If a public status lookup is offered, require application reference plus OTP or rate-limited verification challenge; never expose sensitive data from application number alone.

## Helpdesk and appeals

Implement structured tickets linked to application/campaign:

- category;
- description;
- evidence attachment where allowed;
- assigned team/officer;
- status and SLA timestamps;
- responses;
- audit trail.

Appeals are campaign-policy dependent. An approved appeal that changes verified data must trigger appropriate eligibility/downstream re-evaluation and record the causal link.

## Reports and dashboards

Implement role-scoped dashboard metrics and filterable reports for:

- applications by day/post/region/district/gender;
- hard-copy completion;
- verification backlog/discrepancies;
- document-intelligence mismatches/low confidence;
- eligibility results/reasons;
- interview attendance/panel completion;
- assessment distributions;
- quotas allocated/filled;
- selected/reserve/skill representation;
- medical outcomes;
- training reporting;
- offline last-sync/pending/conflicts;
- overrides/audit.

## Export/print

Support XLSX, CSV where useful, PDF and print-friendly views with field masking and scope enforcement. Include bulk merged invitation PDFs for interview/medical/training centre use.

Generate official artefact templates with placeholder/approved brand asset hooks, QR/reference and verification metadata. Never fabricate official signatures.

## Tests

Test notification retries/failures, scope masking in exports, generated PDFs, secure tracking rate limits, appeals re-evaluation linkage and dashboard query correctness.
