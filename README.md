# 📋 GLPI Kanban Plugin

Um plugin para o **GLPI 10+** que transforma o gerenciamento de chamados adicionando uma visualização em formato **Kanban** altamente interativa.

Este plugin permite que técnicos e gestores acompanhem o fluxo de trabalho de forma visual, organizando os chamados (tickets) em colunas baseadas em seus status nativos do GLPI.

---

## ✨ Funcionalidades

* **Visualização Ágil:** Colunas dinâmicas mapeadas diretamente para os status dos chamados (Novo, Em Atendimento, Pendente, Solucionado, Fechado, etc.)
* **Filtros Avançados em Tempo Real:**
  * Filtragem por Grupo (inclui subgrupos)
  * Filtragem por Técnico Atribuído
  * Filtragem por Requerente
  * Filtragem por Tipo de Chamado (Incidente / Solicitação)
  * Filtragem por Categoria (inclui subcategorias)
  * Pesquisa por Número do Chamado (campo que filtra os cards pelo número/ID)
* **Ordenação Inteligente:** Cards ordenados por Prioridade, Data de Abertura, SLA ou Tempo no Status Atual
* **Cards Ricos em Informações:**
  * ID e Título do chamado
  * **Barra de SLA:** Indicador visual de progresso (verde/amarelo/vermelho) com contagem regressiva
  * Data de abertura, Técnico atribuído, Prioridade e Categoria
* **Drag and Drop:** Mover cards entre colunas para alterar o status, com rollback otimista em caso de falha
* **Desfazer movimento:** Notificação com ação para devolver o card à coluna anterior. O undo restaura **apenas o status**: o motivo de pendência e a solução registrados no movimento continuam no histórico do chamado. Pode ser desligado na configuração.
* **Visões salvas:** Salve o conjunto de filtros atual com um nome e aplique-o depois. As visões ficam gravadas no servidor, vinculadas ao usuário, então acompanham o usuário entre navegadores e não se perdem ao limpar o armazenamento do navegador. O usuário que salvou gerencia as próprias visões; compartilhar uma visão com todos fica restrito a quem pode alterar a configuração do plugin.
* **Limite de trabalho em progresso (WIP):** Defina um limite por coluna. Com o bloqueio ativado, a coluna no limite recusa novos chamados e o movimento é revertido com o motivo; sem ele, o limite apenas destaca a coluna. A contagem considera os chamados visíveis para o usuário e ignora os filtros ativos, porque o limite vale para a fila e não para a visão atual. O mesmo número aparece no cabeçalho da coluna.
* **Responsivo:** Layout adaptativo para desktop, tablet e mobile (scroll horizontal com snap)
* **Paginação por coluna:** As colunas carregam uma página de cards por vez (configurável, 1 a 200) com botão **Carregar mais**. O rodapé da coluna mostra o total real de chamados que atendem aos filtros e quantos estão na tela, então um total grande não é confundido com uma coluna cheia. As métricas do topo (SLA, atribuídos a mim, sem responsável) são calculadas sobre os cards carregados e marcadas como parciais quando há mais chamados além da página.

---

## 🔒 Regras de acesso e visibilidade

### 1. Permissão por perfil (acesso à página)

Para acessar o quadro é obrigatório:

* Estar logado no GLPI;
* Ter o direito **Kanban > Visualizar o quadro kanban** (`plugin_kanban`) no perfil ativo;
* Ter direito de leitura de chamados (`Ticket::canView()`).

Sem o direito do plugin, o usuário recebe erro de direito (HTTP 403 no GLPI 11; página de erro no GLPI 10). A permissão é configurável em **Administração > Perfis** (aba **Kanban**), de forma **independente** dos demais direitos de chamado — um perfil pode ler chamados no GLPI e mesmo assim não enxergar o quadro.

> **Exemplo:** o perfil precisa ter um direito de leitura de chamados **e** o direito `plugin_kanban` marcado. O perfil **Super-Admin** já vem com o direito marcado por padrão.

### 2. Visibilidade dos chamados no quadro (item-level)

