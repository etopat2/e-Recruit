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
# Fixed non-production ports keep the isolated drill from colliding with the
# ordinary development stack. Override them explicitly if the drill host uses
# any of these ports.
export POSTGRES_PORT="${RESTORE_POSTGRES_PORT:-55432}"
export POSTGRES_DB="${RESTORE_POSTGRES_DB:-erecruit}"
export POSTGRES_USER="${RESTORE_POSTGRES_USER:-erecruit}"
export POSTGRES_PASSWORD="${RESTORE_POSTGRES_PASSWORD:-restore-drill-postgres-only}"
export REDIS_PASSWORD="${RESTORE_REDIS_PASSWORD:-restore-drill-redis-only}"
export MINIO_ROOT_USER="${RESTORE_MINIO_ROOT_USER:-erecruit-minio}"
export MINIO_ROOT_PASSWORD="${RESTORE_MINIO_ROOT_PASSWORD:-restore-drill-minio-only}"
export REDIS_PORT="${RESTORE_REDIS_PORT:-56379}"
export MINIO_API_PORT="${RESTORE_MINIO_API_PORT:-59010}"
export MINIO_CONSOLE_PORT="${RESTORE_MINIO_CONSOLE_PORT:-59011}"
export MAILPIT_SMTP_PORT="${RESTORE_MAILPIT_SMTP_PORT:-51026}"
export MAILPIT_UI_PORT="${RESTORE_MAILPIT_UI_PORT:-58026}"
export WORKER_PORT="${RESTORE_WORKER_PORT:-58001}"
export API_PORT="${RESTORE_API_PORT:-58000}"
export WEB_PORT="${RESTORE_WEB_PORT:-55173}"
export APP_PORT="${RESTORE_APP_PORT:-58080}"
compose=(docker compose -p "$project" -f docker-compose.yml)
object_helper=""
cleanup() {
  if [[ -n "$object_helper" ]]; then docker rm -f "$object_helper" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT

# The fixed project name is intentionally isolated from local and production stacks.
"${compose[@]}" down --volumes --remove-orphans
"${compose[@]}" up -d postgres redis minio minio-init document-worker
postgres_id="$("${compose[@]}" ps -q postgres)"
minio_id="$("${compose[@]}" ps -q minio)"

postgres_healthy=0
for _ in $(seq 1 60); do
  health="$(docker inspect --format '{{.State.Health.Status}}' "$postgres_id")"
  if [[ "$health" == "healthy" ]]; then postgres_healthy=1; break; fi
  if [[ "$health" == "unhealthy" ]]; then break; fi
  sleep 2
done
if [[ "$postgres_healthy" != "1" ]]; then
  printf 'Isolated PostgreSQL did not become healthy before restore.\n' >&2
  exit 1
fi

docker cp "${backup_directory}/postgres.dump" "${postgres_id}:/tmp/erecruit.dump"
docker exec "$postgres_id" sh -ec 'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore --clean --if-exists --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB" /tmp/erecruit.dump'
docker exec "$postgres_id" rm -f /tmp/erecruit.dump

# MinIO must not write metadata while its isolated data volume is replaced.
"${compose[@]}" stop minio
object_helper="${project}-object-restore"
docker rm -f "$object_helper" >/dev/null 2>&1 || true
docker create --name "$object_helper" --volumes-from "$minio_id" postgres:17.6-alpine sh -ec 'find /data -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && tar -C /data -xzf /tmp/objects.tar.gz' >/dev/null
docker cp "${backup_directory}/objects.tar.gz" "${object_helper}:/tmp/objects.tar.gz"
docker start -a "$object_helper" >/dev/null
docker rm -f "$object_helper" >/dev/null
object_helper=""

"${compose[@]}" up -d
BASE_URL="${BASE_URL:-http://localhost:${APP_PORT}}" infra/scripts/health-check.sh
"${compose[@]}" exec -T postgres sh -ec 'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select concat('"'"'applications='"'"', count(*)) from applications; select concat('"'"'documents='"'"', count(*)) from documents;"'

printf 'Restore drill completed in isolated Compose project %s. Review counts and workflow smoke evidence before sign-off.\n' "$project"
