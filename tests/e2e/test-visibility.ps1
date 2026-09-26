<#
.SYNOPSIS
    Testes E2E de visibilidade do plugin Kanban via web (login real) em GLPI 10 e/ou 11.

.DESCRIPTION
    Semeia usuários/grupos/gerentes/perfis e chamados (tests/e2e/seeder.php) e valida as
    regras de visibilidade do quadro:
      - Perfil sem o direito 'plugin_kanban' não acessa o quadro (HTTP 403).
      - O usuário vê apenas chamados dos grupos aos quais pertence (subgrupos inclusos)
        ou nos quais está como técnico (ASSIGN).
      - Membro comum NÃO vê chamado delegado individualmente a um colega do grupo.
      - Gerente do grupo vê todos os chamados do grupo, inclusive os delegados a outros
        membros (e a membros de subgrupos).
      - Observador do grupo não torna o chamado visível.
      - A restrição vale para todos os perfis, incluindo Super-Admin.

.PARAMETER Versions
    Versões do GLPI a testar (10 e/ou 11).

.PARAMETER Cleanup
    Apenas remove o cenário semeado (não executa asserções).

.EXAMPLE
    .\test-visibility.ps1
    .\test-visibility.ps1 -Versions 11
    .\test-visibility.ps1 -Cleanup
#>
param(
    [int[]]$Versions = @(10, 11),
    [switch]$Cleanup
)

$ErrorActionPreference = 'Stop'

$composeFiles = @{ 10 = 'docker-compose.glpi10.yml'; 11 = 'docker-compose.glpi11.yml' }
$ports        = @{ 10 = 8090; 11 = 8091 }
$baseUrl      = @{ 10 = 'http://localhost:8090'; 11 = 'http://localhost:8091' }

# O seeder nunca cria contas com senha fixa: a senha vem do ambiente.
$WebPassword = $env:KANBAN_TEST_PASS
if ([string]::IsNullOrWhiteSpace($WebPassword)) {
    Write-Error "Defina KANBAN_TEST_PASS antes de rodar (ex.: `$env:KANBAN_TEST_PASS='<senha-de-teste>'). O seeder recusa criar contas sem senha explícita."
    exit 1
}

$expected = @{
    'kb_manager'  = @('KB-E2E grupo-pai', 'KB-E2E delegado-membro', 'KB-E2E subgrupo')
    'kb_member'   = @('KB-E2E grupo-pai', 'KB-E2E subgrupo')
    'kb_child'    = @('KB-E2E subgrupo')
    'kb_outsider' = @('KB-E2E fora')
}
$expectedAbsent = @{
    'kb_manager'  = @('KB-E2E fora', 'KB-E2E outro-grupo', 'KB-E2E observador', 'KB-E2E estrangeiro')
    'kb_member'   = @('KB-E2E delegado-membro', 'KB-E2E fora', 'KB-E2E outro-grupo', 'KB-E2E observador', 'KB-E2E estrangeiro')
    'kb_child'    = @('KB-E2E grupo-pai', 'KB-E2E delegado-membro', 'KB-E2E fora', 'KB-E2E outro-grupo')
    'kb_outsider' = @('KB-E2E grupo-pai', 'KB-E2E delegado-membro', 'KB-E2E subgrupo', 'KB-E2E outro-grupo', 'KB-E2E observador')
}

function Invoke-Seeder {
    param([int]$Version)
    $file = $composeFiles[$Version]
    $args = @(
        'exec', '-T',
        '-e', 'KANBAN_DB_HOST=db',
        '-e', 'KANBAN_DB_USER=glpi',
        '-e', 'KANBAN_DB_PASS=glpi_password',
        '-e', 'KANBAN_DB_NAME=glpi',
        '-e', "KANBAN_TEST_PASS=$WebPassword",
        'glpi', 'php', '/var/www/glpi/plugins/kanban/tests/e2e/seeder.php'
    )
    if ($Cleanup) {
        $args = @('exec', '-T', '-e', 'KANBAN_CLEANUP=1', 'glpi', 'php', '/var/www/glpi/plugins/kanban/tests/e2e/seeder.php')
    }
    & docker compose -f $file @args
    if ($LASTEXITCODE -ne 0) { throw "Seeder falhou no GLPI $Version (exit $LASTEXITCODE)" }
}

function Get-LoginFields {
    param([string]$Html)
    $user = $null; $pass = $null; $action = '/'; $csrf = ''
    if ($Html -match '<input[^>]*type="text"[^>]*name="([^"]+)"') { $user = $Matches[1] }
    if ($Html -match '<input[^>]*type="password"[^>]*name="([^"]+)"') { $pass = $Matches[1] }
    if ($Html -match '<form[^>]*action="([^"]+)"') { $action = $Matches[1] }
    if ($Html -match '<input[^>]*type="hidden"[^>]*name="_glpi_csrf_token"[^>]*value="([^"]+)"') { $csrf = $Matches[1] }
    return @($user, $pass, $action, $csrf)
}

