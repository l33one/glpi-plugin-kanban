# Plano de melhorias — GLPI Kanban 1.3.0

Estado do trabalho em uma sessão, para retomar depois. Arquivo de desenvolvimento:
não entra no pacote de release (ver `.gitattributes`).

Plugin: `plugins/kanban` · Versão alvo: **1.3.0** (partindo de 1.2.2) ·
Compatibilidade: GLPI 10 e 11.

---

## 1. Objetivo

Seis melhorias no quadro kanban, na ordem de prioridade:

1. Paginação por coluna com total real de chamados (o contador mentia)
2. Desfazer o último movimento de um card
3. Separação da API em controller próprio
4. Limite de trabalho em progresso (WIP) por coluna
5. Visões salvas por usuário (filtros persistidos no servidor)
6. CI automatizada para GLPI 10 e 11

---

## 2. Decisões de projeto que valem lembrar

**Contagens de WIP e totais ignoram os filtros ativos.** O limite vale para a fila,
não para a visão atual. O mesmo número aparece no cabeçalho da coluna e no
cálculo do bloqueio, para que não haja duas verdades na tela.

**Métricas parciais são declaradas, não escondidas.** SLA, atribuídos a mim e sem
responsável são calculados sobre os cards carregados. Quando a coluna foi
truncada, a resposta traz `partial: true` e a interface marca o dado como parcial.

**Undo restaura apenas o status.** Motivo de pendência e solução registrados pelo
movimento continuam no histórico do chamado — desfazer não é editar o passado.

**Visões salvas exigem o mesmo direito que abre o quadro.** Um preset guarda apenas
os filtros que o usuário já pode digitar no formulário, então gerenciá-lo não é
privilégio. Compartilhar uma visão com todos continua exigindo `config` UPDATE.

**GLPI não tem `plugin_<nome>_update()`.** Conferido em `Plugin::install()`: quando
a versão de `setup.php` adianta, o plugin entra em `NOTUPDATED` e o botão *Upgrade*
faz `POST action=install`, ou seja, `plugin_kanban_install()` roda de novo. A
migration já é idempotente, então subir de 1.2.2 cria a tabela de presets sem
código extra. Não adicionar hook de update.

---

## 3. Situação por item

### 3.1 Paginação com total real — concluído

O quadro respondia `{status => [cards]}` e o navegador contava o que recebia, então
uma coluna com 200 chamados virava "200" e o total do quadro mudava conforme o
limite de exibição.

- `getBoardData()` consulta cada coluna sozinha (um COUNT + uma página)
- `countBoardTickets()` usa `COUNT(DISTINCT t.id)`
- resposta passa a ter `totals` e `has_more` por coluna
- botão **Carregar mais** por coluna, com offset
- rodapé mostra "Showing N of M"
- `getTicketsForKanban()` continua existindo como wrapper (compatibilidade)

### 3.2 Desfazer movimento — concluído

- `undoStatusChange()` reaplica as mesmas travas de transição e de WIP do movimento original
- `undo_ticket_status` na API
- notificação com ação de desfazer, tempo maior na tela quando há ação
- toggle `enable_undo` na configuração

### 3.3 Separação da API — concluído

- `inc/api.class.php` (`PluginKanbanApi`), uma ação por método privado
- `front/api.php` só despacha
- `front/kanban.php` virou renderizador de página
- `templates/kanban.html.twig` removido
- `WRITE_ACTIONS` garante POST nas ações que alteram dados
- erro de exceção vai para o log, mensagem genérica ao navegador (o texto pode carregar SQL)

> O monólito PHP foi separado; o toast continua inline em `public/js/kanban.js`.
> Extrair `kanban-toast.js` era ideia inicial, não requisito.

### 3.4 WIP por coluna — concluído

- `checkWipLimit()` em `inc/kanban.class.php`, chamado no servidor por
  `updateTicketStatus()` e `undoStatusChange()` — a validação do JS é só conforto
- `countVisibleTicketsInStatus()` para a contagem
- `get_wip_state` devolve o estado de todas as colunas para o destaque
- config: `wip_limit` (0 = desliga) + `wip_block_exceed` (bloqueia ou só avisa)
- quando bloqueia, a coluna recusa e o movimento reverte com o motivo