A visibilidade de cada chamado é calculada por `getTicketVisibilityCriteria()` e **se aplica a todos os perfis, inclusive Super-Admin e perfis com leitura ampla (READALL)**. Um chamado aparece no quadro apenas quando **ao menos uma** das condições abaixo é verdadeira:

* **(a) Atribuição individual:** o usuário é **técnico** do chamado (`glpi_tickets_users` com tipo `ASSIGN`);
* **(b) Grupo da equipe:** um dos **grupos dos quais o usuário pertence** (incluindo **subgrupos**) está atribuído ao chamado como equipe técnica (`glpi_groups_tickets` com tipo `ASSIGN`) — mesmo que ainda **não haja técnico individual** atribuído, permitindo a auto-atribuição;
* **(c) Gerência de grupo:** o usuário é **gerente** de um grupo (`glpi_groups_users.is_manager = 1`) e o chamado está atribuído a **qualquer membro** dos grupos gerenciados, incluindo membros de **subgrupos**.

Um chamado **não** aparece quando a participação do usuário é apenas:

* **Observador** (`OBSERVER`), mesmo que o grupo dele seja observador do chamado;
* **Requerente** (`REQUESTER`);
* Nenhuma participação — chamado de outro usuário, de outro grupo, ou de um grupo ao qual o usuário não pertence.

> **Exemplo (gerente de grupo):** o **gerente** do grupo vê **todos** os chamados do grupo, inclusive os **delegados** a outros usuários membros (e a membros de subgrupos) individualmente.
>
> **Exemplo (membro comum):** o **membro** de um grupo vê apenas chamados atribuídos ao próprio grupo (e subgrupos) ou a ele individualmente. Ele **não vê** o chamado delegado a um colega do mesmo grupo — apenas o gerente o enxerga.
>
> **Exemplo (permissão de perfil):** um usuário que pertence ao grupo, mas cujo perfil **não possui** o direito `plugin_kanban`, é **bloqueado** na página do Kanban.
>
> **Exemplo (usuário sem grupo):** um usuário sem grupos só vê chamados nos quais é técnico individual.

> **Importante:** a condição (b) lê as associações de grupo diretamente do banco (`glpi_groups_users`) a cada chamada, e não da sessão — novas associações passam a valer **sem re-login**, e membros com sessão "antiga" mantêm a visibilidade correta. Os caches de subgrupos (`ancestors_cache`/`sons_cache` do GLPI) são respeitados para a expansão de subgrupos e subcategorias.

### 3. Entidades

O quadro respeita as **entidades ativas** da sessão (`glpiactiveentities`). Chamados fora das entidades ativas não são exibidos, mesmo que o usuário participe deles.

---

## ⏱️ Regras de SLA

O progresso de SLA é calculado entre a **data de abertura** (`date_creation`) e o prazo (`time_to_resolve`):

* **Em andamento:** o relógio conta em tempo real e a barra muda de cor (≤79% sucesso, 80–99% aviso, 100%+ atrasado);
* **Solucionado (5) ou Fechado (6):** o SLA é **congelado** na data de `solvedate` (fallback: `date_mod`). O percentual não cresce mais após a resolução;
* **Pendente (4):** o SLA é **pausado** no início da pendência (`begin_waiting_date`). Enquanto pendente, o percentual congela, mas a **duração do chamado continua contando** normalmente;
* Sem prazo (`time_to_resolve` vazio): sem SLA, barra neutra;
* Atrasado antes mesmo de abrir (prazo anterior à criação): 100% e status de atrasado imediatamente.

---

## 🛠️ Pré-requisitos

* **GLPI:** Versão 10.0.0 ou superior (GLPI 10 e GLPI 11 testados)
* **PHP:** Versão 8.0 ou superior
* Permissões de Super-Admin para instalar plugins

---

## 🚀 Instalação

1. Clone o repositório na pasta `plugins/` do GLPI:
   ```bash
   cd /var/www/html/glpi/plugins
   git clone https://github.com/l33one/glpi-plugin-kanban.git kanban
   ```