function Get-BoardTitles {
    param([int]$Version, [string]$Login, [string]$Password = $WebPassword)
    # Espaça os logins para não disparar o throttle anti-brute-force do GLPI.
    Start-Sleep -Milliseconds 900
    $base = $baseUrl[$Version]
    $jar = Join-Path $env:TEMP ("kb_e2e_$Version.cookies.txt")
    Remove-Item $jar -ErrorAction SilentlyContinue

    $loginPage = & curl.exe -s -c $jar "$base/"
    $f = Get-LoginFields -Html $loginPage
    if (-not $f[0] -or -not $f[1]) {
        Write-Host "  (login fields nao encontrados na pagina do GLPI $Version)" -ForegroundColor Yellow
        return @{ 'blocked' = $true; 'titles' = @() }
    }

    $data = "$($f[0])=$Login&$($f[1])=$Password"
    if ($f[3]) { $data += "&_glpi_csrf_token=$($f[3])" }
    $loginStatus = & curl.exe -s -o NUL -b $jar -c $jar -d $data -w '%{http_code}' "$base$($f[2])"

    $endpoint = "$base/plugins/kanban/front/kanban.php"
    $raw = & curl.exe -s -b $jar -w "`n%{http_code}" ($endpoint + '?action=get_tickets')
    $code = ($raw -split "`n")[-1]
    $body = ($raw -split "`n")[0..($raw.Length - 2)] -join "`n"
    $body = $body.TrimStart()

    if (-not $body.StartsWith('{') -and -not $body.StartsWith('[')) {
        return @{ 'blocked' = $true; 'titles' = @(); 'code' = $code }
    }
    $json = $body | ConvertFrom-Json
    $titles = @()
    if ($null -ne $json) {
        $statuses = if ($null -ne $json.statuses) { $json.statuses } else { $json }
        foreach ($p in $statuses.PSObject.Properties) {
            foreach ($t in $p.Value) { $titles += [string]$t.title }
        }
    }
    return @{ 'blocked' = $false; 'titles' = $titles; 'code' = $code }
}

function Get-FilterData {
    param([int]$Version, [string]$Login, [string]$Password = $WebPassword)
    # Mesmo login do Get-BoardTitles, porém na ação que alimenta os dropdowns.
    Start-Sleep -Milliseconds 900
    $base = $baseUrl[$Version]
    $jar = Join-Path $env:TEMP ("kb_e2e_fd_$Version.cookies.txt")
    Remove-Item $jar -ErrorAction SilentlyContinue

    $loginPage = & curl.exe -s -c $jar "$base/"
    $f = Get-LoginFields -Html $loginPage
    if (-not $f[0] -or -not $f[1]) { return @{ 'blocked' = $true; 'requesters' = @() } }

    $data = "$($f[0])=$Login&$($f[1])=$Password"
    if ($f[3]) { $data += "&_glpi_csrf_token=$($f[3])" }
    & curl.exe -s -o NUL -b $jar -c $jar -d $data "$base$($f[2])" | Out-Null

    $raw = & curl.exe -s -b $jar ($base + '/plugins/kanban/front/kanban.php?action=get_filter_data')
    $body = ($raw -join "`n").TrimStart()
    if (-not $body.StartsWith('{')) { return @{ 'blocked' = $true; 'requesters' = @() } }
    $json = $body | ConvertFrom-Json
    $names = @()
    if ($null -ne $json.requesters) { $names = @($json.requesters | ForEach-Object { [string]$_.name }) }
    return @{ 'blocked' = $false; 'requesters' = $names }
}

$failures = 0
$checks = 0

function Check([bool]$cond, [string]$msg) {
    $script:checks++
    if ($cond) {
        Write-Host "  PASS :: $msg"
    } else {
        $script:failures++
        Write-Host "  FAIL :: $msg" -ForegroundColor Red
    }
}

function Contains($list, $item) { return ($list | Where-Object { $_ -eq $item }).Count -gt 0 }

