<#
.SYNOPSIS
    Testes E2E do filtro de técnico do plugin Kanban.

.DESCRIPTION
    Verifica que o filtro de técnico exibe apenas os técnicos pertencentes aos
    grupos do usuário logado, em ordem alfabética pelo nome exibido.

    Cenário semeado:
      Grupo 9751 'KB Pai' (+ subgrupo 9752)  -> kb_ana, kb_bruno, kb_carlos, kb_colleague
      Grupo 9753 'KB Outro'                  -> kb_extuser, kb_zebra
      Sem grupo                              -> kb_outsider

    Expectativas:
      - kb_member (grupo 9751) vê apenas Alves Ana, Braga Bruno, Costa Carlos e
        kb_colleague (técnicos do grupo 9751), em ordem alfabética.
      - kb_extuser (grupo 9753) vê apenas Zink Zeca (e ele mesmo) - nunca os do 9751.
      - kb_outsider (sem grupo) não vê técnico algum.

.PARAMETER Versions
    Versões do GLPI a testar (10 e/ou 11).

.PARAMETER Cleanup
    Apenas remove os usuários/tickets do cenário KB TECHFILTER.
#>
param(
    [int[]]$Versions = @(10, 11),
    [switch]$Cleanup
)

   $ErrorActionPreference = 'Stop'
   
   # $env:TEMP nao existe no PowerShell do Linux (a CI roda em ubuntu-latest),
   # e Join-Path com $null aborta o script. GetTempPath() funciona nos dois.
   $tmpDir = [System.IO.Path]::GetTempPath()
   
   $composeFiles = @{ 10 = 'docker-compose.glpi10.yml'; 11 = 'docker-compose.glpi11.yml' }
$baseUrl      = @{ 10 = 'http://localhost:8090'; 11 = 'http://localhost:8091' }
$WebPassword  = $env:KANBAN_TEST_PASS
if ([string]::IsNullOrWhiteSpace($WebPassword)) {
    Write-Error "Defina KANBAN_TEST_PASS antes de rodar (ex.: `$env:KANBAN_TEST_PASS='<senha-de-teste>'). O seeder recusa criar contas sem senha explícita."
    exit 1
}

