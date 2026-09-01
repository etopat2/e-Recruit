# Codex Prompt 10 — Interview Centres, Panels, Scheduling, Invitations and Attendance

Implement recruitment-centre operations.

## Centre and session operations

Create screens/APIs to:

- manage centre dates/sessions, reporting times, rooms and capacities;
- create multiple panels per centre/session;
- assign Panel Head, optional secretary and members with effective dates;
- expose only candidates within authorised scope;
- resolve district-to-centre mapping before scheduling.

## Candidate assignment

Implement deterministic capacity-based auto-distribution of eligible candidates across sessions/panels. Record algorithm/version/input ordering. Allow manual reassignment only with authorised reason and audit log.

## Interview invitation

Generate branded/templated PDF containing application reference, QR, centre, venue, date, reporting time, panel/session if policy allows, instructions and originals to carry. Support batch merge/print and notification jobs.

## Attendance

Support QR scan/reference search and statuses:

- Present;
- Absent;
- Late;
- Referred;
- Disqualified;
- configured equivalents.

Prevent normal assessment entry when candidate is not checked in unless an authorised exception with reason is created.

## Operational dashboard

Show expected, checked in, pending, absent and panel completion at centre level, with scope restrictions.

Design all critical centre workflows so they can later use offline packs without changing domain endpoints/events.

## Tests

Test deterministic assignment, capacity limits, manual audited reassignment, invitation generation, QR attendance, unauthorised cross-centre access and score gating by attendance.
