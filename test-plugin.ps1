<#
.SYNOPSIS
    Sets up and tests the Kanban plugin on GLPI 10 and/or 11 using the official glpi/glpi docker images.

.DESCRIPTION
    - Starts a GLPI stack (glpi/glpi:10 on port 8090, glpi/glpi:11 on port 8091) with MariaDB.
    - Waits for GLPI to finish its auto-install.
    - Installs and activates the kanban plugin via `bin/console glpi:plugin:install/activate`.
    - Seeds test tickets across all 6 statuses (exercises the frozen SLA feature).
    - Optionally (-Tests) runs the plugin PHPUnit suite inside each GLPI container.

.PARAMETER Versions
    Which GLPI major versions to test. Defaults to 10 and 11.

.PARAMETER Tests
    Also run the PHPUnit test suite inside each container.

.PARAMETER Down
    Stop and remove the containers/volumes of the selected stacks instead of setting them up.

.EXAMPLE
    .\test-plugin.ps1
    .\test-plugin.ps1 -Versions 10
    .\test-plugin.ps1 -Versions 10,11 -Tests
    .\test-plugin.ps1 -Down
#>
param(
    [string[]]$Versions = @('10', '11'),
    [switch]$Tests,
    [switch]$Down
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$composeFiles = @{
    '10' = 'docker-compose.glpi10.yml'
    '11' = 'docker-compose.glpi11.yml'
}
$ports = @{ '10' = 8090; '11' = 8091 }

function Invoke-DockerCompose {
    param([string]$ComposeFile, [string[]]$Arguments)
    $label = "docker compose -f $ComposeFile $($Arguments -join ' ')"
    Write-Host "==> $label"
    & docker compose -f $ComposeFile @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "FALHOU: $label (exit $LASTEXITCODE)"
    }
}

function Get-HttpCode {
    param([string]$Uri)
    if (Get-Command curl.exe -ErrorAction SilentlyContinue) {
        $code = & curl.exe -s -o NUL -w '%{http_code}' --max-time 5 $Uri
        if ($LASTEXITCODE -eq 0 -and $code -match '^\d+$') { return [int]$code }
        return -1
    }
    try {
        $r = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 5
        return [int]$r.StatusCode
    } catch { return -1 }
}

function Wait-GlpiReady {
    param([int]$Port, [int]$TimeoutSeconds = 300)
    # The official glpi/glpi image uses a front controller (DocumentRoot=/var/www/glpi/public),
    # so the readiness check targets the login page at "/".
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    do {
        Start-Sleep -Seconds 3
        if ((Get-HttpCode "http://localhost:$Port/") -eq 200) { return $true }
    } while ($sw.Elapsed.TotalSeconds -lt $TimeoutSeconds)
    return $false
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker não encontrado no PATH.'
}

foreach ($v in $Versions) {
    if (-not $composeFiles.ContainsKey($v)) {
        Write-Warning "Versão '$v' ignorada (use 10 ou 11)."
        continue
    }
    $file = $composeFiles[$v]
    $port = $ports[$v]

    if ($Down) {
        Invoke-DockerCompose $file @('down')
        Write-Host "Stack GLPI $v removida."
        continue
    }

    Write-Host "`n==== GLPI $v (http://localhost:$port) ===="

    Invoke-DockerCompose $file @('up', '-d', '--wait')

    if (-not (Wait-GlpiReady $port)) {
        throw "GLPI $v não ficou pronto em http://localhost:$port (login.php)"
    }
    Write-Host "GLPI $v pronto (auto-install concluído)."

    Write-Host '==> Instalando plugin kanban'
    Invoke-DockerCompose $file @('exec', '-T', 'glpi', 'php', 'bin/console', 'glpi:plugin:install', 'kanban', '-u', 'glpi')
    Invoke-DockerCompose $file @('exec', '-T', 'glpi', 'php', 'bin/console', 'glpi:plugin:activate', 'kanban')

    Write-Host '==> Criando tickets de teste'
    Invoke-DockerCompose $file @(
        'exec', '-T',
        '-e', 'KANBAN_DB_HOST=db',
        '-e', 'KANBAN_DB_USER=glpi',
        '-e', 'KANBAN_DB_PASS=glpi_password',
        '-e', 'KANBAN_DB_NAME=glpi',
        '-e', "KANBAN_URL=http://localhost:$port/plugins/kanban/front/kanban.php",
        'glpi', 'php', '/var/www/glpi/plugins/kanban/create_test_tickets.php'
    )

    if ($Tests) {
        Write-Host '==> Executando suíte PHPUnit (best-effort)'
        $binDir = Join-Path $root 'docker\bin'
        $phar = Join-Path $binDir 'phpunit-9.phar'
        if (-not (Test-Path $phar)) {
            New-Item -ItemType Directory -Force -Path $binDir | Out-Null
            Write-Host '==> Baixando phpunit 9.phar'
            Invoke-WebRequest -Uri 'https://phar.phpunit.de/phpunit-9.phar' -OutFile $phar
        }
        Invoke-DockerCompose $file @('cp', $phar, 'glpi:/tmp/phpunit-9.phar')
        try {
            Invoke-DockerCompose $file @(
                'exec', '-T',
                '-e', 'GLPI_ROOT=/var/www/glpi',
                '-e', 'GLPI_CONFIG_DIR=/var/glpi/config',
                'glpi', 'sh', '-c',
                'cd /var/www/glpi/plugins/kanban && php /tmp/phpunit-9.phar -c phpunit.xml.dist'
            )
        } catch {
            Write-Warning "Suíte PHPUnit falhou no GLPI $v (veja o erro acima)."
        }
    }

    Write-Host "==> GLPI $v configurado: http://localhost:$port/plugins/kanban/front/kanban.php (login glpi/glpi)"
}