### 3.5 Visões salvas — concluído

- `inc/filterpreset.class.php` (`PluginKanbanFilterPreset extends CommonDBTM`)
- tabela `glpi_kanban_filter_presets`, declarada em `use_tables` e criada na migration
- campos: `search`, `technician`, `requester`, `group`, `type`, `category`, `assigned_to_me`
- API: `get_presets`, `save_preset`, `rename_preset`, `delete_preset`
- UI: salvar o filtro atual, aplicar, listar, apagar

### 3.6 CI GLPI 10 + 11 — concluído (rodou)

`.github/workflows/ci.yml`: lint PHP e JS, `kanban.xml` bem formado, versão
consistente entre XML e código, strings presentes nos catálogos, build do pacote
e E2E nas matrizes 10 e 11.

---

## 4. Correções feitas durante a execução

| Onde | Problema | Correção |
|---|---|---|
| `inc/filterpreset.class.php` | `saveForUser()` exigia `plugin_kanban CREATE` e `isEditableBy()` exigia `UPDATE`, mas a migration só concede `READ` — todo perfil não-super-admin abria o quadro, via o botão de salvar e recebia "Permission denied" | próprio view passa a usar o mesmo `READ` que abre o quadro; compartilhar/editar view de terceiro continua com `config` UPDATE |
| `inc/api.class.php` | `limit` vinha cru da query string | limitado a 1–200, o mesmo intervalo do formulário de configuração |
| `public/js/kanban.js` | toast montava a mensagem com `innerHTML`, e ela carrega texto do servidor (motivo de movimento recusado) que pode conter texto digitado pelo usuário | `textContent` |
| `public/js/kanban.js` | 4 interpolações de `lang.*` sem escape em atributos HTML | escapadas |
| `tools/check-locales.php` | só reconhecia `__("...", 'kanban')`; o plural `_n()` passava despercebido | coleta os dois msgids do `_n()` |
| `tools/check-locales.php` | assumia um `msgid` por linha; quebrava com msgid longo quebrado em várias linhas | parser por bloco, junta continuação e trata `msgid_plural` |
| `locales/*.po` | plural "Saved board view" estava só singular; `polib` recusa `msgstr` singular em entrada plural | bloco plural escrito como texto (o `save()` do polib re-quebraria as 181 entradas) |
| `hook.php` | plano anterior previa um `plugin_kanban_update()` que o GLPI nunca chama | hook removido; o fato está documentado no docblock do install |
| `hook.php` | **`Migration::createTable()` não existe no GLPI 10 nem no 11** (só `addField()`, que emite `ALTER TABLE` sobre uma tabela que precisa já existir). `glpi:plugin:install kanban` morria com *Call to undefined method* — a instalação do plugin estava quebrada | DDL próprio enfileirado em `addPreQuery()`, guardado por `tableExists()`; chaves embutidas no CREATE, com `addKey()` no ramo `else` só para reparar tabela preexistente |
| `inc/filterpreset.class.php` | `FKEY` do `LEFT JOIN` estava no formato `['coluna' => 'coluna']`; o GLPI quer `[tabela => coluna, tabela => coluna]`. Toda chamada de `get_presets` respondia 500 (*BAD FOREIGN KEY*) — o recurso de visões salvas nunca funcionou | `FKEY` reescrito no formato do core |
| `inc/filterpreset.class.php` | docblock do schema dizia que `users_id = 0` esconde a view da lista, mas a query não filtrava: uma view órfã aparecia para todos e só o admin podia apagar | `NOT users_id = 0` na `WHERE`, como o comentário prometia |
| `inc/api.class.php` | `intParam()` é `?int` (para distinguir "sem filtro" de "filtro 0") e alimentava parâmetros `int` não-nulos em 10 ações. `get_tickets` sem `status` e qualquer escrita sem `ticket_id` respondiam **500** com `TypeError` | `requireIntParam()`: parâmetro ausente é 400, não 500 |
| `inc/api.class.php` | `save_preset` sem nome devolvia pelo caminho curto **sem** `csrf_token`. Como o token do GLPI é de uso único, o POST seguinte morria no CSRF e o botão de salvar views quebrava a sessão | caminho curto também emite token novo |
| `inc/api.class.php` | `fail()` em POST não devolvia token, então qualquer erro (400/403/404) deixava o navegador com um token morto e as escritas seguintes falhavam até recarregar | `fail()` devolve token novo quando o request é POST |
| `front/kanban.php` | `"Showing %1$d of %2$d"` e `"limit of %d tickets"` em aspas duplas: o PHP lê `$d` como variável e warns 7× por carga de página | strings de `sprintf` em aspas simples |
| `tests/bootstrap.php` | `compact('GLPI_CRON_DIR', ...)` só resolve **variáveis**, então o laço nunca rodou e os 7 diretórios de teste nunca foram criados (7 warnings por execução) | lista de constantes explícita |
| `tests/PluginKanbanKanbanTest.php` | `testUndoStatusChangeRestoresThePreviousStatus` não atribuía o chamado: `isTicketVisible()` exige participação, então o undo respondia "Permission denied" — o teste cobria um caminho impossível | atribui user 2 como `ASSIGN`, como os outros testes de visibilidade |
| `tests/PluginKanbanKanbanTest.php` | `assertSame([], ...)` num filtro sem match era o contrato antigo; com a paginação o quadro sempre devolve as colunas configuradas | passa a exigir todas as colunas vazias, e não um array vazio |
| `tests/e2e/test-visibility.ps1`, `test-technician-filter.ps1` | **encoding corrompido** por uma edição anterior: acentos viraram `usuÃ¡rios`/`NÃƒO` e os arquivos ganharam BOM | recuperados do blob do git e reaplicado só a troca de endpoint |
| `tests/e2e/test-technician-filter.ps1` | aceitava `-Version` (singular); a CI chama `-Versions`, então o job quebraria | `[int[]]$Versions` com loop, igual aos outros dois scripts |
| `tests/e2e/test-visibility.ps1` | tratava "bloqueado" só como resposta não-JSON, mas a API recusa com 403 **e** JSON | 403 e `success: false` contam como bloqueado |
| `tests/e2e/test-board-features.ps1` | check de `delete_preset` não mostrava o erro, então a falha só dizia "apaga a view" | erro e HTTP status na mensagem; novos checks para parâmetro obrigatório ausente e para o `csrf_token` da resposta de erro |
| `tests/e2e/test-visibility.ps1` | no caminho `-Cleanup` os argumentos do seeder eram substituídos e perdiam `KANBAN_DB_HOST/USER/PASS/NAME`. O seeder aborta sem eles, então **a limpeza nunca rodou** — e é justamente o passo que a CI executa por último (`ci.yml`), que quebraria no fim do job | as credenciais são mantidas e só `KANBAN_CLEANUP=1` é acrescentado |
| `tools/check-locales.php` | os regex são ancorados em `$`, então com CRLF (o `core.autocrlf=true` do Windows entrega assim) nenhum msgid casava: **0 msgids** lidos e as 180 strings acusadas como faltando, com exit 1 | normaliza `\r\n`/`\r` antes do parse; `locales/*.po` também passa a ser `text eol=lf` no `.gitattributes` |
| `tools/check-locales.php` | só comparava o conjunto de msgids entre `.po` e `.mo`. Editar uma tradução sem recompilar passava despercebido, que é o caso de drift mais comum | compara campo a campo (`msgid => msgstr`), lendo o `.mo` binário em PHP: acusa *not compiled*, *outdated* e *obsolete*, sem depender de gettext instalado |
| `locales/pt_BR.po`, `locales/en_GB.po` | o bloco plural de "Saved board view" estava com `msgstr[0]` e `msgstr[1]` **trocados**. Com `plural=(n != 1)`, `[0]` é o singular, então o quadro mostrava "Visões salvas do quadro" para 1 visão salva e "Visão salva do quadro" para várias | formas na ordem correta, nos dois catálogos |
| `locales/pt_BR.po` | "Cards loaded per column" estava traduzido como "**Cards** carregados por coluna"; as outras 12 strings novas mantinham o empréstimo "cards" | "Cartão/cartões" nas 13 strings, conferindo o resultado ao ler o `.mo` compilado |
| `.gitattributes` | a regra era `/PLAN-1.3.0.md`, específica de um arquivo: um `PLAN-2.0.0.md` futuro entraria no pacote, e só o guard do build o pegaria | `/PLAN-*.md` |
| `tools/build-release.sh` | o guard não cobria `.git/` nem `dist/`, que a CI já cobria — rodando o script fora da CI, `.github/workflows/ci.yml` entraria no pacote | regex alinhada com a da CI |
| `.github/workflows/ci.yml` | o probe de readiness exigia HTTP 200 **sem** seguir redirecionamento; uma raiz que responda 302 (install/login) seguraria o probe por 100×3 s e derrubaria o job inteiro | `curl -sL` |
| `.github/workflows/ci.yml` | baixava `phpunit-9.phar` (versão corrente) a cada execução, sem verificação | versão pinada em 9.6.35 com `sha256sum -c`, a mesma build que rodou a suíte |

