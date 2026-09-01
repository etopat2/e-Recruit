# Offline centre runbook

## Before departure/opening

Inventory assigned encrypted managed devices; confirm browser/PWA version, clock, charge, screen lock and authorised user/MFA. Register each device, download only its centre/session/panel/action-scoped pack, verify manifest fingerprint/expiry/roster count, then test offline reopen. Never transfer a pack through personal messaging/storage.

## Offline operation

Use QR where readable and the approved manual lookup otherwise. Confirm candidate identity according to UPS procedure. Record attendance/scores once; the outbox shows pending events and stable event UUIDs. Do not clear browser data, change system time, share devices or attempt out-of-scope records. A lost device is an incident.

## Reconnect and reconcile

Reconnect to the trusted network, sync, retain the screen until acknowledgements arrive, then compare accepted/duplicate/conflict/rejected counts with the local pending count. Resolve protected conflicts only through the authorised conflict workflow with source/justification; do not re-enter an event to hide a conflict. Panel closure and selection remain blocked until outstanding events/conflicts are zero.

At day end record package owner/device, manifest count, local events, server acknowledgements, duplicates, conflicts, rejected events, expiry/revocation and coordinator/panel-head sign-off. Return and inventory devices.
