# Codex Prompt 04 — Recruitment Campaign Configuration, Rules, Geography and Jurisdiction

Build the administrative configuration capabilities that make the platform reusable for Warder/Wardress, CPO, CASP and future campaigns.

## Campaign/post administration

Implement staff/admin screens and APIs to:

- create, edit, clone, publish/activate, close and archive campaigns;
- create one or more posts/categories per campaign;
- configure opening/closing dates, hard-copy deadline, age cut-off, interview/medical/training windows;
- enable/disable application sections such as employment, professional registration and special skills;
- configure required/optional document types, file count, formats, max size and hard-copy/original requirements;
- configure LC source policy: Origin only, Residence only, Origin or Residence, or approved custom option;
- configure eligibility rules through validated structured forms;
- configure stage ordering using a finite supported stage catalogue rather than an over-engineered workflow designer;
- configure assessment definitions, weights, pass marks and assessor modes;
- configure tie-break policy order;
- version published configuration.

Use validation to prevent impossible combinations, for example negative weights or total percentage weights that violate the campaign aggregation method.

## Geography/reference data

Implement admin maintenance for:

- District → County → Subcounty → Parish → Village hierarchy;
- prison regions;
- recruitment centres with location/address/contact/capacity;
- campaign/effective-dated district-to-centre mappings;
- mapping validation and unresolved-jurisdiction queue.

Never automatically guess the nearest centre for an unmapped district.

## Import/export reference data

Provide controlled CSV/XLSX import templates and validation reports for administrative units and jurisdiction mappings. Imports must be transactional or safely resumable and produce row-level errors.

## Tests

Demonstrate AC-01 by creating two materially different campaign/post configurations in automated tests/seed scenario without code changes.

Test invalid configuration, version changes after publication, LC-source rules and unmapped district handling.
