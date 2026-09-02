param(
    [string]$BaseUrl = "http://localhost:8080",
    [int]$Attempts = 5,
    [int]$TimeoutSeconds = 60
)

$ErrorActionPreference = "Stop"
$base = $BaseUrl.TrimEnd('/')
$paths = @('/healthz', '/api/v1/health/live', '/api/v1/health/ready', '/api/v1/campaigns')
foreach ($path in $paths) {
    $lastError = $null
    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri "$base$path" -TimeoutSec $TimeoutSeconds
            if ($response.StatusCode -eq 200) { $lastError = $null; break }
            $lastError = "HTTP $($response.StatusCode)"
        }
        catch { $lastError = $_.Exception.Message }
        if ($attempt -lt $Attempts) { Start-Sleep -Seconds 2 }
    }
    if ($lastError) { throw "Health check failed for $path after $Attempts attempt(s): $lastError" }
}
Write-Host "UPS e-Recruit health checks passed at $base"
