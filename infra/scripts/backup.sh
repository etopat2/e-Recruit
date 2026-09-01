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
restore_services() {
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

docker exec "$minio_id" sh -ec 'tar -C /data -czf /tmp/objects.tar.gz .'
docker cp "${minio_id}:/tmp/objects.tar.gz" "${destination}/objects.tar.gz" >/dev/null
docker exec "$minio_id" rm -f /tmp/objects.tar.gz

{
  printf 'created_at_utc=%s\n' "$stamp"
  printf 'compose_file=%s\n' "$compose_file"
  printf 'release_commit=%s\n' "$(git rev-parse --verify HEAD 2>/dev/null || printf unknown)"
  printf 'postgres_sha256=%s\n' "$(sha256sum "${destination}/postgres.dump" | cut -d' ' -f1)"
  printf 'objects_sha256=%s\n' "$(sha256sum "${destination}/objects.tar.gz" | cut -d' ' -f1)"
} >"${destination}/manifest.txt"
chmod 600 "${destination}"/*

printf 'Consistent backup written to %s\n' "$destination"