2. Acesse o GLPI como Super-Admin, vá em **Configurar > Plugins** e instale o plugin **Kanban**

3. O menu **Assistência > Kanban** estará disponível apenas para usuários que possuam, em seu perfil, a permissão **Kanban > Visualizar o quadro kanban**. Para liberar a outros perfis, acesse **Administração > Perfis**, edite o perfil e marque a opção na aba **Kanban**

---

## 🐳 Desenvolvimento com Docker

O ambiente de teste usa as imagens oficiais do GLPI (`glpi/glpi`) e é orquestrado por stacks dedicadas:

```bash
# GLPI 10 (porta 8090)
docker compose -f docker-compose.glpi10.yml up -d --wait

# GLPI 11 (porta 8091)
docker compose -f docker-compose.glpi11.yml up -d --wait
```

Acesse o quadro em:
* GLPI 10: `http://localhost:8090/plugins/kanban/front/kanban.php`
* GLPI 11: `http://localhost:8091/plugins/kanban/front/kanban.php`

(as credenciais padrão são `glpi` / `glpi`)

O orquestrador `test-plugin.ps1` sobe as duas versões, instala/ativa o plugin via `bin/console`, cria os **14 chamados de teste** (todos os status, com SLA congelado) e opcionalmente roda a suíte PHPUnit (`-Tests`):

```powershell
.\test-plugin.ps1            # sobe GLPI 10 e 11, instala o plugin e semeia os chamados
.\test-plugin.ps1 -Tests     # idem + roda a suíte PHPUnit (GLPI 10)
.\test-plugin.ps1 -Down      # derruba as stacks
```

---

## 🧪 Testes

Dois níveis de teste são suportados, ambos validados contra **GLPI 10** e **GLPI 11**:

### Suíte PHPUnit (no GLPI 10)

Roda dentro do container contra a instância real (visibilidade, filtros, ordenação, SLA, paginação, WIP, undo e regressões):

```bash
docker compose -f docker-compose.glpi10.yml exec -T \
  -e GLPI_ROOT=/var/www/glpi -e GLPI_CONFIG_DIR=/var/glpi/config \
  glpi sh -c "cd /var/www/glpi/plugins/kanban && php /tmp/phpunit-9.phar -c phpunit.xml.dist"
```

> **Limitação no GLPI 11:** a suíte CLI não executa no GLPI 11 porque essa versão registra as classes legadas (ex.: `Ticket`) apenas no bootstrap web (kernel Symfony). O GLPI 11 é coberto pelos testes E2E abaixo.

### Testes E2E de visibilidade (web, multiusuário)

O cenário `tests/e2e/seeder.php` + `tests/e2e/test-visibility.ps1` valida as regras de visibilidade via **login real** em cada versão. O seeder é **CLI-only** e lê credenciais **somente do ambiente** (sem padrão e sem fallback):

```powershell
$env:KANBAN_DB_HOST='127.0.0.1'; $env:KANBAN_DB_USER='glpi'
$env:KANBAN_DB_PASS='glpi_password'; $env:KANBAN_DB_NAME='glpi'
# senha das contas do cenário: obrigatória, escolha a sua
$env:KANBAN_TEST_PASS='<senha-do-cenário>'
```

```powershell
# Testa as regras no GLPI 10 e 11 (30 checks por versão)
.\tests\e2e\test-visibility.ps1

# Apenas no GLPI 11
.\tests\e2e\test-visibility.ps1 -Versions 11

# Remove o cenário semeado
.\tests\e2e\test-visibility.ps1 -Cleanup
```

### E2E de recursos do quadro

`tests/e2e/test-board-features.ps1` cobre o que não está no teste de visibilidade:
paginação com total real por coluna, visões salvas (criar, reaplicar, apagar),
desfazer movimento e limite de WIP. Precisa do cenário acima:

```powershell
.\tests\e2e\test-board-features.ps1
```

A suíte roda em CI nas matrizes GLPI 10 e 11 (`.github/workflows/ci.yml`), junto
com o lint de PHP/JavaScript, a conferência do `kanban.xml`, a checagem das traduções
e a construção do pacote.

