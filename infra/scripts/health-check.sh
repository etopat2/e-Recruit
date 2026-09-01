#!/usr/bin/env bash
set -euo pipefail

base_url="${BASE_URL:-http://localhost:8080}"
base_url="${base_url%/}"

curl --fail --silent --show-error "${base_url}/healthz" >/dev/null
curl --fail --silent --show-error "${base_url}/api/v1/health/live" >/dev/null
curl --fail --silent --show-error "${base_url}/api/v1/health/ready" >/dev/null
curl --fail --silent --show-error "${base_url}/api/v1/campaigns" >/dev/null

printf 'UPS e-Recruit health checks passed at %s\n' "$base_url"
