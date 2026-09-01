# Optional Codex Prompt — Independent Code Review and Defect Hunt

Act as an independent senior reviewer who did not write this code. Do not trust `STATUS.md` claims without evidence.

Read the UPS e-Recruit specification and implementation contract, inspect the full repository, run the test suite, and search specifically for:

- specification requirements missing from implementation;
- security scope bypasses/IDOR;
- unsafe NIN/document exposure;
- offline sync duplicate or last-write-wins bugs;
- race conditions in application references and selection runs;
- OCR results incorrectly treated as authoritative;
- verified values overwritten instead of versioned;
- same-viewport review behaviour missing or fake;
- campaign rules hard-coded for Warder/Wardress;
- UNEB integration or dependency accidentally introduced;
- scoring formulas hard-coded;
- skill claims influencing selection before verification;
- selection runs not reproducible/versioned;
- manual overrides that erase original rank/outcome;
- medical notes visible to unauthorised roles;
- audit gaps;
- upload validation/MIME/security weaknesses;
- public NIN leaks;
- PWA caching authenticated/sensitive data unsafely;
- offline pack scope too broad;
- deployment docs that do not match actual service names/commands;
- backup instructions not tested;
- tests that assert mocks without exercising the real domain behaviour.

For each defect, give severity, evidence, affected files and remediation. Then fix Critical/High defects, add regression tests, run the suite and update traceability. Do not change policy to make tests pass.