foreach ($v in $Versions) {
    if (-not $composeFiles.ContainsKey($v)) { continue }
    # As funções abaixo leem $Version do escopo de quem chama.
    $Version = $v

if ($Cleanup) {
    & docker compose -f $composeFiles[$Version] exec -T db mariadb -uglpi -pglpi_password glpi -e @"
SET @marker = 'KB TECHFILTER';
DELETE tu FROM glpi_tickets_users tu JOIN glpi_tickets t ON t.id = tu.tickets_id WHERE t.content LIKE '%KB TECHFILTER%';
DELETE FROM glpi_tickets WHERE content LIKE '%KB TECHFILTER%';
DELETE FROM glpi_groups_users WHERE users_id IN (SELECT id FROM glpi_users WHERE name IN ('kb_ana','kb_bruno','kb_carlos','kb_zebra'));
DELETE FROM glpi_profiles_users WHERE users_id IN (SELECT id FROM glpi_users WHERE name IN ('kb_ana','kb_bruno','kb_carlos','kb_zebra'));
DELETE FROM glpi_users WHERE name IN ('kb_ana','kb_bruno','kb_carlos','kb_zebra');
"@
    if ($LASTEXITCODE -eq 0) { Write-Host "Cenario KB TECHFILTER removido (GLPI $Version)." }
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    continue
}

# Garante o cenario base (kb_* , grupos 9751/9753, perfil 9701) via seeder oficial.
& docker compose -f $composeFiles[$Version] exec -T `
    -e KANBAN_DB_HOST=db -e KANBAN_DB_USER=glpi -e KANBAN_DB_PASS=glpi_password -e KANBAN_DB_NAME=glpi `
    -e KANBAN_TEST_PASS=$WebPassword `
    glpi php /var/www/glpi/plugins/kanban/tests/e2e/seeder.php
if ($LASTEXITCODE -ne 0) { throw "Seeder base falhou (exit $LASTEXITCODE)" }

# Garante o cenario (usuarios extra + tickets) de forma idempotente.
& docker compose -f $composeFiles[$Version] exec -T db mariadb -uglpi -pglpi_password glpi -e @"
SET @marker = 'KB TECHFILTER';
DELETE tu FROM glpi_tickets_users tu JOIN glpi_tickets t ON t.id = tu.tickets_id WHERE t.content LIKE '%KB TECHFILTER%';
DELETE FROM glpi_tickets WHERE content LIKE '%KB TECHFILTER%';
DELETE FROM glpi_groups_users WHERE users_id IN (SELECT id FROM glpi_users WHERE name IN ('kb_ana','kb_bruno','kb_carlos','kb_zebra'));
DELETE FROM glpi_profiles_users WHERE users_id IN (SELECT id FROM glpi_users WHERE name IN ('kb_ana','kb_bruno','kb_carlos','kb_zebra'));
DELETE FROM glpi_users WHERE name IN ('kb_ana','kb_bruno','kb_carlos','kb_zebra');
SET @hash = (SELECT password FROM glpi_users WHERE name='kb_colleague' LIMIT 1);
INSERT INTO glpi_users (name, password, authtype, auths_id, entities_id, is_active, is_deleted, realname, firstname) VALUES
 ('kb_ana', @hash, 1, 0, 0, 1, 0, 'Alves', 'Ana'),
 ('kb_bruno', @hash, 1, 0, 0, 1, 0, 'Braga', 'Bruno'),
 ('kb_carlos', @hash, 1, 0, 0, 1, 0, 'Costa', 'Carlos'),
 ('kb_zebra', @hash, 1, 0, 0, 1, 0, 'Zink', 'Zeca');
INSERT INTO glpi_groups_users (users_id, groups_id, is_dynamic, is_manager, is_userdelegate)
 SELECT id, 9751, 0, 0, 0 FROM glpi_users WHERE name IN ('kb_ana','kb_bruno','kb_carlos');
INSERT INTO glpi_groups_users (users_id, groups_id, is_dynamic, is_manager, is_userdelegate)
 SELECT id, 9753, 0, 0, 0 FROM glpi_users WHERE name='kb_zebra';
INSERT INTO glpi_profiles_users (users_id, profiles_id, entities_id, is_recursive, is_dynamic, is_default_profile)
 SELECT id, 9701, 0, 1, 0, 1 FROM glpi_users WHERE name IN ('kb_ana','kb_bruno','kb_carlos','kb_zebra');
INSERT INTO glpi_tickets (name, content, status, priority, urgency, impact, type, date, date_creation, date_mod, entities_id, is_deleted, itilcategories_id, requesttypes_id, users_id_lastupdater, users_id_recipient, actiontime)
 SELECT CONCAT('KB-TECH ', U.name), @marker, 1, 3, 2, 2, 1, NOW(), NOW(), NOW(), 0, 0, 0, 0, 2, 2, 0 FROM glpi_users U WHERE U.name IN ('kb_ana','kb_bruno','kb_carlos','kb_zebra');
INSERT INTO glpi_tickets_users (tickets_id, users_id, type)
 SELECT T.id, U.id, 2 FROM glpi_tickets T JOIN glpi_users U ON U.name IN ('kb_ana','kb_bruno','kb_carlos','kb_zebra') WHERE T.content LIKE '%KB TECHFILTER%' AND T.name = CONCAT('KB-TECH ', U.name);
"@
if ($LASTEXITCODE -ne 0) { throw "Seeder do cenario KB TECHFILTER falhou (exit $LASTEXITCODE)" }

$failures = 0
$checks = 0

function Check([bool]$cond, [string]$msg) {
    $script:checks++
    if ($cond) { Write-Host "  PASS :: $msg" }
    else { $script:failures++; Write-Host "  FAIL :: $msg" -ForegroundColor Red }
}

function Get-FilterData {
    param([string]$Login)
    Start-Sleep -Milliseconds 900
    $base = $baseUrl[$Version]
    $jar = Join-Path $tmpDir "kb_tf_$Version.cookies.txt"
    Remove-Item $jar -ErrorAction SilentlyContinue

    $loginPage = (& curl.exe -s -c $jar "$base/") -join "`n"
    $user = $null; $pass = $null; $action = '/'; $csrf = ''
    if ($loginPage -match '<input[^>]*type="text"[^>]*name="([^"]+)"') { $user = $Matches[1] }
    if ($loginPage -match '<input[^>]*type="password"[^>]*name="([^"]+)"') { $pass = $Matches[1] }
    if ($loginPage -match '<form[^>]*action="([^"]+)"') { $action = $Matches[1] }
    if ($loginPage -match '<input[^>]*type="hidden"[^>]*name="_glpi_csrf_token"[^>]*value="([^"]+)"') { $csrf = $Matches[1] }

    $data = "$user=$Login&$pass=$WebPassword"
    if ($csrf) { $data += "&_glpi_csrf_token=$csrf" }
    & curl.exe -s -o NUL -b $jar -c $jar -d $data "$base$action"

    $raw = (& curl.exe -s -b $jar ($base + '/plugins/kanban/front/api.php?action=get_filter_data')) -join "`n"
    if (-not $raw) { return @() }
    $json = $raw | ConvertFrom-Json
    $techs = @()
    foreach ($t in $json.technicians) { $techs += [pscustomobject]@{ id = $t.id; name = $t.name } }
    return $techs
}