---

## 5. Verificações

Rodadas com sucesso:

```
php -l                 → ok em todos os *.php (exceto vendor/)
node --check           → ok em public/js/kanban.js
php tools/check-locales.php → 180 strings, 2 catálogos,
                             OK (182 msgids) e OK (181 entries, in sync) em .po e .mo, exit 0

GLPI 10 (docker-compose.glpi10.yml, glpi/glpi:10.0.27, PHP 8.5.9)
  PHPUnit 9.6.35       → OK (65 testes, 424 asserções)
  E2E test-visibility        → 40/40
  E2E test-technician-filter → 11/11
  E2E test-board-features    → 36/36

GLPI 11 (docker-compose.glpi11.yml, glpi/glpi:11, PHP 8.5.9)
  install --force (caminho de upgrade 1.2.2 → 1.3.0) → ok, tabela criada
  E2E test-visibility        → 40/40
  E2E test-technician-filter → 11/11
  E2E test-board-features    → 36/36
```

Os caminhos de falha do checker de locales também conferidos, e não só o de msgid
faltando:

```
msgid renomeado            → exit 1, lista o que falta
.po com CRLF               → exit 0 (normalização funciona nos dois finais de linha)
.po editado sem msgfmt     → exit 1, "13 outdated" nos msgstr que mudaram
.mo ausente                → exit 1, "run msgfmt to compile it"
```

