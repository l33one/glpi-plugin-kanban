<?php
/**
 * Test bootstrap for the GLPI Kanban plugin.
 *
 * This bootstrap initializes the full GLPI environment (DB, session, plugin
 * autoloading) so that unit tests can exercise the plugin classes against a
 * real GLPI 10 instance.
 *
 * The GLPI root directory can be configured via the GLPI_ROOT environment
 * variable. If not set, it falls back to a standard Linux path and then
 * checks for a local config.php file.
 */

// CLI-only defence in depth: this bootstrap creates files/_tests/* and loads
// GLPI with GLPI_USE_CSRF_CHECK disabled, so it must never be web-reachable,
// even when the plugin is deployed with `git clone` (tests/ is export-ignored
// from the release archive, but not from a clone).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

// Determine GLPI root: env var > config.php > default
$glpi_root = getenv('GLPI_ROOT') ?: '';
if (!$glpi_root) {
    $config_file = __DIR__ . '/config.php';
    if (file_exists($config_file)) {
        $glpi_root = require $config_file;
    }
}
if (!$glpi_root) {
    $glpi_root = '/var/www/html/glpi';
}

define('GLPI_ROOT', rtrim($glpi_root, '/\\'));
// GLPI_CONFIG_DIR can point elsewhere (e.g. the official glpi/glpi docker image keeps
// config under /var/glpi/config). Falls back to the classic GLPI_ROOT/config layout.
define('GLPI_CONFIG_DIR', getenv('GLPI_CONFIG_DIR') ?: GLPI_ROOT . '/config');
define('GLPI_VAR_DIR', GLPI_ROOT . '/files/_tests');
define('GLPI_CRON_DIR', GLPI_VAR_DIR . '/cron');
define('GLPI_LOG_DIR', GLPI_VAR_DIR . '/logs');
define('GLPI_DUMP_DIR', GLPI_VAR_DIR . '/dump');
define('GLPI_PICTURE_DIR', GLPI_VAR_DIR . '/pictures');
define('GLPI_TMP_DIR', GLPI_VAR_DIR . '/tmp');
define('GLPI_CACHE_DIR', GLPI_VAR_DIR . '/cache');
define('GLPI_SESSION_DIR', GLPI_VAR_DIR . '/sessions');
define('GLPI_MARKETPLACE_DIR', GLPI_ROOT . '/marketplace');
define('PLUGINS_DIRECTORIES', [
    GLPI_ROOT . '/plugins',
    GLPI_ROOT . '/marketplace',
]);

if (!file_exists(GLPI_VAR_DIR)) {
    mkdir(GLPI_VAR_DIR, 0777, true);
}
// compact() only resolves variables, so the constants have to be listed here:
// the loop below never ran and GLPI had to create the directories itself.
$test_dirs = [
    GLPI_CRON_DIR,
    GLPI_LOG_DIR,
    GLPI_DUMP_DIR,
    GLPI_PICTURE_DIR,
    GLPI_TMP_DIR,
    GLPI_CACHE_DIR,
    GLPI_SESSION_DIR,
];
foreach ($test_dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

define('GLPI_USE_CSRF_CHECK', 0);

// Indicates we are running in a test suite context (avoids session_id regeneration issues).
define('TU_USER', 'kanban-tests');

require_once GLPI_ROOT . '/inc/includes.php';

/**
 * Create test tickets for the Kanban plugin tests.
 * This function creates tickets across all GLPI statuses with varied
 * priorities, categories, and SLA deadlines.
 */
function kanban_plugin_create_test_tickets() {
    global $DB;

    $now = date('Y-m-d H:i:s');

    $sla_2h  = date('Y-m-d H:i:s', strtotime('+2 hours'));
    $sla_8h  = date('Y-m-d H:i:s', strtotime('+8 hours'));
    $sla_24h = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $sla_3d  = date('Y-m-d H:i:s', strtotime('+3 days'));
    $sla_5d  = date('Y-m-d H:i:s', strtotime('+5 days'));
    $sla_exp = date('Y-m-d H:i:s', strtotime('-1 hour'));

    $tickets = [
        ['Erro de login - Portal cliente (Novo)',       1, 5, $sla_2h],
        ['Atualizar servidor de homologação (Novo)',    1, 4, $sla_24h],
        ['Solicitação de acesso VPN',                   1, 3, $sla_5d],
        ['Falha no backup noturno - Verificar logs',    2, 6, $sla_exp],
        ['Configurar novo notebook colaborador',        2, 2, $sla_3d],
        ['Migrar banco de dados legacy',                3, 4, $sla_8h],
        ['Implementar SSO para sistema interno',        3, 3, $sla_5d],
        ['Aguardando aprovação de orçamento - Servidor', 4, 5, $sla_24h],
        ['Resposta do fornecedor pendente - Firewall',  4, 3, $sla_8h],
        ['Monitor externo substituído com sucesso',     5, 2, $sla_exp],
        ['Treinamento novo sistema finalizado',         6, 3, $sla_exp],
        ['Contrato de manutenção renovado',             6, 4, $sla_exp],
    ];

    $ticket_ids = [];
    foreach ($tickets as $i => $data) {
        $inserted = $DB->insert('glpi_tickets', [
            'name'              => $data[0],
            'content'           => 'Test ticket for Kanban plugin',
            'status'            => $data[1],
            'priority'          => $data[2],
            'urgency'           => 2,
            'impact'            => 2,
            'type'              => 1,
            'date'              => $now,
            'date_creation'     => $now,
            'date_mod'          => $now,
            'entities_id'       => 0,
            'is_deleted'        => 0,
            'itilcategories_id' => 0,
            'requesttypes_id'   => 0,
            'users_id_lastupdater' => 2,
            'users_id_recipient' => 2,
            'actiontime'        => 0,
            'begin_waiting_date'=> null,
            'time_to_resolve'   => $data[3],
        ]);
        // GLPI 10's insert() returns a boolean; the new id comes from insertId().
        // Casting the return value yielded 1 for every ticket, so all the
        // actors pointed at ticket 1 -- harmless on an empty database where the
        // fixtures start at id 1, but a silently empty board everywhere else.
        if (!$inserted) {
            throw new RuntimeException('Could not create the test ticket: ' . $data[0]);
        }
        $id = (int)$DB->insertId();
        $ticket_ids[] = $id;

        echo sprintf("Ticket %2d - [%s] '%s' (ID=%d)\n", $i + 1, getStatusName($data[1]), $data[0], $id);
    }

    // Assign all created tickets to user 2 as technician (ASSIGN)
    foreach ($ticket_ids as $tid) {
        $DB->insert('glpi_tickets_users', [
            'tickets_id' => $tid,
            'users_id'   => 2,
            'type'       => CommonITILActor::ASSIGN,
        ]);
    }

    // Assign tickets 1, 4, 7 (first, fourth and seventh created) to user 2 as requester (REQUESTER)
    foreach ([0, 3, 6] as $idx) {
        $DB->insert('glpi_tickets_users', [
            'tickets_id' => $ticket_ids[$idx],
            'users_id'   => 2,
            'type'       => CommonITILActor::REQUESTER,
        ]);
    }

    echo "\nTotal: " . count($tickets) . " tickets created across all statuses.\n";

    return $ticket_ids;
}

/**
 * Get human-readable status name
 */
function getStatusName(int $status): string {
    return [
        1 => 'New',
        2 => 'Assigned',
        3 => 'Planned',
        4 => 'Waiting',
        5 => 'Solved',
        6 => 'Closed',
    ][$status] ?? "Status $status";
}
