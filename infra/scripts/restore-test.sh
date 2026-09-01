#!/usr/bin/env bash
set -euo pipefail

if [[ "${RESTORE_DRILL_ACK:-}" != "destroy-isolated-restore-stack" ]]; then
  printf 'Set RESTORE_DRILL_ACK=destroy-isolated-restore-stack to confirm the isolated drill reset.\n' >&2
  exit 2
fi
if [[ $# -ne 1 ]]; then
  printf 'Usage: RESTORE_DRILL_ACK=destroy-isolated-restore-stack %s BACKUP_DIRECTORY\n' "$0" >&2
  exit 2
fi

backup_directory="$(cd "$1" && pwd)"
test -f "${backup_directory}/postgres.dump"
test -f "${backup_directory}/objects.tar.gz"
project="ups-erecruit-restore-drill"
compose=(docker compose -p "$project" -f docker-compose.yml)

# The fixed project name is intentionally isolated from local and production stacks.
"${compose[@]}" down --volumes --remove-orphans
"${compose[@]}" up -d postgres redis minio minio-init document-worker
postgres_id="$("${compose[@]}" ps -q postgres)"
minio_id="$("${compose[@]}" ps -q minio)"

docker cp "${backup_directory}/postgres.dump" "${postgres_id}:/tmp/erecruit.dump"
docker exec "$postgres_id" sh -ec 'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore --clean --if-exists --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB" /tmp/erecruit.dump'
docker exec "$postgres_id" rm -f /tmp/erecruit.dump

docker cp "${backup_directory}/objects.tar.gz" "${minio_id}:/tmp/objects.tar.gz"
docker exec "$minio_id" sh -ec 'rm -rf /data/* && tar -C /data -xzf /tmp/objects.tar.gz && rm -f /tmp/objects.tar.gz'

"${compose[@]}" up -d
BASE_URL="${BASE_URL:-http://localhost:8080}" infra/scripts/health-check.sh
"${compose[@]}" exec -T postgres sh -ec 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select concat('"'"'applications='"'"', count(*)) from applications; select concat('"'"'documents='"'"', count(*)) from documents;"'

printf 'Restore drill completed in isolated Compose project %s. Review counts and workflow smoke evidence before sign-off.\n' "$project"