As traduções compiladas também foram lidas do `.mo` (o binário, não o `.po`) para
confirmar os valores: `Cartões carregados por coluna`, e o `_n()` resolvendo
`Visão salva do quadro` em n=1 e `Visões salvas do quadro` em n=2 e n=5.

**PHPUnit não roda no GLPI 11, por desenho.** O `inc/includes.php` do GLPI 11 é um
shim de compatibilidade: ele não mais carrega autoloader nem banco, então o
bootstrap do plugin termina com `Class "User" not found` (16 erros, 48 pulados em
65 testes). Suportá-lo exigiria um segundo bootstrap, usando o `Kernel` do GLPI 11.
A CI já restringe o PHPUnit à matriz 10 (`if: matrix.glpi == 10`) e o GLPI 11 fica
coberto pelos E2E, que exercitam a mesma funcionalidade pela web.

---

## 6. Pendências

Tudo resolvido nesta rodada (`e2a6640`..`1516846`):

1. ~~Revisar o `git diff` completo~~ — 3803 linhas adicionadas em 27 arquivos,
   divididos por fronteira de arquivo: runtime, testes, tooling/CI e docs
2. ~~Commitar~~ — inclusive `.github/`, que estava untracked e impedia a CI
3. ~~Build do pacote~~ — `dist/glpi-plugin-kanban-1.3.0.tar.bz2`, 79.383 bytes,
   contendo só `front/`, `inc/`, `locales/`, `public/`, `hook.php`, `setup.php`,
   `kanban.xml`, `kanban.png`, `LICENSE` e `README.md` (21 arquivos). Nenhum
   `tests/`, `tools/`, `.github/`, `docker/`, `docker-compose*` ou `PLAN-*.md`
