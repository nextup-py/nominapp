<#
.SYNOPSIS
    Deja una PC con Windows lista para usar como terminal de marcacion de Nominapp.

.DESCRIPTION
    - Crea un acceso directo que abre el terminal en modo kiosko (pantalla completa, sin pestanas ni barra
      de direcciones) con un perfil de navegador exclusivo, para que no se mezcle con el uso normal de la PC.
    - Lo coloca en el Escritorio y en la carpeta de Inicio de Windows (arranca solo al encender la PC).
    - Ajusta la energia: nunca suspender, nunca hibernar, pantalla siempre encendida (requiere ejecutar como
      administrador; sin permisos se omite y se avisa).

    El archivo esta escrito sin tildes a proposito: Windows PowerShell 5.1 lee mal los acentos de un .ps1
    sin BOM y asi funciona igual por correo, WhatsApp o pendrive.

.PARAMETER Url
    Direccion del terminal, tal como figura en el panel (Asistencias > Terminales > URL de acceso).
    Ejemplo: https://nominapp.ejemplo.com.py/terminal/abc123

.PARAMETER Browser
    Auto (por defecto) usa Chrome si esta instalado y, si no, Edge. Tambien se puede forzar Chrome o Edge.

.PARAMETER NoKiosk
    Abre el terminal en una ventana propia (sin pestanas ni barra de direcciones) en vez de pantalla completa.

.PARAMETER NoStartup
    No agrega el acceso directo a la carpeta de Inicio (el terminal no arranca solo).

.PARAMETER NoPowerSettings
    No toca el plan de energia.

.PARAMETER Launch
    Abre el terminal al terminar.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\Configurar-Terminal.ps1 -Url https://nominapp.ejemplo.com.py/terminal/abc123 -Launch
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^https://[^/\s]+/terminal/[A-Za-z0-9]+/?$')]
    [string]$Url,

    [ValidateSet('Auto', 'Chrome', 'Edge')]
    [string]$Browser = 'Auto',

    [switch]$NoKiosk,
    [switch]$NoStartup,
    [switch]$NoPowerSettings,
    [switch]$Launch
)

$ErrorActionPreference = 'Stop'

function Write-Step {
    param([string]$Message)
    Write-Host "[OK] $Message" -ForegroundColor Green
}

function Write-Warn {
    param([string]$Message)
    Write-Host "[!]  $Message" -ForegroundColor Yellow
}

function Test-IsAdmin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-BrowserPath {
    param([string]$Preferred)

    # Interpolacion y no Join-Path: ${env:ProgramFiles(x86)} es $null en un Windows de 32 bits.
    $chrome = @(
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
    )
    $edge = @(
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
    )

    $candidates = switch ($Preferred) {
        'Chrome' { $chrome }
        'Edge'   { $edge }
        default  { $chrome + $edge }
    }

    foreach ($path in $candidates) {
        if ($path -and (Test-Path -LiteralPath $path)) {
            return $path
        }
    }

    return $null
}

function New-Shortcut {
    param(
        [string]$Path,
        [string]$Target,
        [string]$Arguments,
        [string]$Description
    )

    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($Path)
    $shortcut.TargetPath = $Target
    $shortcut.Arguments = $Arguments
    $shortcut.WorkingDirectory = Split-Path -Parent $Target
    $shortcut.IconLocation = "$Target,0"
    $shortcut.Description = $Description
    $shortcut.Save()
}

function Invoke-PowerCfg {
    param([string[]]$Arguments)

    # En Windows PowerShell 5.1, redirigir el error de un programa nativo con 2>&1 lo convierte en un error
    # terminante si $ErrorActionPreference es 'Stop'; por eso se baja a 'Continue' solo durante la llamada.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & powercfg.exe @Arguments 2>&1 | Out-Null
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }

    if ($code -ne 0) {
        Write-Warn ("powercfg {0} no se pudo aplicar (codigo {1})." -f ($Arguments -join ' '), $code)
        return $false
    }

    return $true
}

if ($env:OS -ne 'Windows_NT') {
    throw 'Este script es solo para Windows.'
}

Write-Host ''
Write-Host 'Configuracion de terminal de marcacion - Nominapp' -ForegroundColor Cyan
Write-Host "Direccion: $Url"
Write-Host ''