foreach ($v in $Versions) {
    if (-not $composeFiles.ContainsKey($v)) { continue }
    if ($Cleanup) {
        Invoke-Seeder -Version $v
        Write-Host "Cenario removido no GLPI $v."
        continue
    }

    Write-Host "`n===== GLPI $v ($($baseUrl[$v])) ====="
    Invoke-Seeder -Version $v

    Write-Host '-- Regra: perfil precisa do direito plugin_kanban --'
    $noright = Get-BoardTitles -Version $v -Login 'kb_noright'
    Check ($noright.blocked) "kb_noright (perfil sem direito) bloqueado no quadro (HTTP $($noright.code))"

    Write-Host '-- Regras de visibilidade por usuário --'
    foreach ($user in @('kb_manager','kb_member','kb_child','kb_outsider')) {
        $board = Get-BoardTitles -Version $v -Login $user
        if ($board.blocked) {
            Write-Host "  FAIL :: $user deveria acessar mas foi bloqueado (HTTP $($board.code))" -ForegroundColor Red
            $script:failures++
            $script:checks++
            continue
        }
        $seen = $board.titles
        foreach ($want in $expected[$user]) {
            Check (Contains $seen $want) "$user VÊ '$want'"
        }
        foreach ($absent in $expectedAbsent[$user]) {
            Check (-not (Contains $seen $absent)) "$user NÃO vê '$absent'"
        }
    }

    Write-Host '-- Regra: restrição vale para todos os perfis (incl. Super-Admin) --'
    $admin = Get-BoardTitles -Version $v -Login 'glpi' -Password 'glpi'
    $kbSeen = $admin.titles | Where-Object { $_ -like 'KB-E2E*' }
    Check (($admin.blocked) -eq $false) 'Super-Admin acessa o quadro'
    Check ($kbSeen.Count -eq 0) 'Super-Admin não vê chamados do cenário KB-E2E (sem participação)'

    Write-Host '-- Regra: gerente do grupo vê delegações a outros usuários --'
    $mgr = Get-BoardTitles -Version $v -Login 'kb_manager'
    Check (Contains $mgr.titles 'KB-E2E delegado-membro') 'kb_manager vê chamado delegado a kb_colleague (membro do grupo)'
    $mem = Get-BoardTitles -Version $v -Login 'kb_member'
    Check (-not (Contains $mem.titles 'KB-E2E delegado-membro')) 'kb_member (não gerente) NÃO vê chamado delegado ao colega'

    Write-Host '-- Regra: filtro de solicitantes só revela requisitantes de chamados visíveis --'
    # Cada chamado do cenário tem um solicitante distinto:
    #   grupo-pai=kb_member, delegado-membro=kb_colleague, subgrupo=kb_child,
    #   fora=kb_noright, outro-grupo=kb_extuser, observador=kb_outsider,
    #   estrangeiro=kb_noright
    # Os nomes são comparados por substring porque getUserName() monta o nome
    # completo (primeiro nome + sobrenome) conforme a configuração da instância.
    $req = @{}
    foreach ($user in @('kb_manager', 'kb_member', 'kb_child', 'kb_outsider')) {
        $req[$user] = @((Get-FilterData -Version $v -Login $user).requesters)
    }
    function ContainsLike($list, $item) {
        return @($list | Where-Object { $_ -like "*$item*" }).Count -gt 0
    }
    Check (ContainsLike $req['kb_manager'] 'KB kb_member') 'kb_manager VÊ solicitante do chamado grupo-pai (visível)'
    Check (ContainsLike $req['kb_manager'] 'KB kb_colleague') 'kb_manager VÊ solicitante do chamado delegado (visível como gerente)'
    Check (ContainsLike $req['kb_manager'] 'KB kb_child') 'kb_manager VÊ solicitante do chamado do subgrupo (visível)'
    Check (-not (ContainsLike $req['kb_manager'] 'KB kb_extuser')) 'kb_manager NÃO vê solicitante do chamado de outro grupo (invisível)'
    Check (-not (ContainsLike $req['kb_manager'] 'KB kb_noright')) 'kb_manager NÃO vê solicitante dos chamados fora do seu grupo (invisível)'
    Check (-not (ContainsLike $req['kb_member'] 'KB kb_colleague')) 'kb_member NÃO vê solicitante do chamado delegado a colega (invisível)'
    Check (ContainsLike $req['kb_member'] 'KB kb_child') 'kb_member VÊ solicitante do chamado do subgrupo (visível)'
    Check (-not (ContainsLike $req['kb_member'] 'KB kb_noright')) 'kb_member NÃO vê solicitante do chamado fora (invisível)'
    Check (ContainsLike $req['kb_outsider'] 'KB kb_noright') 'kb_outsider VÊ o solicitante do próprio chamado'
    Check ($req['kb_outsider'].Count -eq 1) "kb_outsider vê SOMENTE 1 solicitante (obtido: $($req['kb_outsider'].Count))"
}

Write-Host "`n===== RESUMO ====="
if ($failures -eq 0) {
    Write-Host "TODOS OS $checks CHECKS PASSARAM" -ForegroundColor Green
} else {
    Write-Host "$failures falha(s) em $checks checks" -ForegroundColor Red
    exit 1
}