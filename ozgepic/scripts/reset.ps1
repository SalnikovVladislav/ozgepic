# =====================================================================
#  ПОЛНЫЙ ресет данных (Windows). Аналог scripts/reset.sh для dev.
# =====================================================================
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

$ans = Read-Host "❗ Это удалит БД, состояния пользователей и сертификаты. Продолжить? [yes/NO]"
if ($ans -ne 'yes') { Write-Host 'Отменено.'; exit 1 }

Write-Host '▶ docker compose down -v...'
docker compose -f docker-compose.yml -f docker-compose.prod.yml down -v 2>$null
if ($LASTEXITCODE -ne 0) { docker compose down -v }

Write-Host '▶ Удаляю JSON бота...'
Get-ChildItem -Path bot\data -Filter *.json -ErrorAction SilentlyContinue | Remove-Item -Force

Write-Host '▶ Удаляю TLS-сертификаты...'
if (Test-Path proxy\letsencrypt) { Remove-Item proxy\letsencrypt\* -Recurse -Force -ErrorAction SilentlyContinue }
if (Test-Path proxy\certbot-www) { Remove-Item proxy\certbot-www\* -Recurse -Force -ErrorAction SilentlyContinue }

Write-Host '✅ Всё чисто.'

