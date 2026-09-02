#!/usr/bin/env bash
set -euo pipefail

compose_file="${COMPOSE_FILE:-docker-compose.production.yml}"
backup_root="${BACKUP_ROOT:-./backups}"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
destination="${backup_root%/}/${stamp}"
mkdir -p "$destination"
chmod 700 "$destination"

compose=(docker compose -f "$compose_file")
api_was_lowered=0
workers_stopped=0
object_helper=""
restore_services() {
  if [[ -n "$object_helper" ]]; then docker rm -f "$object_helper" >/dev/null 2>&1 || true; fi
  if [[ "$workers_stopped" == "1" ]]; then "${compose[@]}" start queue scheduler >/dev/null || true; fi
  if [[ "$api_was_lowered" == "1" ]]; then "${compose[@]}" exec -T api php artisan up >/dev/null || true; fi
}
trap restore_services EXIT

"${compose[@]}" exec -T api php artisan down --retry=60
api_was_lowered=1
"${compose[@]}" stop queue scheduler
workers_stopped=1

postgres_id="$("${compose[@]}" ps -q postgres)"
minio_id="$("${compose[@]}" ps -q minio)"
test -n "$postgres_id" && test -n "$minio_id"

docker exec "$postgres_id" sh -ec 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump --format=custom --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f /tmp/erecruit.dump'
docker cp "${postgres_id}:/tmp/erecruit.dump" "${destination}/postgres.dump" >/dev/null
docker exec "$postgres_id" rm -f /tmp/erecruit.dump

# The pinned MinIO image has no shell archive tools. Its data volume is
# mounted read-only into the pinned PostgreSQL Alpine image for archiving.
object_helper="ups-erecruit-object-backup-${stamp}"
docker create --name "$object_helper" --volumes-from "${minio_id}:ro" postgres:17.6-alpine tar -C /data -czf /tmp/objects.tar.gz . >/dev/null
docker start -a "$object_helper" >/dev/null
docker cp "${object_helper}:/tmp/objects.tar.gz" "${destination}/objects.tar.gz" >/dev/null
docker rm -f "$object_helper" >/dev/null
object_helper=""

{
  printf 'created_at_utc=%s\n' "$stamp"
  printf 'compose_file=%s\n' "$compose_file"
  printf 'release_commit=%s\n' "$(git rev-parse --verify HEAD 2>/dev/null || printf unknown)"
  printf 'postgres_sha256=%s\n' "$(sha256sum "${destination}/postgres.dump" | cut -d' ' -f1)"
  printf 'objects_sha256=%s\n' "$(sha256sum "${destination}/objects.tar.gz" | cut -d' ' -f1)"
} >"${destination}/manifest.txt"
chmod 600 "${destination}"/*

printf 'Consistent backup written to %s\n' "$destination"
