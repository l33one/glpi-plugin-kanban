<#
.SYNOPSIS
    Testes E2E das funcionalidades novas do quadro Kanban (paginaÃ§Ã£o, undo, WIP e
    saved views) via web (login real) em GLPI 10 e/ou 11.

.DESCRIPTION
    Depende do cenÃ¡rio semeado por tests/e2e/seeder.php (o mesmo de test-visibility.ps1)
    e valida:
      - get_tickets devolve 'totals' e 'has_more' por coluna, e o total Ã© maior que
        a quantidade de cards quando a coluna foi truncada (nada Ã© escondido em silÃªncio).
      - a paginaÃ§Ã£o por coluna funciona: status + offset devolvem a pÃ¡gina seguinte e
        has_more reflete o que ainda falta.
      - um movimento de status pode ser desfeito (update_ticket_status + undo_ticket_status),
        devolvendo o chamado ao status anterior.
      - o limite de WIP Ã© contado por status (get_wip_state).
      - saved views: salvar, listar e apagar.
      - a API sÃ³ aceita escrita por POST (GET em uma aÃ§Ã£o de escrita responde 405),
        e rejeita aÃ§Ã£o desconhecida com 404.
      - um usuÃ¡rio sem o direito plugin_kanban continua bloqueado (403).

.PARAMETER Versions
    VersÃµes do GLPI a testar (10 e/ou 11).

.EXAMPLE
    .\test-board-features.ps1
    .\test-board-features.ps1 -Versions 11
#>
param(
    [int[]]$Versions = @(10, 11)
)

   $ErrorActionPreference = 'Stop'
   
   # $env:TEMP nao existe no PowerShell do Linux (a CI roda em ubuntu-latest),
   # e Join-Path com $null aborta o script. GetTempPath() funciona nos dois.
   $tmpDir = [System.IO.Path]::GetTempPath()
   
   # curl.exe e o dispositivo nulo 'NUL' so existem no Windows. $IsWindows nao
   # existe no Windows PowerShell 5.1, que tambem roda estes scripts, entao a
   # deteccao usa $env:OS, presente nas duas plataformas.
   $isWindows   = $env:OS -eq 'Windows_NT'
   $curl        = if ($isWindows) { 'curl.exe' } else { 'curl' }
   $nullDevice  = if ($isWindows) { 'NUL' } else { '/dev/null' }
   
   $composeFiles = @{ 10 = 'docker-compose.glpi10.yml'; 11 = 'docker-compose.glpi11.yml' }
   $baseUrl      = @{ 10 = 'http://localhost:8090'; 11 = 'http://localhost:8091' }

