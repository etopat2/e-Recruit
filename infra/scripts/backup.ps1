param(
    [string]$ComposeFile = "docker-compose.production.yml",
    [string]$BackupRoot = ".\backups"
)

$ErrorActionPreference = "Stop"
$stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
$destination = Join-Path (Resolve-Path (New-Item -ItemType Directory -Force -Path $BackupRoot)).Path $stamp
New-Item -ItemType Directory -Path $destination | Out-Null
$compose = @('compose', '-f', $ComposeFile)
$lowered = $false
$workersStopped = $false
try {
    & docker @compose exec -T api php artisan down --retry=60
    if ($LASTEXITCODE -ne 0) { throw 'Could not enter maintenance mode.' }
    $lowered = $true
    & docker @compose stop queue scheduler
    if ($LASTEXITCODE -ne 0) { throw 'Could not stop asynchronous writers.' }
    $workersStopped = $true

    $postgresId = (& docker @compose ps -q postgres).Trim()
    $minioId = (& docker @compose ps -q minio).Trim()
    if (-not $postgresId -or -not $minioId) { throw 'PostgreSQL and MinIO must be running.' }

    & docker exec $postgresId sh -ec 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump --format=custom --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f /tmp/erecruit.dump'
    if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL backup failed.' }
    & docker cp "${postgresId}:/tmp/erecruit.dump" (Join-Path $destination 'postgres.dump')
    & docker exec $postgresId rm -f /tmp/erecruit.dump

    & docker exec $minioId sh -ec 'tar -C /data -czf /tmp/objects.tar.gz .'
    if ($LASTEXITCODE -ne 0) { throw 'Object backup failed.' }
    & docker cp "${minioId}:/tmp/objects.tar.gz" (Join-Path $destination 'objects.tar.gz')
    & docker exec $minioId rm -f /tmp/objects.tar.gz

    $dbHash = (Get-FileHash (Join-Path $destination 'postgres.dump') -Algorithm SHA256).Hash.ToLowerInvariant()
    $objectHash = (Get-FileHash (Join-Path $destination 'objects.tar.gz') -Algorithm SHA256).Hash.ToLowerInvariant()
    $commit = (& git rev-parse --verify HEAD 2>$null)
    @("created_at_utc=$stamp", "compose_file=$ComposeFile", "release_commit=$commit", "postgres_sha256=$dbHash", "objects_sha256=$objectHash") |
        Set-Content -Encoding utf8 (Join-Path $destination 'manifest.txt')
    Write-Host "Consistent backup written to $destination"
}
finally {
    if ($workersStopped) { & docker @compose start queue scheduler | Out-Null }
    if ($lowered) { & docker @compose exec -T api php artisan up | Out-Null }
}
