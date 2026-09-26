<?php
/**
 * Create test tickets for the Kanban plugin directly via MySQL
 *
 * Execute via (official glpi/glpi compose stacks):
 *   .\test-plugin.ps1
 *   # or directly:
 *   docker compose -f docker-compose.glpi10.yml exec -T `
 *     -e KANBAN_DB_HOST=db -e KANBAN_DB_USER=glpi -e KANBAN_DB_PASS=glpi_password `
 *     -e KANBAN_DB_NAME=glpi -e KANBAN_URL=http://localhost:8090/plugins/kanban/front/kanban.php `
 *     glpi php /var/www/glpi/plugins/kanban/create_test_tickets.php
 *
 * Or legacy diouxx/glpi:
 *   docker exec glpi-app php /var/www/html/glpi/plugins/kanban/create_test_tickets.php
 *
 * Creates tickets across all GLPI statuses with varied priorities, categories, and SLA deadlines
 * to fully exercise the Kanban board features, including the frozen SLA for
 * solved (5) / closed (6) tickets (freeze at solvedate) and waiting (4) tickets
 * (SLA frozen at begin_waiting_date).
 *
 * Connection settings can be overridden via environment variables:
 *   KANBAN_DB_HOST  (comma separated fallback list)
 *   KANBAN_DB_USER
 *   KANBAN_DB_PASS
*  KANBAN_DB_NAME
 *  KANBAN_URL
 */

// CLI-only defence in depth: never web-reachable, even if the file is copied
// into a served directory that bypasses the plugin's release packaging.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$host    = (string)getenv('KANBAN_DB_HOST');
$user    = (string)getenv('KANBAN_DB_USER');
$pass    = (string)getenv('KANBAN_DB_PASS');
$dbname  = (string)getenv('KANBAN_DB_NAME');
$kanban_url = (string)getenv('KANBAN_URL');
if ($host === '' || $user === '' || $pass === '' || $dbname === '') {
    fwrite(STDERR, "Set KANBAN_DB_HOST, KANBAN_DB_USER, KANBAN_DB_PASS and KANBAN_DB_NAME before running.\n");
    exit(1);
}

$conn = null;
$conn_error = '';
$c = @new mysqli($host, $user, $pass, $dbname);
if ($c->connect_error) {
    $conn_error = $c->connect_error;
} else {
    $conn = $c;
}
if (!$conn) {
    fwrite(STDERR, "Connection failed: " . $conn_error . "\n");
    exit(1);
}

echo "Connected to MariaDB successfully.\n\n";

// The Kanban board only shows tickets the current user can see (item-level visibility),
// so all seeded tickets are assigned to the default super-admin user "glpi".
$glpi_uid = 2; // default in a fresh GLPI install
$res = $conn->query("SELECT id FROM glpi_users WHERE name = 'glpi' LIMIT 1");
if ($res && $row = $res->fetch_row()) {
    $glpi_uid = (int)$row[0];
}
echo "Usuario 'glpi' tem id $glpi_uid.\n";

// Clean up tickets from previous runs (idempotent) so re-running the seed gives a clean board.
$content_marker = 'Chamado de teste para o plugin Kanban';
$res = $conn->query("SELECT id FROM glpi_tickets WHERE content LIKE '%" . $conn->real_escape_string($content_marker) . "%'");
$old_ids = [];
if ($res) {
    while ($row = $res->fetch_row()) {
        $old_ids[] = (int)$row[0];
    }
}
if (!empty($old_ids)) {
    $ids_in = implode(',', $old_ids);
    $conn->query("DELETE FROM glpi_tickets_users WHERE tickets_id IN ($ids_in)");
    $conn->query("DELETE FROM glpi_tickets WHERE id IN ($ids_in)");
    echo 'Removidos ' . count($old_ids) . " tickets de execucoes anteriores.\n\n";
}

$now = date('Y-m-d H:i:s');

// SLA deadlines: 2h, 8h, 24h, 3d, 5d from now
$sla_2h  = date('Y-m-d H:i:s', strtotime('+2 hours'));
$sla_8h  = date('Y-m-d H:i:s', strtotime('+8 hours'));
$sla_24h = date('Y-m-d H:i:s', strtotime('+24 hours'));
$sla_3d  = date('Y-m-d H:i:s', strtotime('+3 days'));
$sla_5d  = date('Y-m-d H:i:s', strtotime('+5 days'));
$sla_exp = date('Y-m-d H:i:s', strtotime('-1 hour')); // Already expired SLA

