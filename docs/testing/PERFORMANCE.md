# Performance and resilience validation

## Targets

Targets are engineering gates pending UPS/hosting approval. Typical non-file API actions target p95 below 1.5 seconds and p99 below 3 seconds under the agreed normal load. Error rate must remain below 1%. Submission/reference issuance must be atomic and duplicate-free. OCR is measured as asynchronous queue throughput and is excluded from synchronous response targets.

## Reproducible datasets

The generator creates obviously synthetic records only and is disabled in production:

```sh
docker compose exec api php artisan erecruit:generate-synthetic --count=10000 --seed=20260901
docker compose exec api php artisan erecruit:generate-synthetic --count=50000 --seed=20260901
docker compose exec api php artisan erecruit:generate-synthetic --count=150000 --seed=20260901
```

Record CPU model/count, RAM, storage type, container limits, database settings, release commit, dataset counts and whether the object store is local or remote for every result. A result without that context is not comparable evidence.

## k6 profiles

The committed smoke profile exercises liveness, campaigns and optional authenticated list/dashboard traffic:

```sh
docker run --rm --network host -i grafana/k6:0.54.0 run - \
  -e BASE_URL=http://127.0.0.1:8080 \
  -e PUBLIC_VUS=10 -e STAFF_VUS=2 -e DURATION=60s \
  < tests/load/k6-smoke.js
```

Set `API_TOKEN` to a synthetic scoped staff token for authenticated traffic. Never put a production token in shell history or a result file. Separate soak/deadline-surge runs should progressively test 25, 100 and the infrastructure-approved VU level. Upload tests must use generated files; offline sync tests must use unique event UUIDs.

## Queue and dependency exercises

- Stop `document-worker`, upload synthetic evidence, confirm the document becomes failed after configured retries, restart the worker, retry the failed job and confirm a reviewable result.
- Stop SMTP or point to a rejecting test server, confirm the recruitment transaction remains committed and notification state becomes failed/retryable.
- Stop Redis and confirm readiness fails while PostgreSQL application data remains intact; restore Redis before accepting traffic.
- Stop MinIO and confirm readiness and uploads fail without a document metadata/original mismatch; restore and retry.
- Create one open protected-field conflict and one outstanding offline event; confirm selection certification returns HTTP 409.

## Query evidence

High-volume indexes are defined for campaign/post/status application filtering, applicant update lookup, evidence fields, assessment assignments, ranking bucket/order, audit chronology, notification state and offline conflicts. Use PostgreSQL `EXPLAIN (ANALYZE, BUFFERS)` on the actual 50k/150k staging dataset before altering indexes. Store redacted plans with the release evidence; do not copy applicant values.

No national-scale results are claimed from a developer workstation. The go-live gate requires measured results on the approved pilot/production-like hardware.