Write-Host "`n===== Filtro de tecnico - GLPI $Version ====="
Write-Host '-- kb_member (grupo 9751 + subgrupo 9752) --'
$m = Get-FilterData -Login 'kb_member'
$mNames = @($m | ForEach-Object { $_.name })
foreach ($expected in @('Alves Ana', 'Braga Bruno', 'Costa Carlos', 'KB kb_colleague kb_colleague')) {
    Check ($mNames -contains $expected) "kb_member VE tecnico '$expected'"
}
foreach ($absent in @('Zink Zeca', 'KB kb_extuser kb_extuser', 'KB kb_outsider kb_outsider')) {
    Check (-not ($mNames -contains $absent)) "kb_member NAO VE tecnico '$absent'"
}
$expectedOrder = @('Alves Ana', 'Braga Bruno', 'Costa Carlos', 'KB kb_colleague kb_colleague')
$actualOrder = @($mNames | Where-Object { $expectedOrder -contains $_ })
Check (($actualOrder -join '|') -eq ($expectedOrder -join '|')) "Ordem alfabetica: $($actualOrder -join ', ')"

Write-Host '-- kb_extuser (grupo 9753) --'
$e = Get-FilterData -Login 'kb_extuser'
$eNames = @($e | ForEach-Object { $_.name })
Check (($eNames -contains 'Zink Zeca')) "kb_extuser VE tecnico 'Zink Zeca'"
Check (-not ($eNames -contains 'Alves Ana')) "kb_extuser NAO VE tecnico do grupo 9751"

Write-Host '-- kb_outsider (sem grupo) --'
$o = Get-FilterData -Login 'kb_outsider'
Check ($o.Count -eq 0) "kb_outsider (sem grupo) nao ve nenhum tecnico"

Write-Host "`n===== RESUMO ====="
if ($failures -eq 0) { Write-Host "TODOS OS $checks CHECKS PASSARAM" -ForegroundColor Green }
else { Write-Host "$failures falha(s) em $checks checks" -ForegroundColor Red; exit 1 }
}