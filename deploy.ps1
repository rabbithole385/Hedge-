# deploy.ps1 - trigger a Render redeploy.
#
# Auto-deploy is unavailable for this service, so we call its Deploy Hook.
# Get the hook from: Render Dashboard -> your service -> Settings -> Deploy Hook
# (looks like https://api.render.com/deploy/srv-xxxxxxxx?key=yyyyyyyy)
#
# Setup (once) - pick either:
#   1) Environment variable:
#        $env:RENDER_DEPLOY_HOOK = "https://api.render.com/deploy/srv-xxxx?key=yyyy"
#   2) A git-ignored file named .render-deploy-hook next to this script,
#      containing just the hook URL.
#
# Then every time you want to ship:   ./deploy.ps1
# Add -Push to also push the current branch to origin first:   ./deploy.ps1 -Push

param(
    [string]$Hook,
    [switch]$Push
)

$ErrorActionPreference = 'Stop'

if ($Push) {
    Write-Host "Pushing to origin..." -ForegroundColor Cyan
    git push origin main
}

if (-not $Hook) { $Hook = $env:RENDER_DEPLOY_HOOK }
$hookFile = Join-Path $PSScriptRoot '.render-deploy-hook'
if (-not $Hook -and (Test-Path $hookFile)) {
    $Hook = (Get-Content $hookFile -Raw).Trim()
}

if (-not $Hook) {
    Write-Host "No Render Deploy Hook configured." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Set it once with either:"
    Write-Host '  $env:RENDER_DEPLOY_HOOK = "https://api.render.com/deploy/srv-xxxx?key=yyyy"'
    Write-Host "  or save that URL to .render-deploy-hook (git-ignored)."
    exit 1
}

Write-Host "Triggering Render deploy..." -ForegroundColor Cyan
try {
    $response = Invoke-RestMethod -Method Post -Uri $Hook -TimeoutSec 30
    $deployId = $response.deploy.id
    $status = $response.deploy.status
    if ($deployId) {
        Write-Host "Deploy triggered: $deployId ($status)" -ForegroundColor Green
    } else {
        Write-Host "Deploy triggered." -ForegroundColor Green
    }
} catch {
    Write-Host "Deploy request failed: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
