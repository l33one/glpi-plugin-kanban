<#
.SYNOPSIS
    Testes E2E de visibilidade do plugin Kanban via web (login real) em GLPI 10 e/ou 11.

.DESCRIPTION
    Semeia usuÃ¡rios/grupos/gerentes/perfis e chamados (tests/e2e/seeder.php) e valida as
    regras de visibilidade do quadro:
      - Perfil sem o direito 'plugin_kanban' nÃ£o acessa o quadro (HTTP 403).
      - O usuÃ¡rio vÃª apenas chamados dos grupos aos quais pertence (subgrupos inclusos)
        ou nos quais estÃ¡ como tÃ©cnico (ASSIGN).
      - Membro comum NÃƒO vÃª chamado delegado individualmente a um colega do grupo.
      - Gerente do grupo vÃª todos os chamados do grupo, inclusive os delegados a outros
        membros (e a membros de subgrupos).
      - Observador do grupo nÃ£o torna o chamado visÃ­vel.
      - A restriÃ§Ã£o vale para todos os perfis, incluindo Super-Admin.

.PARAMETER Versions
    VersÃµes do GLPI a testar (10 e/ou 11).

.PARAMETER Cleanup
    Apenas remove o cenÃ¡rio semeado (nÃ£o executa asserÃ§Ãµes).

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
   
   # $env:TEMP nao existe no PowerShell do Linux (a CI roda em ubuntu-latest),
   # e Join-Path com $null aborta o script. GetTempPath() funciona nos dois.
   $tmpDir = [System.IO.Path]::GetTempPath()
   
   # $curl e o dispositivo nulo 'NUL' so existem no Windows. $IsWindows nao
   # existe no Windows PowerShell 5.1, que tambem roda estes scripts, entao a
   # deteccao usa $env:OS, presente nas duas plataformas.
   $isWindows   = $env:OS -eq 'Windows_NT'
   $curl        = if ($isWindows) { 'curl.exe' } else { 'curl' }
   $nullDevice  = if ($isWindows) { 'NUL' } else { '/dev/null' }
   
   $composeFiles = @{ 10 = 'docker-compose.glpi10.yml'; 11 = 'docker-compose.glpi11.yml' }
   $ports        = @{ 10 = 8090; 11 = 8091 }
$baseUrl      = @{ 10 = 'http://localhost:8090'; 11 = 'http://localhost:8091' }