# 1. Navegador
$browserPath = Get-BrowserPath -Preferred $Browser
if (-not $browserPath) {
    throw 'No se encontro Chrome ni Edge. Instalar Google Chrome (https://www.google.com/chrome) y volver a ejecutar.'
}
Write-Step "Navegador: $browserPath"

# 2. Acceso directo con perfil exclusivo
$profileDir = Join-Path $env:LOCALAPPDATA 'NominappTerminal\perfil'
New-Item -ItemType Directory -Force -Path $profileDir | Out-Null

$browserArgs = @(
    "--user-data-dir=`"$profileDir`"",
    '--no-first-run',
    '--no-default-browser-check',
    '--noerrdialogs',
    '--disable-session-crashed-bubble',
    '--autoplay-policy=no-user-gesture-required'
)

if ($NoKiosk) {
    $browserArgs += "--app=`"$Url`""
} else {
    $browserArgs += '--kiosk'
    $browserArgs += "`"$Url`""
}

$arguments = $browserArgs -join ' '
$shortcutName = 'Terminal Nominapp.lnk'
$description = 'Terminal de marcacion de asistencia'

$desktopShortcut = Join-Path ([Environment]::GetFolderPath('Desktop')) $shortcutName
New-Shortcut -Path $desktopShortcut -Target $browserPath -Arguments $arguments -Description $description
Write-Step "Acceso directo en el Escritorio: $desktopShortcut"

if (-not $NoStartup) {
    $startupShortcut = Join-Path ([Environment]::GetFolderPath('Startup')) $shortcutName
    New-Shortcut -Path $startupShortcut -Target $browserPath -Arguments $arguments -Description $description
    Write-Step "Inicio automatico al encender la PC: $startupShortcut"
}

# 3. Energia
if ($NoPowerSettings) {
    Write-Warn 'Plan de energia sin cambios (se pidio -NoPowerSettings).'
} elseif (-not (Test-IsAdmin)) {
    Write-Warn 'Sin permisos de administrador: no se cambio el plan de energia.'
    Write-Warn 'Volver a ejecutar el script con "Ejecutar como administrador", o ajustar Energia a mano (ver la guia).'
} else {
    $ok = $true
    foreach ($mode in @('ac', 'dc')) {
        $ok = (Invoke-PowerCfg @('/change', "standby-timeout-$mode", '0')) -and $ok
        $ok = (Invoke-PowerCfg @('/change', "hibernate-timeout-$mode", '0')) -and $ok
        $ok = (Invoke-PowerCfg @('/change', "monitor-timeout-$mode", '0')) -and $ok
    }
    $ok = (Invoke-PowerCfg @('/hibernate', 'off')) -and $ok
    # Cerrar la tapa de una laptop no debe suspender el equipo (no existe en PCs de escritorio: se ignora el aviso).
    [void](Invoke-PowerCfg @('/setacvalueindex', 'SCHEME_CURRENT', 'SUB_BUTTONS', 'LIDACTION', '0'))
    [void](Invoke-PowerCfg @('/setdcvalueindex', 'SCHEME_CURRENT', 'SUB_BUTTONS', 'LIDACTION', '0'))
    [void](Invoke-PowerCfg @('/setactive', 'SCHEME_CURRENT'))

    if ($ok) {
        Write-Step 'Energia: nunca suspender, nunca hibernar, pantalla siempre encendida.'
    } else {
        Write-Warn 'Algunos ajustes de energia no se aplicaron; revisar Configuracion > Sistema > Energia.'
    }
}

Write-Host ''
Write-Host 'Pendiente a mano (Windows no lo permite desde un script sin credenciales):' -ForegroundColor Cyan
Write-Host ' - Fecha y hora en automatico, y permiso de camara para el sitio la primera vez que se abra.'
Write-Host ' - Si Windows pide contrasena al despertar, desactivarlo; y si la PC debe arrancar sola, iniciar sesion automatica.'
Write-Host ' - En el panel, verificar Pantalla encendida: Activa y estado En linea.'
Write-Host ''

if ($Launch) {
    Start-Process -FilePath $browserPath -ArgumentList $arguments
    Write-Step 'Terminal abierto.'
}
