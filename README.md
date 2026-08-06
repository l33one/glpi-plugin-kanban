# 📋 GLPI Kanban Plugin

Um plugin para o **GLPI 10+** que transforma o gerenciamento de chamados adicionando uma visualização em formato **Kanban** altamente interativa.

Este plugin permite que técnicos e gestores acompanhem o fluxo de trabalho de forma visual, organizando os chamados (tickets) em colunas baseadas em seus status nativos do GLPI.

---

## ✨ Funcionalidades

* **Visualização Ágil:** Colunas dinâmicas mapeadas diretamente para os status dos chamados (Novo, Em Atendimento, Pendente, Solucionado, Fechado, etc.)
* **Controle de Acesso Seguro:** Exibe apenas chamados que o usuário logado possui permissão de visualizar (Ticket::canView), respeitando regras de perfil e entidades do GLPI
* **Permissão por Perfil:** A permissão para visualizar a página do Kanban é configurável em **Administração > Perfis** (aba Kanban), permitindo liberar/negar o acesso por perfil de forma independente dos direitos de tickets
* **Filtros Avançados em Tempo Real:** 
  * Filtragem por Grupo
  * Filtragem por Técnico Atribuído
  * Filtragem por Requerente
  * Pesquisa por Número do Chamado (campo de busca que filtra os cards pelo número/ID do chamado)
* **Ordenação Inteligente:** Cards ordenados por Prioridade, Data de Abertura ou Tempo no Status Atual
* **Cards Ricos em Informações:** 
  * ID e Título do chamado
  * **Barra de SLA:** Indicador visual de progresso de SLA (verde/amarelo/vermelho) com contagem regressiva
  * Data de abertura, Técnico atribuído, Prioridade e Categoria
* **Drag and Drop:** Mover cards entre colunas para alterar o status, com rollback otimista em caso de falha
* **Responsivo:** Layout adaptativo para desktop, tablet e mobile (scroll horizontal com snap)
* **Performance:** Limite de 200 tickets por status com debounce nos filtros

---

## 🛠️ Pré-requisitos

* **GLPI:** Versão 10.0.0 ou superior
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

3. O menu **Assistência > Kanban** estará disponível apenas para usuários que possuam, em seu perfil, a permissão **Kanban > Visualizar o quadro kanban** (marcada por padrão no perfil Super-Admin). Para liberar o acesso a outros perfis, acesse **Administração > Perfis**, edite o perfil desejado e marque a opção na aba **Kanban**

---

## 🐳 Desenvolvimento com Docker

```bash
docker-compose up -d
```

Acesse: `http://localhost:8088`

### Criar tickets de teste
```bash
docker exec glpi-app php /var/www/html/glpi/plugins/kanban/create_test_tickets.php
```

---

## 📁 Estrutura do Plugin

```
kanban/
├── setup.php                  # Registro do plugin, hooks, versão
├── hook.php                   # Instalação/desinstalação
├── inc/
│   ├── kanban.class.php       # Model principal: queries, filtros, SLA, direitos
│   ├── menu.class.php         # Registro do menu no GLPI
│   └── profile.class.php      # Aba de permissões no formulário de Perfil
├── front/
│   └── kanban.php             # Controller: página e endpoints AJAX
├── templates/
│   └── kanban.html.twig       # Template Twig (server-side rendering)
├── public/
│   ├── js/kanban.js           # Lógica frontend: drag-drop, filtros, countdown
│   └── css/kanban.css         # Estilos customizados + responsivo
├── create_test_tickets.php    # Script para criar tickets de teste
├── docker-compose.yml         # Ambiente de desenvolvimento
└── README.md
```

---

## 🔒 Segurança

* CSRF token em todas as requisições POST
* Acesso à página e ao menu controlados pela permissão **plugin_kanban** (perfil) + `Ticket::canView()`
* Validação de permissões de atualização via `Ticket::canUpdateItem()`
* Restrição de entidades via `$_SESSION['glpiactiveentities']`

---

## 📝 Licença

GPL-2.0+ - Veja o arquivo LICENSE para mais detalhes.

**Autor:** Leewan Meneses  
**Repositório:** https://github.com/l33one/glpi-plugin-kanban