# O seeder nunca cria contas com senha fixa: a senha vem do ambiente.
$WebPassword = $env:KANBAN_TEST_PASS
if ([string]::IsNullOrWhiteSpace($WebPassword)) {
    Write-Error "Defina KANBAN_TEST_PASS antes de rodar (ex.: `$env:KANBAN_TEST_PASS='<senha-de-teste>'). O seeder recusa criar contas sem senha explÃ­cita."
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
        '-e', "KANBAN_TEST_PASS=$WebPassword"
    )
    if ($Cleanup) {
        # Keep the credentials: the seeder refuses to run without them, cleanup
        # or not.
        $args += @('-e', 'KANBAN_CLEANUP=1')
    }
    $args += @('glpi', 'php', '/var/www/glpi/plugins/kanban/tests/e2e/seeder.php')
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
    # EspaÃ§a os logins para nÃ£o disparar o throttle anti-brute-force do GLPI.
    Start-Sleep -Milliseconds 900
    $base = $baseUrl[$Version]
    $jar = Join-Path $tmpDir ("kb_e2e_$Version.cookies.txt")
    Remove-Item $jar -ErrorAction SilentlyContinue

    $loginPage = & $curl -s -c $jar "$base/"
    $f = Get-LoginFields -Html $loginPage
    if (-not $f[0] -or -not $f[1]) {
        Write-Host "  (login fields nao encontrados na pagina do GLPI $Version)" -ForegroundColor Yellow
        return @{ 'blocked' = $true; 'titles' = @() }
    }

    $data = "$($f[0])=$Login&$($f[1])=$Password"
    if ($f[3]) { $data += "&_glpi_csrf_token=$($f[3])" }
    $loginStatus = & $curl -s -o $nullDevice -b $jar -c $jar -d $data -w '%{http_code}' "$base$($f[2])"

    $endpoint = "$base/plugins/kanban/front/api.php"
    $raw = & $curl -s -b $jar -w "`n%{http_code}" ($endpoint + '?action=get_tickets')
    $code = ($raw -split "`n")[-1]
    $body = ($raw -split "`n")[0..($raw.Length - 2)] -join "`n"
    $body = $body.TrimStart()

    # A API nega com 403 e corpo JSON {success:false}: "nÃ£o Ã© JSON" sÃ³ detecta
    # a pÃ¡gina de erro do GLPI, nÃ£o a recusa da API.
    if ($code -eq '403') {
        return @{ 'blocked' = $true; 'titles' = @(); 'code' = $code }
    }
    if (-not $body.StartsWith('{') -and -not $body.StartsWith('[')) {
        return @{ 'blocked' = $true; 'titles' = @(); 'code' = $code }
    }
    $json = $body | ConvertFrom-Json
    if ($json -and $json.success -eq $false) {
        return @{ 'blocked' = $true; 'titles' = @(); 'code' = $code }
    }
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
    # Mesmo login do Get-BoardTitles, porÃ©m na aÃ§Ã£o que alimenta os dropdowns.
    Start-Sleep -Milliseconds 900
    $base = $baseUrl[$Version]
    $jar = Join-Path $tmpDir ("kb_e2e_fd_$Version.cookies.txt")
    Remove-Item $jar -ErrorAction SilentlyContinue

    $loginPage = & $curl -s -c $jar "$base/"
    $f = Get-LoginFields -Html $loginPage
    if (-not $f[0] -or -not $f[1]) { return @{ 'blocked' = $true; 'requesters' = @() } }

    $data = "$($f[0])=$Login&$($f[1])=$Password"
    if ($f[3]) { $data += "&_glpi_csrf_token=$($f[3])" }
    & $curl -s -o $nullDevice -b $jar -c $jar -d $data "$base$($f[2])" | Out-Null

    $raw = & $curl -s -b $jar ($base + '/plugins/kanban/front/api.php?action=get_filter_data')
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

    Write-Host '-- Regras de visibilidade por usuÃ¡rio --'
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
            Check (Contains $seen $want) "$user VÃŠ '$want'"
        }
        foreach ($absent in $expectedAbsent[$user]) {
            Check (-not (Contains $seen $absent)) "$user NÃƒO vÃª '$absent'"
        }
    }

    Write-Host '-- Regra: restriÃ§Ã£o vale para todos os perfis (incl. Super-Admin) --'
    $admin = Get-BoardTitles -Version $v -Login 'glpi' -Password 'glpi'
    $kbSeen = $admin.titles | Where-Object { $_ -like 'KB-E2E*' }
    Check (($admin.blocked) -eq $false) 'Super-Admin acessa o quadro'
    Check ($kbSeen.Count -eq 0) 'Super-Admin nÃ£o vÃª chamados do cenÃ¡rio KB-E2E (sem participaÃ§Ã£o)'

    Write-Host '-- Regra: gerente do grupo vÃª delegaÃ§Ãµes a outros usuÃ¡rios --'
    $mgr = Get-BoardTitles -Version $v -Login 'kb_manager'
    Check (Contains $mgr.titles 'KB-E2E delegado-membro') 'kb_manager vÃª chamado delegado a kb_colleague (membro do grupo)'
    $mem = Get-BoardTitles -Version $v -Login 'kb_member'
    Check (-not (Contains $mem.titles 'KB-E2E delegado-membro')) 'kb_member (nÃ£o gerente) NÃƒO vÃª chamado delegado ao colega'

    Write-Host '-- Regra: filtro de solicitantes sÃ³ revela requisitantes de chamados visÃ­veis --'
    # Cada chamado do cenÃ¡rio tem um solicitante distinto:
    #   grupo-pai=kb_member, delegado-membro=kb_colleague, subgrupo=kb_child,
    #   fora=kb_noright, outro-grupo=kb_extuser, observador=kb_outsider,
    #   estrangeiro=kb_noright
    # Os nomes sÃ£o comparados por substring porque getUserName() monta o nome
    # completo (primeiro nome + sobrenome) conforme a configuraÃ§Ã£o da instÃ¢ncia.
    $req = @{}
    foreach ($user in @('kb_manager', 'kb_member', 'kb_child', 'kb_outsider')) {
        $req[$user] = @((Get-FilterData -Version $v -Login $user).requesters)
    }
    function ContainsLike($list, $item) {
        return @($list | Where-Object { $_ -like "*$item*" }).Count -gt 0
    }
    Check (ContainsLike $req['kb_manager'] 'KB kb_member') 'kb_manager VÃŠ solicitante do chamado grupo-pai (visÃ­vel)'
    Check (ContainsLike $req['kb_manager'] 'KB kb_colleague') 'kb_manager VÃŠ solicitante do chamado delegado (visÃ­vel como gerente)'
    Check (ContainsLike $req['kb_manager'] 'KB kb_child') 'kb_manager VÃŠ solicitante do chamado do subgrupo (visÃ­vel)'
    Check (-not (ContainsLike $req['kb_manager'] 'KB kb_extuser')) 'kb_manager NÃƒO vÃª solicitante do chamado de outro grupo (invisÃ­vel)'
    Check (-not (ContainsLike $req['kb_manager'] 'KB kb_noright')) 'kb_manager NÃƒO vÃª solicitante dos chamados fora do seu grupo (invisÃ­vel)'
    Check (-not (ContainsLike $req['kb_member'] 'KB kb_colleague')) 'kb_member NÃƒO vÃª solicitante do chamado delegado a colega (invisÃ­vel)'
    Check (ContainsLike $req['kb_member'] 'KB kb_child') 'kb_member VÃŠ solicitante do chamado do subgrupo (visÃ­vel)'
    Check (-not (ContainsLike $req['kb_member'] 'KB kb_noright')) 'kb_member NÃƒO vÃª solicitante do chamado fora (invisÃ­vel)'
    Check (ContainsLike $req['kb_outsider'] 'KB kb_noright') 'kb_outsider VÃŠ o solicitante do prÃ³prio chamado'
    Check ($req['kb_outsider'].Count -eq 1) "kb_outsider vÃª SOMENTE 1 solicitante (obtido: $($req['kb_outsider'].Count))"
}

Write-Host "`n===== RESUMO ====="
if ($failures -eq 0) {
    Write-Host "TODOS OS $checks CHECKS PASSARAM" -ForegroundColor Green
} else {
    Write-Host "$failures falha(s) em $checks checks" -ForegroundColor Red
    exit 1
}