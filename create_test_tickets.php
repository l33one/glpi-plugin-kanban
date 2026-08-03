<?php
/**
 * Create test tickets for the Kanban plugin directly via MySQL
 *
 * Execute via:
 *   docker exec glpi-app php /var/www/html/glpi/plugins/kanban/create_test_tickets.php
 *
 * Creates tickets across all GLPI statuses with varied priorities, categories, and SLA deadlines
 * to fully exercise the Kanban board's features.
 */

$host = 'mariadb';
$user = 'glpi_user';
$pass = 'glpi';
$dbname = 'glpidb_10';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    $conn = new mysqli('glpi-db', $user, $pass, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error . "\n");
    }
}

echo "Connected to MariaDB successfully.\n\n";

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

    $sql = "INSERT INTO glpi_tickets
        (name, content, status, priority, urgency, impact, type, date, date_creation, date_mod,
         entities_id, is_deleted, itilcategories_id, requesttypes_id, users_id_lastupdater,
         users_id_recipient, actiontime, begin_waiting_date, time_to_resolve)
        VALUES
        ('$name', 'Chamado de teste para o plugin Kanban - todos os status.',
         $status, $priority, 2, 2, 1, '$now', '$now', '$now',
         0, 0, 0, 0, 2, 2, 0, NULL, '$sla')";

    if ($conn->query($sql)) {
        $newId = $conn->insert_id;
        echo sprintf("Ticket %2d - [%s] '%s' (ID=%d)\n", $i + 1, getStatusName($status), $data[0], $newId);
    } else {
        echo sprintf("Ticket %2d - FALHOU: %s\n", $i + 1, $conn->error);
    }
}

echo "\nTotal: " . count($tickets) . " tickets criados em todos os 6 status.\n";
echo "Acesse: http://localhost:8088/glpi/plugins/kanban/front/kanban.php\n";
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
