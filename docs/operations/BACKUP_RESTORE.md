# Backup and restore

## Policy boundary

UPS/hosting stakeholders must approve actual RPO, RTO, schedule and retention. Engineering recommends PostgreSQL point-in-time recovery where supported, at least daily consistent DB/object backups during active recruitment, versioned/immutable off-host object copies and a quarterly restore drill; these are recommendations, not declared policy.

Secrets/configuration are backed up by the approved secret/configuration platform, never placed in the repository backup. Encrypt backup destinations and transport; restrict and audit access.

## Consistent backup

`infra/scripts/backup.sh` enters Laravel maintenance mode, stops queue/scheduler writers, creates a PostgreSQL custom dump and MinIO data archive, computes SHA-256 values, then restores services through a trap:

```sh
COMPOSE_FILE=docker-compose.production.yml BACKUP_ROOT=/approved/encrypted/target infra/scripts/backup.sh
```

Copy the completed timestamped directory off-host, verify hashes there and record object/database counts. If the deployment uses managed PostgreSQL/S3, replace the mechanics with provider snapshots/versioning while preserving the same consistency window and manifest.

## Isolated restore drill

Only the fixed `ups-erecruit-restore-drill` Compose project is destroyed by the script:

```sh
RESTORE_DRILL_ACK=destroy-isolated-restore-stack infra/scripts/restore-test.sh /approved/backup/20260901T120000Z
```

Before sign-off:

1. Seed clearly synthetic applications and upload generated documents.
2. Capture application/document/audit counts and representative object SHA-256 values.
3. Take/copy/verify backup.
4. Run the isolated reset/restore.
5. Compare counts/hashes, login, open a protected document, submit an idempotent duplicate offline event, verify audit chain and generate a synthetic acknowledgement.
6. Record achieved data-loss window and recovery duration; compare only to stakeholder-approved targets.

Never restore over production as a drill. A real recovery requires incident/change approval, traffic isolation, recovery point selection and offline-event/notification reconciliation.