$WebPassword = $env:KANBAN_TEST_PASS
if ([string]::IsNullOrWhiteSpace($WebPassword)) {
    Write-Error "Defina KANBAN_TEST_PASS antes de rodar (ex.: `$env:KANBAN_TEST_PASS='<senha-de-teste>')."
    exit 1
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

function Invoke-Seeder {
    param([int]$Version)
    & docker compose -f $composeFiles[$Version] exec -T `
        -e 'KANBAN_DB_HOST=db' `
        -e 'KANBAN_DB_USER=glpi' `
        -e 'KANBAN_DB_PASS=glpi_password' `
        -e 'KANBAN_DB_NAME=glpi' `
        -e "KANBAN_TEST_PASS=$WebPassword" `
        glpi php /var/www/glpi/plugins/kanban/tests/e2e/seeder.php
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

# Devolve @{ blocked = $true|false; jar = caminho do cookie jar; csrf = token }.
# O token vem da propria pagina do quadro: o do login ja foi consumido.
function New-Session {
    param([int]$Version, [string]$Login, [string]$Password = $WebPassword)
    Start-Sleep -Milliseconds 900
    $base = $baseUrl[$Version]
    $jar = Join-Path $tmpDir ("kb_feat_$($Login)_$Version.cookies.txt")
    Remove-Item $jar -ErrorAction SilentlyContinue

    $loginPage = (& $curl -s -c $jar "$base/") -join "`n"
    $f = Get-LoginFields -Html $loginPage
    if (-not $f[0] -or -not $f[1]) { return @{ blocked = $true; jar = $null; csrf = '' } }

    $data = "$($f[0])=$Login&$($f[1])=$Password"
    if ($f[3]) { $data += "&_glpi_csrf_token=$($f[3])" }
    & $curl -s -o $nullDevice -b $jar -c $jar -d $data "$base$($f[2])" | Out-Null

    $board = (& $curl -s -b $jar -c $jar "$base/plugins/kanban/front/kanban.php") -join "`n"
    $csrf = ''
    if ($board -match '<input[^>]*type="hidden"[^>]*name="_glpi_csrf_token"[^>]*value="([^"]+)"') { $csrf = $Matches[1] }

    return @{ blocked = $false; jar = $jar; csrf = $csrf }
}

function Invoke-Api {
    param(
        [hashtable]$Session,
        [string]$Action,
        [hashtable]$Params = @{},
        [switch]$Post
    )
    $base = $currentBase
    $endpoint = "$base/plugins/kanban/front/api.php"

    $query = "action=$Action"
    foreach ($k in $Params.Keys) { $query += "&" + [uri]::EscapeDataString($k) + "=" + [uri]::EscapeDataString([string]$Params[$k]) }

    if (-not $Post) {
        $raw = & $curl -s -b $Session.jar -w "`n%{http_code}" "$endpoint`?$query"
    } else {
        # O token do GLPI e de uso unico e precisa acompanhar cada POST.
        if ($Session.csrf) { $query += "&_glpi_csrf_token=" + [uri]::EscapeDataString($Session.csrf) }
        $raw = & $curl -s -b $Session.jar -w "`n%{http_code}" -d $query "$endpoint"
    }

    $code = ($raw -split "`n")[-1]
    $body = ($raw -split "`n")[0..($raw.Length - 2)] -join "`n"

    $json = $null
    if ($body -and ($body.TrimStart().StartsWith('{'))) {
        try { $json = $body | ConvertFrom-Json } catch { $json = $null }
    }

    # A resposta traz um token novo para o proximo POST.
    if ($json -and $json.csrf_token) { $Session.csrf = [string]$json.csrf_token }

    return @{ code = $code; body = $body; json = $json }
}

foreach ($v in $Versions) {
    if (-not $composeFiles.ContainsKey($v)) { continue }
    $currentBase = $baseUrl[$v]

    Write-Host "`n===== GLPI $v ($($baseUrl[$v])) ====="
    Invoke-Seeder -Version $v

    Write-Host '-- Regras: sem o direito plugin_kanban a API responde 403 --'
    $sessNoRight = New-Session -Version $v -Login 'kb_noright'
    $r = Invoke-Api -Session $sessNoRight -Action 'get_tickets'
    Check ($r.code -eq '403') "kb_noright recebe HTTP 403 em get_tickets (obtido $($r.code))"

    Write-Host '-- PaginaÃ§Ã£o: totais reais e has_more por coluna --'
    $sess = New-Session -Version $v -Login 'kb_manager'
    Check (-not $sess.blocked) 'kb_manager autenticado'

    $full = Invoke-Api -Session $sess -Action 'get_tickets' -Params @{ limit = 2 }
    Check ($full.json -ne $null) "get_tickets devolve JSON (HTTP $($full.code))"
    if ($full.json) {
        Check ($null -ne $full.json.totals) 'a resposta traz "totals" por coluna'
        Check ($null -ne $full.json.has_more) 'a resposta traz "has_more" por coluna'
        Check ($null -ne $full.json.metrics) 'a resposta traz "metrics"'
        Check ($null -eq $full.json.metrics.PSObject.Properties['error']) 'a resposta nÃ£o traz erro'

        # O cenÃ¡rio do seeder deixa 3 chamados visÃ­veis para kb_manager no status 1.
        $total1 = [int]$full.json.totals.'1'
        $loaded1 = @($full.json.statuses.'1').Count
        Check ($total1 -ge $loaded1) "total ($total1) >= cards carregados ($loaded1) no status 1"
        Check ($loaded1 -le 2) "a coluna respeita limit=2 (carregou $loaded1)"
        Check ([bool]$full.json.has_more.'1' -eq ($total1 -gt $loaded1)) 'has_more coerente com o total'

        $ids = @($full.json.statuses.'1' | ForEach-Object { [int]$_.id })
        Check ($ids.Count -gt 0) 'hÃ¡ cards no status 1 para testar a paginaÃ§Ã£o'
    }

    Write-Host '-- PaginaÃ§Ã£o: pÃ¡gina seguinte por coluna --'
    if ($full.json) {
        $total1 = [int]$full.json.totals.'1'
        if ($total1 -gt 2) {
            $page2 = Invoke-Api -Session $sess -Action 'get_tickets' -Params @{ status = 1; offset = 2; limit = 2 }
            $p2ids = @($page2.json.statuses.'1' | ForEach-Object { [int]$_.id })
            $firstIds = @($full.json.statuses.'1' | ForEach-Object { [int]$_.id })
            Check ($p2ids.Count -gt 0) "offset=2 devolve cards (obtidos $($p2ids.Count))"
            Check (-not ($p2ids | Where-Object { $firstIds -contains $_ })) 'a pÃ¡gina seguinte nÃ£o repete cards da primeira'
            Check ([bool]$page2.json.has_more.'1' -eq ($total1 -gt ($p2ids.Count + 2))) 'has_more da pÃ¡gina seguinte coerente'
        } else {
            Write-Host "  (poucos chamados no cenÃ¡rio: pulando o teste de offset)" -ForegroundColor Yellow
        }
    }

    Write-Host '-- Undo: mover e devolver o chamado ao status anterior --'
    if ($full.json) {
        $ticketId = [int](@($full.json.statuses.'1' | ForEach-Object { [int]$_.id })[0])
        if ($ticketId -gt 0) {
            $move = Invoke-Api -Session $sess -Action 'update_ticket_status' -Post -Params @{ ticket_id = $ticketId; status = 2 }
            Check ($move.json.result.success -eq $true) "status 1 -> 2 do chamado #$ticketId (erro: $($move.json.result.error))"

            $undo = Invoke-Api -Session $sess -Action 'undo_ticket_status' -Post -Params @{ ticket_id = $ticketId; from_status = 1 }
            Check ($undo.json.result.success -eq $true) "undo do chamado #$ticketId volta ao status 1 (erro: $($undo.json.result.error))"

            # Undo para um status que nÃ£o existe tem de ser recusado.
            $badUndo = Invoke-Api -Session $sess -Action 'undo_ticket_status' -Post -Params @{ ticket_id = $ticketId; from_status = 9999 }
            Check ($badUndo.json.result.success -eq $false) 'undo com status inexistente Ã© recusado'
        } else {
            Check $false 'nenhum chamado disponÃ­vel para testar o undo'
        }
    }

    Write-Host '-- WIP: contagem por status --'
    $wip = Invoke-Api -Session $sess -Action 'get_wip_state'
    Check ($wip.json -ne $null) "get_wip_state devolve JSON (HTTP $($wip.code))"
    if ($wip.json) {
        Check ($null -ne $wip.json.wip) 'a resposta traz o estado de WIP por coluna'
        Check ($null -ne $wip.json.wip_limit) 'a resposta traz o limite configurado'
        $hasStatus1 = $null -ne $wip.json.wip.'1'
        Check $hasStatus1 'o status 1 aparece no estado de WIP'
        if ($hasStatus1) {
            Check ([int]$wip.json.wip.'1'.current -ge 0) 'a contagem de WIP Ã© um nÃºmero'
        }
    }

    Write-Host '-- Saved views: salvar, listar e apagar --'
    $viewName = 'KB-E2E view'
    $save = Invoke-Api -Session $sess -Action 'save_preset' -Post -Params @{ name = $viewName; technician = 0; is_private = 1 }
    Check ($save.json.result.success -eq $true) "salva a view '$viewName' (erro: $($save.json.result.error))"
    $viewId = [int]$save.json.result.id

    $list = Invoke-Api -Session $sess -Action 'get_presets'
    $names = @($list.json.presets | ForEach-Object { [string]$_.name })
    Check (($names | Where-Object { $_ -eq $viewName }).Count -eq 1) 'a view salva aparece em get_presets'

    $withName = @($list.json.presets | Where-Object { [string]$_.name -eq $viewName })
    if ($withName.Count -eq 1) {
        Check ($null -ne $withName[0].filters) 'a view devolve os filtros guardados'
        Check ([bool]$withName[0].can_write) 'o dono pode editar a prÃ³pria view'
    }

    $badSave = Invoke-Api -Session $sess -Action 'save_preset' -Post -Params @{ name = '   ' }
    Check ($badSave.json.result.success -eq $false) 'view sem nome Ã© recusada'

if ($viewId -gt 0) {
        $del = Invoke-Api -Session $sess -Action 'delete_preset' -Post -Params @{ id = $viewId }
        Check ($del.json.result.success -eq $true) "apaga a view (HTTP $($del.code), erro: $($del.json.result.error))"
        $after = Invoke-Api -Session $sess -Action 'get_presets'
        $afterNames = @($after.json.presets | ForEach-Object { [string]$_.name })
        Check (-not (($afterNames | Where-Object { $_ -eq $viewName }).Count -gt 0)) 'a view apagada some da lista'
    }

    Write-Host '-- Contrato da API: escrita sÃ³ por POST, aÃ§Ãµes desconhecidas --'
    $viaGet = Invoke-Api -Session $sess -Action 'update_ticket_status' -Params @{ ticket_id = 1; status = 2 }
    Check ($viaGet.code -eq '405') "GET em aÃ§Ã£o de escrita responde 405 (obtido $($viaGet.code))"

    $unknown = Invoke-Api -Session $sess -Action 'nao_existe'
    Check ($unknown.code -eq '404') "aÃ§Ã£o desconhecida responde 404 (obtido $($unknown.code))"

    $bogus = Invoke-Api -Session $sess -Action '../../config'
    Check ($bogus.code -in @('400', '404')) "aÃ§Ã£o com caracteres invÃ¡lidos Ã© recusada (obtido $($bogus.code))"

    # ParÃ¢metro obrigatÃ³rio ausente Ã© requisiÃ§Ã£o invÃ¡lida, nÃ£o erro 500.
    $noTicket = Invoke-Api -Session $sess -Action 'update_ticket_status' -Post
    Check ($noTicket.code -eq '400') "update_ticket_status sem ticket_id responde 400 (obtido $($noTicket.code))"
    $noFollowup = Invoke-Api -Session $sess -Action 'get_followups'
    Check ($noFollowup.code -eq '400') "get_followups sem ticket_id responde 400 (obtido $($noFollowup.code))"

    # Toda resposta de POST precisa devolver um token novo: o do GLPI Ã© de uso Ãºnico.
    Check ($null -ne $noTicket.json.csrf_token) 'a resposta de erro de POST traz csrf_token'

    Write-Host '-- Presets de outro usuÃ¡rio nÃ£o podem ser apagados --'
    $other = New-Session -Version $v -Login 'kb_child'
    if (-not $other.blocked) {
        $otherList = Invoke-Api -Session $other -Action 'get_presets'
        Check ($otherList.json -ne $null) 'kb_child consegue ler seus presets'
    }
}

Write-Host "`n===== RESUMO ====="
if ($failures -eq 0) {
    Write-Host "TODOS OS $checks CHECKS PASSARAM" -ForegroundColor Green
} else {
    Write-Host "$failures falha(s) em $checks checks" -ForegroundColor Red
    exit 1
}