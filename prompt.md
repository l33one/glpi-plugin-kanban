# Contexto e Objetivo
Você é um desenvolvedor especialista PHP e GLPI. Seu objetivo é criar a estrutura completa e o código para um plugin do GLPI 10+ que adiciona uma visualização em formato **Kanban** para os chamados (*Tickets*).

---

## 🏗️ Requisitos Técnicos e Arquitetura

1. **Compatibilidade:** Exclusivamente **GLPI 10.0.0 ou superior**.
2. **Estrutura de Plugin:** Seguir rigorosamente o padrão de diretórios do GLPI:
   - Nome interno do plugin (folder): `kanban`
   - Arquivos principais: `setup.php`, `hook.php`
   - Classes principais na pasta `inc/` (ex: `inc/kanban.class.php`, `inc/menu.class.php`)
   - Interfaces e templates na pasta `templates/` (Twig) ou controllers na pasta `front/`
   - Assets JS/CSS na pasta `public/` ou `css/`/`js/`
3. **Segurança & Permissões:**
   - Respeitar a API interna de permissões do GLPI (`Ticket::canView()`, filtros por perfil do usuário logado via `Ticket::getSearchOptions()`, `Session::getLoginUserID()`, etc.).
   - Utilizar validação de tokens CSRF (`Session::checkCSRF()`) em requisições AJAX/POST.
4. **Interface e Framework Front-end:**
   - Utilizar a interface padrão do GLPI 10 (Tabler UI / Bootstrap 5 / Twig).
   - Manipulação de drag-and-drop no Kanban utilizando JavaScript vanila ou bibliotecas leves integradas ao GLPI (como HTML5 Drag and Drop API ou SortableJS via CDN/assets locais).

---

## 📋 Especificações Funcionais do Plugin

### Especificação 1: Menu e Aba Principal
- Criar um menu ou aba dedicada na navegação principal do GLPI (ex: no menu *Assistência* -> *Kanban* ou em uma aba separada na central de chamados).
- A tela deve renderizar um quadro Kanban completo com colunas representando cada **Status de Chamado** nativo do GLPI (*Novo*, *Em Atendimento (Atribuído)*, *Pendente*, *Solucionado*, *Fechado*, etc.).

### Especificação 2: Estrutura das Colunas
- Cada coluna deve carregar dinamicamente a lista de chamados cujo status atual corresponda ao status daquela coluna.

### Especificação 3: Controle de Acesso e Permissão
- Apenas chamados que o usuário logado possui permissão para visualizar devem ser retornados nas consultas SQL/API interna (`Ticket::getSearchRequest()` ou queries preparadas considerando `$_SESSION['glpiactiveentities']` e regras de visibilidade de grupo/técnico/requerente).

### Especificação 4: Filtros de Visualização
Na parte superior do Kanban, incluir uma barra de filtros com atualizações em tempo real (ou via AJAX):
- **Filtro por Grupo** (grupos atribuídos ao chamado ou do usuário).
- **Filtro por Técnico Atribuído**.
- **Filtro por Requerente**.

### Especificação 5: Ordenação por Coluna
Cada coluna do Kanban deve ter um cabeçalho com botões/dropdown de ordenação para reorganizar os cards daquela coluna por:
- **Prioridade** (Maior -> Menor / Menor -> Maior)
- **Data de Abertura** (Mais recente / Mais antigo)
- **Tempo no Status Atual** (Maior tempo no status / Menor tempo no status)

### Especificação 6: Design do Card de Chamado
Cada card dentro das colunas do Kanban deve exibir:
- **Título do Card:** ID do chamado (`#<ID>`) seguido pelo título/assunto do chamado.
- **Barra de Progresso do SLA:**
  - Exibir o progresso do tempo de solução/atendimento (SLA) visualmente em uma barra de status (verde se dentro do prazo, amarela próxima do limite, vermelha se estourado).
- **Metadados:**
  - Data de abertura (formatada conforme padrão do GLPI).
  - Técnico atribuído (nome/avatar ou "Não atribuído").
  - Prioridade (Badge colorido padrão do GLPI).
  - Categoria do chamado.

---

## 🛠️ O que você deve gerar:

1. **`setup.php`**: Definição da versão do plugin, autor, verificação de pré-requisitos e registro de hooks/menus. (✨ *Implementado*)
2. **`hook.php`**: Funções de instalação (`plugin_kanban_install`), desinstalação (`plugin_kanban_uninstall`) e hooks de renderização se necessário. (✨ *Implementado*)
3. **`inc/kanban.class.php`**: Classe principal para manipular busca de chamados no banco de dados, regras de ordenação, tratamento de filtros e verificação de permissões do usuário logado. (✨ *Implementado*)
4. **`front/kanban.php`**: Controller que carrega a página e responde às requisições AJAX dos filtros e ordenação. (✨ *Implementado*)
5. **`templates/kanban.html.twig`**: Arquivo de visualização para renderizar a interface no padrão GLPI 10. (✨ *Implementado*)
6. **`public/js/kanban.js`** e **`public/css/kanban.css`**: Estilização e lógica dos cards, drag-and-drop, filtros e barras de SLA. (✨ *Implementado*)

---

## 🚀 Status do Projeto e Próximos Passos
O plugin **GLPI Kanban** foi estruturado e desenvolvido com sucesso atendendo a todos os requisitos técnicos e funcionais especificados (compatibilidade com GLPI 10+, suporte a PHP 8.x, segurança CSRF, permissões de entidades/perfis, filtros dinâmicos, ordenação, cálculo de SLA e movimentação por Drag and Drop com atualização assíncrona via AJAX).