$tickets = [
    // Status 1 (New)
    ['Erro de login - Portal cliente (Novo)',       1, 5, $sla_2h],
    ['Atualizar servidor de homologação (Novo)',    1, 4, $sla_24h],
    ['Solicitação de acesso VPN - João Silva',      1, 3, $sla_5d],

    // Status 2 (Assigned)
    ['Falha no backup noturno - Verificar logs',    2, 6, $sla_exp],
    ['Configurar novo notebook colaborador',        2, 2, $sla_3d],
    ['Migrar banco de dados legacy',                2, 4, $sla_8h],

    // Status 3 (Planned)
    ['Implementar SSO para sistema interno',        3, 3, $sla_5d],
    ['Revisão de segurança trimestral',             3, 4, $sla_3d],

    // Status 4 (Pending)
    ['Aguardando aprovação de orçamento - Servidor',4, 5, $sla_24h],
    ['Resposta do fornecedor pendente - Firewall',  4, 3, $sla_8h],

    // Status 5 (Solved)
    ['Monitor externo substituído com sucesso',     5, 2, $sla_exp],
    ['Patch de segurança aplicado nos servidores',  5, 3, $sla_exp],

    // Status 6 (Closed)
    ['Treinamento novo sistema finalizado',         6, 3, $sla_exp],
    ['Contrato de manutenção renovado',             6, 4, $sla_exp],
];

foreach ($tickets as $i => $data) {
    $name     = $conn->real_escape_string($data[0]);
    $status   = (int)$data[1];
    $priority = (int)$data[2];
    $sla      = $conn->real_escape_string($data[3]);

    // Freeze references so the Kanban board shows frozen SLA / pending duration:
    // - solved/closed: SLA freezes at solvedate
    // - waiting: SLA freezes at begin_waiting_date (duration keeps counting)
    $begin_waiting_date = $status === 4 ? "'$now'" : 'NULL';
    $solvedate          = in_array($status, [5, 6], true) ? "'$now'" : 'NULL';

    $sql = "INSERT INTO glpi_tickets
        (name, content, status, priority, urgency, impact, type, date, date_creation, date_mod,
         entities_id, is_deleted, itilcategories_id, requesttypes_id, users_id_lastupdater,
         users_id_recipient, actiontime, begin_waiting_date, solvedate, time_to_resolve)
        VALUES
        ('$name', 'Chamado de teste para o plugin Kanban - todos os status.',
         $status, $priority, 2, 2, 1, '$now', '$now', '$now',
         0, 0, 0, 0, $glpi_uid, $glpi_uid, 0, $begin_waiting_date, $solvedate, '$sla')";

    if ($conn->query($sql)) {
        $newId = $conn->insert_id;

        // Assign the ticket to user glpi as technician (type 2) so it appears on the board.
        $conn->query("INSERT INTO glpi_tickets_users (tickets_id, users_id, type)
                      VALUES ($newId, $glpi_uid, 2)");
        // A few tickets also have glpi as requester (type 1), to exercise the requester filter.
        if (in_array($i, [0, 3, 6], true)) {
            $conn->query("INSERT INTO glpi_tickets_users (tickets_id, users_id, type)
                          VALUES ($newId, $glpi_uid, 1)");
        }

        echo sprintf("Ticket %2d - [%s] '%s' (ID=%d)\n", $i + 1, getStatusName($status), $data[0], $newId);
    } else {
        echo sprintf("Ticket %2d - FALHOU: %s\n", $i + 1, $conn->error);
    }
}

echo "\nTotal: " . count($tickets) . " tickets criados em todos os 6 status.\n";
echo "Acesse: $kanban_url\n";
$conn->close();

/**
 * Get human-readable status name
 */
function getStatusName(int $status): string {
    return [
        1 => 'Novo',
        2 => 'Em Atend.',
        3 => 'Planejado',
        4 => 'Pendente',
        5 => 'Solucionado',
        6 => 'Fechado',
    ][$status] ?? "Status $status";
}
