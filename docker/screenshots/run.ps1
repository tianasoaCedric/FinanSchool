# Captures d'écran des pages (outil dev).
# Usage : .\docker\screenshots\run.ps1 -Theme light -Paths "/dashboard","/compte"
# Les images sont écrites dans docker/screenshots/out/ (ignoré par git).
param(
  [string]$Theme = "light",
  [string[]]$Paths = @("/dashboard"),
  [string]$Email = $env:SHOT_EMAIL,
  [string]$Password = $env:SHOT_PASSWORD
)
$ErrorActionPreference = "Stop"
$dir = $PSScriptRoot
New-Item -ItemType Directory -Force "$dir\out" | Out-Null
$img = "mcr.microsoft.com/playwright:v1.49.1-noble"
if (-not (Test-Path "$dir\node_modules\playwright")) {
  docker run --rm -v "${dir}:/work" -w /work $img npm install --no-audit --no-fund --silent
}
docker run --rm --network container:finanschool-frontend-1 `
  -e SHOT_EMAIL=$Email -e SHOT_PASSWORD=$Password -e SHOT_MOCK_SIM=$env:SHOT_MOCK_SIM `
  -v "${dir}:/work" -w /work $img node shot.mjs /work/out $Theme @Paths