O cenário cria 2 perfis (com e sem o direito do plugin), 3 grupos (pai → subgrupo + grupo fora), gerente/membros/sem-grupo e 7 chamados (atribuídos a grupo, a usuário delegado, a subgrupo, a grupo externo, observador, etc.). As regras verificadas:

| Usuário | Vê | Não vê |
|---|---|---|
| **Gerente** do grupo | todos os chamados do grupo (grupo-pai, subgrupo) **e os delegados a outros membros** | grupo externo, observador, estrangeiro |
| **Membro** do grupo | chamados do grupo e do subgrupo | chamado delegado ao colega, grupo externo, observador |
| Membro de **subgrupo** | chamados do subgrupo | chamados do grupo pai, delegações, grupo externo |
| Usuário **sem grupo** | apenas os que é técnico | demais |
| Usuário com **perfil sem direito** | — | é **bloqueado** no quadro (erro de direito) |
| **Super-Admin** | seus chamados | chamados do cenário sem participação (restrição vale para todos) |

---

## 📁 Estrutura do Plugin

```
kanban/
├── setup.php                  # Registro do plugin, hooks, versão
├── hook.php                   # Instalação/desinstalação
├── kanban.xml                 # Descriptor (versão, compatibilidade, download_url)
├── inc/
│   ├── kanban.class.php       # Model principal: queries, filtros, paginação, SLA, visibilidade, WIP, direitos
│   ├── api.class.php          # Controller da API JSON: leitura/escrita, presets, undo
│   ├── filterpreset.class.php # Visões salvas (CommonDBTM, glpi_kanban_filter_presets)
│   ├── config.class.php       # Configuração via Config API do GLPI
│   ├── menu.class.php         # Registro do menu no GLPI
│   └── profile.class.php      # Aba de permissões no formulário de Perfil
├── front/
│   ├── kanban.php             # Controller da página (renderiza o quadro)
│   ├── api.php                # Endpoint JSON (front/api.php?action=...)
│   └── config.php             # Página de configuração do plugin
├── public/
│   ├── js/kanban.js           # Lógica frontend: drag-drop, filtros, countdown
│   └── css/kanban.css         # Estilos customizados + responsivo
├── locales/                   # Traduções en_GB / pt_BR
├── tests/                     # [dev] suíte PHPUnit + E2E
├── tools/                     # [dev] compilador de locales e build de release
├── docker/                    # [dev] binários auxiliares (phpunit.phar é baixado sob demanda)
├── create_test_tickets.php    # [dev] cria os 14 chamados de teste (todos os status)
├── docker-compose.glpi10.yml  # [dev] stack GLPI 10 (porta 8090)
├── docker-compose.glpi11.yml  # [dev] stack GLPI 11 (porta 8091)
├── test-plugin.ps1            # [dev] orquestrador: up/install/ativo/seed/tests/down
└── README.md
```