4. ~~Publicar o release 1.3.0~~ — `main` e a tag `1.3.0` no ar, release em
   <https://github.com/l33one/glpi-plugin-kanban/releases/tag/1.3.0>, com o
   `.tar.bz2` anexado. O asset publicado confere com o pacote local, SHA-256
   `f8b595afc6ee6f9fb721eb7a91f67499987003f287fec7837350d04162f83e23`. A tag
   aponta para `a034e58`, e os commits seguintes são só de CI e testes, sem tocar
   no runtime
5. ~~Decidir o PHPUnit no GLPI 11~~ — não: o GLPI 11 fica coberto pelos E2E, que
   sobem a stack de verdade e exercitam a funcionalidade pela web

### O que só apareceu na CI

A CI foi o que expôs que a suíte de PHPUnit nunca teve fixtures de verdade. Local
ela passava por dados que sobraram de execuções anteriores; na CI, que sobe com
volume novo, o quadro vinha vazio e ela falhava. Três defeitos distintos, todos no
armazenamento de estado e não no plugin:

- `kanban_plugin_create_test_tickets()` existia no bootstrap mas ninguém chamava,
  e ainda usava a assinatura do GLPI 9 (`$DB->insert($array)`);
- depois de chamada, ela lia o id do retorno de `insert()`, que no GLPI 10 é
  booleano — o id vem de `insertId()`. Todos os actors apontavam para o ticket 1,
  o que passa despercebido em base vazia (onde o primeiro ticket *é* o 1) e
  esvazia o quadro em qualquer base já usada;
- dois testes dependiam desse estado herdado: um criava tickets sem Responsible
  (o quadro só mostra chamados que o usuário responde) e outro afirmava testar uma
  coluna vazia com `assertNotNull`, quando `checkWipLimit()` devolve `null` para
  permitir — ou seja, validava o ramo oposto ao que a mensagem descreve, e só
  passava porque a coluna estava cheia.

Com isso a suíte passou de 235 para 413 asserções no mesmo banco: boa parte do
quadro estava sendo testada contra nada. A CI verde (`37048984781`) é o primeiro
run em que lint, package, E2E do GLPI 10 e E2E do GLPI 11 passam juntos.

### O pacote também foi testado como artifact

Não bastava listar o tar: ele foi extraído, montado no lugar do diretório do
plugin no container do GLPI 10 e os E2E rodaram contra os arquivos extraídos —
36/36 e 40/40. É o que pega um arquivo de runtime export-ignored por engano, já que
o E2E normal roda contra a árvore de trabalho. (O `tests/e2e/seeder.php` foi
injetado na extração temporária, porque `tests/` é justamente o que não entra no
pacote.)

### Bloqueios do ambiente

- `pwsh` e `composer` não instalados (os E2E rodam em Windows PowerShell 5.1, e
  não usam recursos exclusivos do 7)
- Sem checkout do core GLPI local: assinaturas, migrations e hooks não puderam
  ser validados contra o código-fonte local, só contra o core dos containers

---

## 7. Detalhes de implementação que importam

**Contrato da API `get_tickets`** — `{statuses, totals, has_more, limit, metrics}`.
Escritas respondem `{success, result, csrf_token}`; o token é de uso único no GLPI,
por isso toda resposta de POST devolve um novo.

**Ordenação global vs. por página** — `appendColumnTickets` ordena cada página antes
de concatenar. A ordem fica correta dentro de cada página; a ordenação global só é
garantida quando a ordenação do servidor coincide com a do cliente (data decrescente,
por exemplo). Vale revisar se alguém ordenar por outro critério.

**`canManageViews()`** — o nome evita confundir com `isEditableBy()`, que responde a
pergunta "esta view é minha?".

**`get_wip_state`** — consulta uma contagem por status em toda chamada. Em quadro com
muitas colunas e muitas chamadas isso pesa; se incomodar, cachear por requisição.

**Novo em `getBoardData`:** `$only_status` restringe a consulta a uma coluna, usado
pelo "Carregar mais". `computeMetrics($statuses, $totals)` recebe os totais e decide
`partial` comparando total com cards carregados.