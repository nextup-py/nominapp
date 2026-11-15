<#
.SYNOPSIS
  Arma el build de Vite en esta PC y lo sube al servidor cPanel (que tiene Node viejo y no puede compilar).

.DESCRIPTION
  1. Verifica que la copia local este en la rama y el commit que se van a desplegar.
  2. npm ci y npm run build.
  3. Guarda el commit en public\build\commit.txt (deploy.sh lo compara con el codigo del servidor).
  4. Comprime a build.tar.gz y lo sube por scp a la carpeta personal del servidor.

  Despues, en el servidor: ./deploy-nominapp.sh actualizar

.PARAMETER Servidor
  usuario@host del servidor (por ejemplo usuario@servidor.ejemplo.com).

.PARAMETER Puerto
  Puerto SSH. Por defecto 22.

.PARAMETER Rama
  Rama a construir. Por defecto main.

.PARAMETER SinSubir
  Solo arma el archivo, no lo sube.

.EXAMPLE
  .\scripts\cpanel\Preparar-Build.ps1 -Servidor usuario@servidor.ejemplo.com

El archivo va en ASCII a proposito: Windows PowerShell 5.1 lee mal las tildes de un .ps1 sin BOM.
#>
param(
    [Parameter(Mandatory = $true)][string]$Servidor,
    [int]$Puerto = 22,
    [string]$Rama = 'main',
    [switch]$SinSubir
)

$ErrorActionPreference = 'Stop'

function Fallar($mensaje) {
    Write-Host ''
    Write-Host "[ERROR] $mensaje" -ForegroundColor Red
    exit 1
}

function Paso($mensaje) {
    Write-Host ''
    Write-Host "== $mensaje" -ForegroundColor Cyan
}

# Raiz del repositorio = dos carpetas arriba de este script.
$raiz = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location $raiz

Paso 'Verificando el repositorio'
$rama_actual = (git rev-parse --abbrev-ref HEAD).Trim()
if ($rama_actual -ne $Rama) {
    Fallar "Estas en la rama '$rama_actual' y se esperaba '$Rama'. Ejecutar: git checkout $Rama"
}

git fetch origin $Rama
if ($LASTEXITCODE -ne 0) { Fallar 'git fetch fallo.' }

$local = (git rev-parse HEAD).Trim()
$remoto = (git rev-parse "origin/$Rama").Trim()
if ($local -ne $remoto) {
    Fallar "Tu copia local ($($local.Substring(0,9))) no es igual a origin/$Rama ($($remoto.Substring(0,9))). Ejecutar: git pull origin $Rama"
}

$sucio = git status --porcelain -uno
if ($sucio) {
    Write-Host 'Hay cambios locales sin commitear; el build los incluiria:' -ForegroundColor Yellow
    Write-Host $sucio
    $resp = Read-Host 'Continuar de todos modos? (s/N)'
    if ($resp -notmatch '^(s|S|si|SI)$') { Fallar 'Cancelado.' }
}
Write-Host "Commit: $(git log --oneline -1)"

Paso 'npm ci'
npm ci
if ($LASTEXITCODE -ne 0) { Fallar 'npm ci fallo.' }

Paso 'npm run build'
npm run build
if ($LASTEXITCODE -ne 0) { Fallar 'npm run build fallo.' }

if (-not (Test-Path 'public\build\manifest.json') -and -not (Test-Path 'public\build\.vite\manifest.json')) {
    Fallar 'El build no genero manifest.json.'
}

Set-Content -Path 'public\build\commit.txt' -Value $local -NoNewline -Encoding Ascii

Paso 'Comprimiendo'
$archivo = Join-Path $HOME 'build.tar.gz'
if (Test-Path $archivo) { Remove-Item $archivo -Force }
tar -czf $archivo -C public build
if ($LASTEXITCODE -ne 0) { Fallar 'tar fallo (se necesita Windows 10 o superior).' }
$mb = [math]::Round((Get-Item $archivo).Length / 1MB, 1)
Write-Host "Listo: $archivo ($mb MB)"

if ($SinSubir) {
    Write-Host ''
    Write-Host 'Build armado. Subirlo a mano a la carpeta personal del servidor como build.tar.gz.'
    exit 0
}

Paso "Subiendo a $Servidor"
Write-Host 'Si pide la contrasena de la clave SSH, escribirla.'
scp -P $Puerto $archivo "${Servidor}:build.tar.gz"
if ($LASTEXITCODE -ne 0) { Fallar 'scp fallo. Probar subirlo a mano con el Administrador de archivos de cPanel.' }

Write-Host ''
Write-Host "Build subido (commit $($local.Substring(0,9)))." -ForegroundColor Green
Write-Host 'Siguiente paso, en el servidor:  ./deploy-nominapp.sh verificar   y despues   ./deploy-nominapp.sh actualizar'
