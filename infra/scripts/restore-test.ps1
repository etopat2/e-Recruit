param(
    [Parameter(Mandatory = $true)][string]$BackupDirectory,
    [string]$Acknowledgement = $env:RESTORE_DRILL_ACK,
    [string]$BaseUrl = "http://localhost:58080"
)

$ErrorActionPreference = "Stop"
if ($Acknowledgement -ne 'destroy-isolated-restore-stack') {
    throw 'Pass -Acknowledgement destroy-isolated-restore-stack to confirm the isolated drill reset.'
}
$backup = (Resolve-Path -LiteralPath $BackupDirectory).Path
$databaseDump = Join-Path $backup 'postgres.dump'
$objectArchive = Join-Path $backup 'objects.tar.gz'
$manifest = Join-Path $backup 'manifest.txt'
foreach ($required in @($databaseDump, $objectArchive, $manifest)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { throw "Required backup artifact is missing: $required" }
}

$project = 'ups-erecruit-restore-drill'
$env:POSTGRES_DB = 'erecruit'; $env:POSTGRES_USER = 'erecruit'; $env:POSTGRES_PASSWORD = 'restore-drill-postgres-only'
$env:REDIS_PASSWORD = 'restore-drill-redis-only'
$env:MINIO_ROOT_USER = 'erecruit-minio'; $env:MINIO_ROOT_PASSWORD = 'restore-drill-minio-only'
$env:POSTGRES_PORT = '55432'; $env:REDIS_PORT = '56379'
$env:MINIO_API_PORT = '59010'; $env:MINIO_CONSOLE_PORT = '59011'
$env:MAILPIT_SMTP_PORT = '51026'; $env:MAILPIT_UI_PORT = '58026'
$env:WORKER_PORT = '58001'; $env:API_PORT = '58000'; $env:WEB_PORT = '55173'; $env:APP_PORT = '58080'
$compose = @('compose', '-p', $project, '-f', 'docker-compose.yml')
$objectHelper = $null
try {
    # The fixed project name and alternate ports isolate this destructive reset.
    & docker @compose down --volumes --remove-orphans
    if ($LASTEXITCODE -ne 0) { throw 'Could not reset the isolated restore project.' }
    & docker @compose up -d postgres redis minio minio-init document-worker
    if ($LASTEXITCODE -ne 0) { throw 'Could not start isolated data services.' }
    $postgresId = (& docker @compose ps -q postgres).Trim()
    $minioId = (& docker @compose ps -q minio).Trim()
    if (-not $postgresId -or -not $minioId) { throw 'Isolated PostgreSQL and MinIO containers were not found.' }
    $postgresHealthy = $false
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $health = (& docker inspect --format '{{.State.Health.Status}}' $postgresId).Trim()
        if ($health -eq 'healthy') { $postgresHealthy = $true; break }
        if ($health -eq 'unhealthy') { break }
        Start-Sleep -Seconds 2
    }
    if (-not $postgresHealthy) { throw 'Isolated PostgreSQL did not become healthy before restore.' }

    & docker cp $databaseDump "${postgresId}:/tmp/erecruit.dump"
    & docker exec $postgresId sh -ec 'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore --clean --if-exists --no-owner --no-acl -U "$POSTGRES_USER" -d "$POSTGRES_DB" /tmp/erecruit.dump'
    if ($LASTEXITCODE -ne 0) { throw 'Isolated PostgreSQL restore failed.' }
    & docker exec $postgresId rm -f /tmp/erecruit.dump

    # Stop isolated MinIO so it cannot update metadata while its volume is replaced.
    & docker @compose stop minio
    if ($LASTEXITCODE -ne 0) { throw 'Could not stop isolated MinIO for its object restore.' }
    $objectHelper = "${project}-object-restore"
    $existingHelper = & docker ps -aq --filter "name=$objectHelper"
    if ($existingHelper) { & docker rm -f $existingHelper | Out-Null }
    & docker create --name $objectHelper --volumes-from $minioId postgres:17.6-alpine sh -ec 'find /data -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && tar -C /data -xzf /tmp/objects.tar.gz' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the isolated object restore helper.' }
    & docker cp $objectArchive "${objectHelper}:/tmp/objects.tar.gz"
    & docker start -a $objectHelper
    if ($LASTEXITCODE -ne 0) { throw 'Isolated object restore failed.' }
    & docker rm -f $objectHelper | Out-Null
    $objectHelper = $null

    & docker @compose up -d
    if ($LASTEXITCODE -ne 0) { throw 'Could not start the complete isolated restore stack.' }
    & powershell -NoProfile -ExecutionPolicy Bypass -File infra/scripts/health-check.ps1 -BaseUrl $BaseUrl
    if ($LASTEXITCODE -ne 0) { throw 'Restored application health verification failed.' }
    & docker @compose exec -T postgres psql -U erecruit -d erecruit -Atc "select concat('applications=', count(*)) from applications; select concat('documents=', count(*)) from documents; select concat('audit_logs=', count(*)) from audit_logs;"
    if ($LASTEXITCODE -ne 0) { throw 'Could not read restored record counts.' }
    Write-Host "Restore drill completed in isolated Compose project $project at $BaseUrl"
}
finally {
    if ($objectHelper) {
        $existingHelper = & docker ps -aq --filter "name=$objectHelper"
        if ($existingHelper) { & docker rm -f $existingHelper | Out-Null }
    }
}