> Os itens marcados com `[dev]` existem no repositório para revisão e CI, mas
> **nunca** são publicados: ver [Publicação](#-publicação).

---

## 📦 Publicação

O pacote distribuível é gerado a partir de um **commit/tag** com `git archive`, e o
`.gitattributes` define o que entra nele:

```bash
# após commitar, cria a tag <versão> e gera o pacote
git tag -a 1.3.0 -m "1.3.0"
tools/build-release.sh            # ou: tools/build-release.sh 1.3.0 dist
# -> dist/glpi-plugin-kanban-1.3.0.tar.bz2
```

Regras aplicadas em `.gitattributes` (`export-ignore`), que **excluem do pacote**:
`tests/`, `tools/`, `docker/`, `dist/`, `.github/`, `docker-compose*.yml`,
`create_test_tickets.php`, `.env.example`, `phpunit.xml.dist`, `test-plugin.ps1`,
`prompt.md` e `PLAN-*.md`.

O script falha (exit != 0) se a árvore tiver alterações não commitadas, se a versão
não apontar para o `HEAD`, se o prefixo `kanban/` estiver ausente ou se qualquer
arquivo de desenvolvimento escapar para o pacote. O `download_url` publicado no
`kanban.xml` aponta exatamente para esse `.tar.bz2`.

---

## 🔒 Segurança

* **Autorização:** acesso à página, ao menu e à API controlados pela permissão **plugin_kanban** (perfil) + `Ticket::canView()`. A API é o mesmo entrypoint para todos os verbos: `front/api.php` só aceita POST nas ações que alteram dados
* **Visões salvas:** o dono cria, renomeia e apaga as próprias visões com o mesmo direito que abre o quadro, e o conteúdo gravado é apenas o conjunto de filtros que a pessoa já pode digitar no formulário. Compartilhar uma visão com todos exige `config` UPDATE, e editar a visão de outra pessoa também
* **Autorização por item:** toda escrita (`assign_to_me`, `change_priority`, `update_ticket_status`, `undo_ticket_status`) valida a visibilidade do chamado no quadro (`isTicketVisible()`) **antes** de gravar, além de `Ticket::canUpdateItem()` — um perfil com leitura ampla (READALL) ou Super-Admin não contorna a regra de visibilidade do quadro
* **WIP no servidor:** o limite é conferido em `updateTicketStatus()` e em `undoStatusChange()`, com a mesma contagem mostrada na coluna; a validação do JavaScript é só conforto de interface
* **Auto-atribuição:** `assign_to_me` reproduz a regra do core (`Ticket::canAssignToMe()` ou `Ticket::canAssign()`, ou seja, `STEAL`, ou `OWN` em chamado ainda sem técnico) — `addTeamMember()` insere a linha em `glpi_tickets_users` sem nenhuma checagem
* **Leitura de seguimentos:** `get_followups` exige `Ticket::canViewItem()` (checagem de entidade) e filtra cada acompanhamento com `ITILFollowup::canViewItem()` (`SEEPUBLIC`/`SEEPRIVATE`), além de esconder privados de outros autores
* **SQL:** nenhum corpo de template de solução é copiado do banco para o `add()`; o plugin passa `_solutiontemplates_id` e deixa o core renderizar e escapar (`ITILSolution::prepareInputForAdd()`), o que é obrigatório no GLPI 10, onde o query builder não escapa valores
* **CSRF:** token do GLPI validado pelo core em todo POST (`Session::checkCSRF` no GLPI 10, `CheckCsrfListener` no GLPI 11) e token novo devolvido em cada resposta AJAX, pois o token é de uso único
* **XSS:** labels dos filtros escapados com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`; conteúdo rico (descrição e seguimentos) sanitizado **no servidor** com `Glpi\RichText\RichText::getSafeHtml()`; valores inline em `<script>` com `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`
* **Erros:** falhas no `get_tickets` são registradas no log do GLPI e retornam mensagem genérica — a exceção bruta (que pode conter SQL) nunca chega ao navegador
* **Filtros sem vazamento:** o filtro de requerentes lista apenas usuários que realmente são requerentes em chamados (`INNER JOIN glpi_tickets_users`), e o filtro de técnicos por grupo só aceita grupos expandidos do próprio usuário
* **Entidades:** todo acesso respeita `$_SESSION['glpiactiveentities']` via `getEntitiesRestrictCriteria()`
* **Configuração:** persistida pela Config API do GLPI (`Config::setConfigurationValues`/`getConfigurationValues`) — não há mais arquivo PHP incluível; a desinstalação remove o contexto e o `kanban_config.php` legado
* **Scaffolding de desenvolvimento:** seeders e docker são CLI-only (`PHP_SAPI !== 'cli'` → 403), leem credenciais **somente do ambiente** (abortam se `KANBAN_DB_*` não estiver definido) e são excluídos do pacote de release

---

## 📝 Licença

GPL-2.0+ - Veja o arquivo LICENSE para mais detalhes.

**Autor:** Leewan Meneses  
**Repositório:** https://github.com/l33one/glpi-plugin-kanban