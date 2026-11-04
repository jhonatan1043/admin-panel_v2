# Instala Nginx Proxy Manager en Docker (Windows + Docker Desktop).
# Ejecutar en PowerShell COMO ADMINISTRADOR desde esta carpeta:
#   powershell -ExecutionPolicy Bypass -File .\instalar.ps1

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

Write-Host "1/5 Verificando Docker..." -ForegroundColor Cyan
try {
    $os = docker version --format '{{.Server.Os}}'
} catch {
    throw "Docker no responde. Abre Docker Desktop y espera a que diga 'Engine running'."
}
if ($os -ne 'linux') {
    throw "Docker esta en modo '$os'. Cambia a 'Linux containers' desde el icono de Docker Desktop."
}

Write-Host "2/5 Verificando que los puertos 80, 443 y 81 esten libres..." -ForegroundColor Cyan
$ocupados = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalPort -in 80, 443, 81 }
if ($ocupados) {
    $ocupados | ForEach-Object {
        $p = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue
        Write-Host ("   Puerto {0} usado por {1} (PID {2})" -f $_.LocalPort, $p.ProcessName, $_.OwningProcess) -ForegroundColor Yellow
    }
    $npmCorriendo = docker ps --filter "name=^npm$" --format '{{.Names}}'
    if (-not $npmCorriendo) {
        throw "Libera esos puertos (IIS, Apache, XAMPP, Skype...) y vuelve a ejecutar."
    }
}

Write-Host "3/5 Creando red 'proxy'..." -ForegroundColor Cyan
$red = docker network ls --filter "name=^proxy$" --format '{{.Name}}'
if (-not $red) { docker network create proxy | Out-Null } else { Write-Host "   Ya existe." }

Write-Host "4/5 Abriendo puertos 80 y 443 en el firewall de Windows..." -ForegroundColor Cyan
foreach ($puerto in 80, 443) {
    $nombre = "Nginx Proxy Manager $puerto"
    if (-not (Get-NetFirewallRule -DisplayName $nombre -ErrorAction SilentlyContinue)) {
        New-NetFirewallRule -DisplayName $nombre -Direction Inbound -Protocol TCP -LocalPort $puerto -Action Allow | Out-Null
    }
}

Write-Host "5/5 Levantando Nginx Proxy Manager..." -ForegroundColor Cyan
docker compose pull
docker compose up -d

Write-Host ""
Write-Host "Listo. Abre http://localhost:81 y crea tu usuario administrador." -ForegroundColor Green
Write-Host "(En versiones antiguas: admin@example.com / changeme)"
