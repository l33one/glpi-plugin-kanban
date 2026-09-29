<?php

/**
 * Seed (or reset) the browser-test scenario (KB-IMPROVE tickets).
 *
 * Recreates the tickets used by the puppeteer test
 * (test_improvements_browser.js) using the CURRENT kb_* user ids, so the
 * scenario keeps working even after tests/e2e/seeder.php recreates those
 * users with different ids.
 *
 * Usage (inside the GLPI container):
 *   php /var/www/glpi/plugins/kanban/tests/e2e/seed_browser.php
 */

// CLI-only defence in depth: never web-reachable, even if this file is copied
// into a served directory that bypasses the plugin's release packaging.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$db_host = (string)getenv('KANBAN_DB_HOST');
$db_user = (string)getenv('KANBAN_DB_USER');
$db_pass = (string)getenv('KANBAN_DB_PASS');
$db_name = (string)getenv('KANBAN_DB_NAME');
if ($db_host === '' || $db_user === '' || $db_pass === '' || $db_name === '') {
    fwrite(STDERR, "Set KANBAN_DB_HOST, KANBAN_DB_USER, KANBAN_DB_PASS and KANBAN_DB_NAME before running.\n");
    exit(1);
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    fwrite(STDERR, 'DB connection failed: ' . $conn->connect_error . "\n");
    exit(1);
}

$marker = 'KB IMPROVE';

function run(mysqli $c, string $sql, string $label)
{
    if (!$c->query($sql)) {
        fwrite(STDERR, "SQL ERROR ($label): " . $c->error . "\n  -> $sql\n");
        exit(1);
    }
}

