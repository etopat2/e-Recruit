param([string]$BaseUrl = "http://localhost:8080")

$ErrorActionPreference = "Stop"
$base = $BaseUrl.TrimEnd('/')
$paths = @('/healthz', '/api/v1/health/live', '/api/v1/health/ready', '/api/v1/campaigns')
foreach ($path in $paths) {
    $response = Invoke-WebRequest -UseBasicParsing -Uri "$base$path" -TimeoutSec 30
    if ($response.StatusCode -ne 200) { throw "Health check failed for $path with HTTP $($response.StatusCode)." }
}
Write-Host "UPS e-Recruit health checks passed at $base"
