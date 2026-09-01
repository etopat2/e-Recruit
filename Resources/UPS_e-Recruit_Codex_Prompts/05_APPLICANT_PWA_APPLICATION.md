# Codex Prompt 05 — Applicant PWA, Dynamic Application Wizard and Submission

Implement the complete applicant-facing application flow as a mobile-first installable PWA.

## Dynamic application wizard

Build configuration-driven steps for:

- personal identity: first/middle/other/last names, DOB, sex, nationality, NIN, passport photo;
- origin address hierarchy;
- residence address hierarchy;
- contacts: primary/alternative phone and email;
- education history with subject/grade grid when applicable;
- employment history when enabled;
- professional registration when enabled;
- special skills and supporting evidence when enabled;
- LC recommendation details and source selection according to campaign policy;
- declarations/privacy/hard-copy acknowledgement;
- review and submit.

## UX requirements

- autosave every step;
- visible progress;
- resume on another authenticated device using server draft state;
- save a safe local draft for intermittent connectivity without caching unnecessary sensitive data;
- accessible labels/errors/focus;
- usable on common low-end Android browsers and desktop/cyber-café use;
- localised-string architecture ready for future languages, English default;
- prevent accidental duplicate submission;
- clear server validation errors even after offline/draft edits.

## Submission

Final submission must:

1. validate required data/document states;
2. create immutable submission snapshot/version;
3. issue concurrency-safe application reference;
4. generate QR code;
5. generate acknowledgement PDF;
6. show hard-copy instructions when required, including writing the reference on top of the application letter;
7. create notification jobs;
8. add status-history entry.

Do not encode interview centre into permanent application number.

## Applicant dashboard

Provide secure timeline/status, application summary, documents, notifications, downloadable acknowledgement/invitations when available, enquiry/appeal entry points and safe account recovery.

## Tests

Use Vitest/component tests plus Playwright E2E for:

- registration;
- autosave/resume;
- dynamic Warder/Wardress-like versus CPO/CASP-like forms;
- validation;
- final submission/reference issuance;
- duplicate-submit protection;
- acknowledgment generation;
- mobile viewport accessibility smoke tests.

Update traceability for FR-APP and AC-02.
