# 📋 GLPI Kanban Plugin

Um plugin para o **GLPI 10+** que transforma o gerenciamento de chamados adicionando uma visualização em formato **Kanban** altamente interativa. 

Este plugin permite que técnicos e gestores acompanhem o fluxo de trabalho de forma visual, organizando os chamados (tickets) em colunas baseadas em seus status nativos do GLPI.

---

## ✨ Funcionalidades

* **Visualização Ágil:** Colunas dinâmicas mapeadas diretamente para os status dos chamados (Novo, Em Atendimento, Pendente, Solucionado, Fechado, etc.).
* **Controle de Acesso Seguro:** Exibe no quadro apenas os chamados aos quais o usuário logado possui permissão para visualizar, respeitando as regras nativas de perfis e entidades do GLPI.
* **Filtros Avançados em Tempo Real:** 
  * Filtragem por Grupo.
  * Filtragem por Técnico Atribuído.
  * Filtragem por Requerente.
* **Ordenação Inteligente:** Botões em cada coluna para ordenar os cards por Prioridade, Data de Abertura ou Tempo no Status Atual.
* **Cards Ricos em Informações:** 
  * ID e Título do chamado em destaque.
  * **Barra de SLA:** Indicador visual de progresso do SLA de atendimento/solução (cores de alerta para prazos próximos ou estourados).
  * Data de abertura, Técnico atribuído (com avatar, se disponível), Prioridade e Categoria.

---

## 🛠️ Pré-requisitos

* **GLPI:** Versão 10.0.0 ou superior.
* **PHP:** Versão 8.0 ou superior (seguindo o padrão do GLPI 10).
* Permissões de super-admin ou configuração de plugins para realizar a instalação.

---

## 🚀 Instalação

1. Faça o download da última versão deste plugin ou clone o repositório.
2. Extraia ou mova a pasta do plugin para dentro do diretório `plugins/` do seu GLPI. É crucial que a pasta se chame exatamente **`kanban`**.
   
   ```bash
   cd /var/www/html/glpi/plugins
   git clone [https://github.com/l33one/glpi-plugin-kanban.git](https://github.com/l33one/glpi-plugin-kanban.git) kanban