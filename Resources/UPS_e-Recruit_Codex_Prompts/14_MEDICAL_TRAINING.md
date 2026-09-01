# Codex Prompt 14 — Medical Examination, Final Selection, Reserve Replacement and Training/PATS Intake

Implement post-selection workflow with minimum necessary medical information.

## Medical scheduling

From an approved provisional shortlist:

- create facility/date rosters;
- generate invitations and QR/reference;
- record attendance;
- allow authorised medical officers only to record outcome and restricted notes/reference.

Outcomes:

- Fit;
- Not Fit;
- Deferred;
- Further Assessment Required;
- No Show.

General recruitment users should normally see only the permitted outcome, not detailed medical notes.

## Reserve replacement

When policy creates a vacancy after Not Fit/No Show/withdrawal, recommend the next eligible reserve candidate in the correct selection bucket. Do not automatically finalise replacement; require authorised approval and preserve audit lineage.

## Final selection

Final successful status must require the approved selection outcome, required medical outcome and final authorised approval. Public publishing configuration must minimise PII and never expose full NIN by default.

## Training/PATS

Generate reporting invitation with reference/QR, date, location, instructions and required items/documents.

Support configurable statuses including:

- Expected;
- Reported;
- Verified;
- Admitted;
- Late;
- Documentation Incomplete;
- No Show;
- Withdrawn;
- Replacement.

Provide intake dashboards by campaign/post, region, district, gender and verified skill.

## Downstream handoff

Provide a controlled export/API contract for finally admitted recruits. Do not integrate directly to an HR database. Mask/limit fields by policy and log export actor/purpose.

## Tests

Test medical role isolation, invitation/roster, replacement recommendation, final selection gates, public NIN masking, training reporting and controlled export. Update AC-08.