// --------------------------------------------------------------- LIMPAR
$conn->query("DELETE gt FROM glpi_groups_tickets gt
              JOIN glpi_tickets t ON t.id = gt.tickets_id
              WHERE t.content LIKE '%" . $conn->real_escape_string($marker) . "%'");
$conn->query("DELETE fu FROM glpi_itilfollowups fu
              JOIN glpi_tickets t ON t.id = fu.items_id AND fu.itemtype='Ticket'
              WHERE t.content LIKE '%" . $conn->real_escape_string($marker) . "%'");
$conn->query("DELETE tu FROM glpi_tickets_users tu
              JOIN glpi_tickets t ON t.id = tu.tickets_id
              WHERE t.content LIKE '%" . $conn->real_escape_string($marker) . "%'");
$conn->query("DELETE FROM glpi_tickets WHERE content LIKE '%" . $conn->real_escape_string($marker) . "%'");

// --------------------------------------- PERFIL E USUÁRIOS BASE (9701/9751)
run($conn, "INSERT IGNORE INTO glpi_profiles (id, name, interface) VALUES (9701, 'KB Test Tech', 'central')", 'profile 9701');
// ticket = READ(1) | UPDATE(2) | STEAL(16384): o STEAL é exigido por
// Ticket::canAssignToMe(), que o plugin reproduz na ação assign_to_me.
run($conn, "INSERT IGNORE INTO glpi_profilerights (profiles_id, name, rights) VALUES (9701, 'ticket', 16387)", 'profile rights ticket');
run($conn, "INSERT IGNORE INTO glpi_profilerights (profiles_id, name, rights) VALUES (9701, 'plugin_kanban', 1)", 'profile rights kanban');
run($conn, "INSERT IGNORE INTO glpi_groups (id, entities_id, is_recursive, name, date_mod, completename, level, ancestors_cache, sons_cache)
            VALUES (9751, 0, 1, 'KB Pai', NOW(), 'KB Pai', 1, '[]', '{}')", 'group 9751');
run($conn, "INSERT IGNORE INTO glpi_itilcategories (id, entities_id, is_recursive, name, completename, level, ancestors_cache, sons_cache)
            VALUES (9701, 0, 1, 'KB Categoria Busca', 'KB Categoria Busca', 1, '[]', '{}')", 'category 9701');

// No default password on purpose: the accounts created below must never be
// provisioned with a value that is hard-coded in the repository.
$test_pass = (string)getenv('KANBAN_TEST_PASS');
if ($test_pass === '') {
    fwrite(STDERR, "Set KANBAN_TEST_PASS before running: the seeded accounts are never created with a built-in password.\n");
    exit(1);
}

$hash = password_hash($test_pass, PASSWORD_DEFAULT);
foreach (['kb_member' => 9751, 'kb_colleague' => 9751] as $uname => $gid) {
    run($conn, "INSERT IGNORE INTO glpi_users (name, password, authtype, auths_id, entities_id, is_active, is_deleted, realname, firstname)
                VALUES ('$uname', '$hash', 1, 0, 0, 1, 0, 'KB $uname', '$uname')", "user $uname");
    $uid = (int)$conn->query("SELECT id FROM glpi_users WHERE name='$uname'")->fetch_row()[0];
    $conn->query("INSERT IGNORE INTO glpi_groups_users (users_id, groups_id, is_dynamic, is_manager, is_userdelegate)
                  VALUES ($uid, $gid, 0, 0, 0)");
    $conn->query("INSERT IGNORE INTO glpi_profiles_users (users_id, profiles_id, entities_id, is_recursive, is_dynamic, is_default_profile)
                  VALUES ($uid, 9701, 0, 1, 0, 1)");
}

$kb_member_id    = (int)$conn->query("SELECT id FROM glpi_users WHERE name='kb_member'")->fetch_row()[0];
$kb_colleague_id = (int)$conn->query("SELECT id FROM glpi_users WHERE name='kb_colleague'")->fetch_row()[0];

// ------------------------------------------------------------ CHAMADOS
$now      = date('Y-m-d H:i:s');
$tomorrow = date('Y-m-d H:i:s', time() + 86400);

function kb_ticket(mysqli $c, int $id, string $title, string $content, int $priority, string $ttr): int
{
    $now = date('Y-m-d H:i:s');
    $sql = "INSERT INTO glpi_tickets
        (id, name, content, status, priority, urgency, impact, type, date, date_creation, date_mod,
         entities_id, is_deleted, itilcategories_id, requesttypes_id, users_id_lastupdater,
         users_id_recipient, actiontime, begin_waiting_date, solvedate, time_to_resolve)
        VALUES ($id, '" . $c->real_escape_string($title) . "', '" . $c->real_escape_string($content) . "',
                1, $priority, 3, 3, 1, '$now', '$now', '$now', 0, 0, 9701, 0, 2, 2, 0, NULL, NULL, '$ttr')";
    run($c, $sql, 'ticket ' . $title);
    return $id;
}

$t_search  = kb_ticket($conn, 277, 'KB-SEARCH-ALPHA', "$marker unicornio-do-teste busca por descricao", 3, 'NULL');
$t_crit    = kb_ticket($conn, 278, 'KB-CRITICAL', "$marker urgente critico hoje", 5, 'NULL');
$t_sla     = kb_ticket($conn, 279, 'KB-SLA', "$marker ticket com sla e acompanhamento", 3, "'$tomorrow'");

// Grupo 9751 (ASSIGN) para todos -> visíveis a kb_member
foreach ([$t_search, $t_crit, $t_sla] as $tid) {
    run($conn, "INSERT INTO glpi_groups_tickets (tickets_id, groups_id, type) VALUES ($tid, 9751, 2)", "gt $tid");
}

// KB-SLA atribuído a kb_member (técnico)
run($conn, "INSERT INTO glpi_tickets_users (tickets_id, users_id, type) VALUES ($t_sla, $kb_member_id, 2)", 'tu sla');

// Followup no KB-SLA (autor: kb_colleague)
run($conn, "INSERT INTO glpi_itilfollowups (items_id, itemtype, users_id, content, is_private, date, requesttypes_id)
            VALUES ($t_sla, 'Ticket', $kb_colleague_id, 'Acompanhamento inicial de teste', 0, '$now', 1)", 'followup sla');

echo "Cenario KB IMPROVE pronto (kb_member id=$kb_member_id, kb_colleague id=$kb_colleague_id).\n";
$conn->close();