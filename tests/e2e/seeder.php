<?php
/**
 * Seed de um cenário de visibilidade para o plugin Kanban.
 *
 * Cria usuários, grupos (com subgrupos), gerentes, perfis com/sem o direito
 * do plugin e chamados com diferentes atribuições, para validar as regras de
 * visibilidade do quadro Kanban:
 *   - perfil precisa do direito "plugin_kanban" (leitura) para acessar;
 *   - o usuário vê apenas chamados nos quais participa (ASSIGN) ou cujos
 *     grupos (subgrupos incluídos) estão atribuídos;
 *   - gerente de grupo vê todos os chamados do grupo, inclusive os delegados
 *     a outros membros;
 *   - observador nunca é visível.
 *
 * Variáveis de ambiente:
 *   KANBAN_DB_HOST / KANBAN_DB_USER / KANBAN_DB_PASS / KANBAN_DB_NAME
 *   KANBAN_CLEANUP=1  remove apenas o cenário (idempotente).
 */

// CLI-only defence in depth: never web-reachable, even if this file is copied
// into a served directory that bypasses the plugin's release packaging.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$marker  = 'KB E2E GLPI11';

// No default password on purpose: the accounts created below must never be
// provisioned with a value that is hard-coded in the repository.
$pass    = (string)getenv('KANBAN_TEST_PASS');
$host = (string)getenv('KANBAN_DB_HOST');
$user = (string)getenv('KANBAN_DB_USER');
$passdb = (string)getenv('KANBAN_DB_PASS');
$name = (string)getenv('KANBAN_DB_NAME');
if ($host === '' || $user === '' || $passdb === '' || $name === '') {
    fwrite(STDERR, "Set KANBAN_DB_HOST, KANBAN_DB_USER, KANBAN_DB_PASS and KANBAN_DB_NAME before running.\n");
    exit(1);
}
if ($pass === '') {
    fwrite(STDERR, "Set KANBAN_TEST_PASS before running: the seeded accounts are never created with a built-in password.\n");
    exit(1);
}
$hash = password_hash($pass, PASSWORD_DEFAULT);

$conn = new mysqli($host, $user, $passdb, $name);
if ($conn->connect_error) {
    fwrite(STDERR, "Erro de conexao: {$conn->connect_error}\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

$cleanup_only = getenv('KANBAN_CLEANUP') === '1';

function run(mysqli $c, string $sql, string $label) {
    if (!$c->query($sql)) {
        fwrite(STDERR, "SQL error [$label]: {$c->error}\nSQL: $sql\n");
        exit(1);
    }
}

// ------------------------------------------------------------------ LIMPAR
$conn->query("DELETE gt FROM glpi_groups_tickets gt
              JOIN glpi_tickets t ON t.id = gt.tickets_id
              WHERE t.content LIKE '%" . $conn->real_escape_string($marker) . "%'");
$conn->query("DELETE tu FROM glpi_tickets_users tu
              JOIN glpi_tickets t ON t.id = tu.tickets_id
              WHERE t.content LIKE '%" . $conn->real_escape_string($marker) . "%'");
$conn->query("DELETE FROM glpi_tickets WHERE content LIKE '%" . $conn->real_escape_string($marker) . "%'");
$conn->query("DELETE FROM glpi_groups_users WHERE users_id IN (
   SELECT id FROM glpi_users WHERE name IN ('kb_manager','kb_member','kb_child','kb_outsider','kb_noright','kb_extuser','kb_colleague'))");
$conn->query("DELETE FROM glpi_profiles_users WHERE users_id IN (
   SELECT id FROM glpi_users WHERE name IN ('kb_manager','kb_member','kb_child','kb_outsider','kb_noright','kb_extuser','kb_colleague'))");
$conn->query("DELETE FROM glpi_users WHERE name IN ('kb_manager','kb_member','kb_child','kb_outsider','kb_noright','kb_extuser','kb_colleague')");
$conn->query("DELETE FROM glpi_groups_users WHERE groups_id IN (9751, 9752, 9753)");
$conn->query("DELETE FROM glpi_groups WHERE id IN (9751, 9752, 9753)");
$conn->query("DELETE FROM glpi_profilerights WHERE profiles_id IN (9701, 9702)");
$conn->query("DELETE FROM glpi_profiles WHERE id IN (9701, 9702)");

if ($cleanup_only) {
    echo "Cenario KB E2E removido.\n";
    $conn->close();
    exit(0);
}

// ------------------------------------------------------- PERFIS (9701/9702)
run($conn, "INSERT INTO glpi_profiles (id, name, interface) VALUES (9701, 'KB Test Tech', 'central')", 'profile 9701');
run($conn, "INSERT INTO glpi_profiles (id, name, interface) VALUES (9702, 'KB Test NoRight', 'central')", 'profile 9702');
foreach ([
    // 9701: técnico do quadro — precisa ler e atualizar chamados para as
    // ações rápidas (atribuir a mim / mudar prioridade) funcionarem.
    [9701, 'entity', 33], [9701, 'ticket', 3], [9701, 'plugin_kanban', 1],
    [9702, 'entity', 33], [9702, 'ticket', 1], [9702, 'plugin_kanban', 0],
] as [$pid, $rname, $rval]) {
    run($conn, "INSERT INTO glpi_profilerights (profiles_id, name, rights)
                VALUES ($pid, '$rname', $rval)", "pright $pid/$rname");
}

// ------------------------------------------------------------ GRUPOS
run($conn, "INSERT INTO glpi_groups
   (id, entities_id, is_recursive, name, groups_id, completename, level, ancestors_cache, sons_cache)
   VALUES (9751, 0, 1, 'KB Pai', 0, 'KB Pai', 1, '[]', '{\"9751\":9751,\"9752\":9752}')", 'group 9751');
run($conn, "INSERT INTO glpi_groups
   (id, entities_id, is_recursive, name, groups_id, completename, level, ancestors_cache, sons_cache)
   VALUES (9752, 0, 1, 'KB Sub', 9751, 'KB Pai > KB Sub', 2, '[\"9751\"]', '{\"9752\":9752}')", 'group 9752');
run($conn, "INSERT INTO glpi_groups
   (id, entities_id, is_recursive, name, groups_id, completename, level, ancestors_cache, sons_cache)
   VALUES (9753, 0, 1, 'KB Outro', 0, 'KB Outro', 1, '[]', '{\"9753\":9753}')", 'group 9753');

// ------------------------------------------------------------ USUÁRIOS
$users = [
    ['kb_manager', 9751, 1],   // membro + gerente do grupo pai
    ['kb_member',  9751, 0],   // membro do grupo pai
    ['kb_child',   9752, 0],   // membro do subgrupo
    ['kb_outsider', 0, 0],     // sem grupos
    ['kb_noright', 9751, 0],   // gerente veria, mas perfil sem direito
    ['kb_extuser', 9753, 0],   // membro de grupo "fora"
    ['kb_colleague', 9751, 0], // colega de grupo (recebe delegação individual)
];
foreach ($users as [$uname, $gid, $is_mgr]) {
    run($conn, "INSERT INTO glpi_users
        (name, password, authtype, auths_id, entities_id, is_active, is_deleted, realname, firstname)
        VALUES ('$uname', '$hash', 1, 0, 0, 1, 0, 'KB $uname', '$uname')", "user $uname");
    if ($gid > 0) {
        run($conn, "INSERT INTO glpi_groups_users (users_id, groups_id, is_dynamic, is_manager, is_userdelegate)
                    VALUES ((SELECT id FROM glpi_users WHERE name='$uname'), $gid, 0, $is_mgr, 0)", "group user $uname");
    }
    $profile = in_array($uname, ['kb_noright'], true) ? 9702 : 9701;
    run($conn, "INSERT INTO glpi_profiles_users (users_id, profiles_id, entities_id, is_recursive, is_dynamic, is_default_profile)
                VALUES ((SELECT id FROM glpi_users WHERE name='$uname'), $profile, 0, 1, 0, 1)", "profile user $uname");
}

// ------------------------------------------------------------ CHAMADOS
function kb_ticket(mysqli $c, string $marker, string $title, int $status) {
    $now = date('Y-m-d H:i:s');
    $sql = "INSERT INTO glpi_tickets
        (name, content, status, priority, urgency, impact, type, date, date_creation, date_mod,
         entities_id, is_deleted, itilcategories_id, requesttypes_id, users_id_lastupdater,
         users_id_recipient, actiontime, begin_waiting_date, solvedate, time_to_resolve)
        VALUES ('" . $c->real_escape_string($title) . "', '$marker', $status, 3, 2, 2, 1,
                '$now', '$now', '$now', 0, 0, 0, 0, 2, 2, 0, NULL, NULL, NULL)";
    run($c, $sql, 'ticket ' . $title);
    return (int)$c->insert_id;
}
$uid = function (mysqli $c, string $name) use (&$uid) {
    $r = $c->query("SELECT id FROM glpi_users WHERE name='$name'");
    return (int)$r->fetch_row()[0];
};

$t_grp    = kb_ticket($conn, $marker, 'KB-E2E grupo-pai', 1);          // grupo 9751, sem técnico
$t_deleg  = kb_ticket($conn, $marker, 'KB-E2E delegado-membro', 1);    // técnico: kb_colleague
$t_sub    = kb_ticket($conn, $marker, 'KB-E2E subgrupo', 1);           // grupo 9752
$t_outside= kb_ticket($conn, $marker, 'KB-E2E fora', 1);               // técnico: kb_outsider
$t_other  = kb_ticket($conn, $marker, 'KB-E2E outro-grupo', 1);        // grupo 9753
$t_obs    = kb_ticket($conn, $marker, 'KB-E2E observador', 1);         // grupo 9751 como OBSERVER
$t_ext    = kb_ticket($conn, $marker, 'KB-E2E estrangeiro', 1);        // técnico: kb_extuser (grupo 9753)

run($conn, "INSERT INTO glpi_groups_tickets (tickets_id, groups_id, type) VALUES ($t_grp, 9751, 2)", 'gt grp');
run($conn, "INSERT INTO glpi_groups_tickets (tickets_id, groups_id, type) VALUES ($t_sub, 9752, 2)", 'gt sub');
run($conn, "INSERT INTO glpi_groups_tickets (tickets_id, groups_id, type) VALUES ($t_other, 9753, 2)", 'gt other');
run($conn, "INSERT INTO glpi_groups_tickets (tickets_id, groups_id, type) VALUES ($t_obs, 9751, 1)", 'gt obs'); // OBSERVER
run($conn, "INSERT INTO glpi_tickets_users (tickets_id, users_id, type)
            VALUES ($t_deleg, " . $uid($conn, 'kb_colleague') . ", 2)", 'tu deleg');     // ASSIGN técnico
run($conn, "INSERT INTO glpi_tickets_users (tickets_id, users_id, type)
            VALUES ($t_outside, " . $uid($conn, 'kb_outsider') . ", 2)", 'tu fora');
run($conn, "INSERT INTO glpi_tickets_users (tickets_id, users_id, type)
            VALUES ($t_ext, " . $uid($conn, 'kb_extuser') . ", 2)", 'tu ext');

// Solicitantes distintos por chamado: o filtro de solicitantes só pode listar quem
// aparece em chamados visíveis a quem está olhando o quadro.
$requesters = [
    'grupo-pai'       => [$t_grp,     'kb_member'],
    'delegado-membro' => [$t_deleg,   'kb_colleague'],
    'subgrupo'        => [$t_sub,     'kb_child'],
    'fora'            => [$t_outside, 'kb_noright'],
    'outro-grupo'     => [$t_other,   'kb_extuser'],
    'observador'      => [$t_obs,     'kb_outsider'],
    'estrangeiro'     => [$t_ext,     'kb_noright'],
];
foreach ($requesters as $label => [$tid, $uname]) {
    run($conn, "INSERT INTO glpi_tickets_users (tickets_id, users_id, type)
                VALUES ($tid, " . $uid($conn, $uname) . ", 1)", "solicitante $label ($uname)");
}

echo "====================================================\n";
echo "Cenario criado (senha dos usuários kb_*: $pass)\n";
echo "  Perfil 9701 'KB Test Tech'  (plugin_kanban = 1)\n";
echo "  Perfil 9702 'KB Test NoRight' (plugin_kanban = 0)\n";
echo "  Grupo 9751 'KB Pai' -> subgrupo 9752 'KB Sub'\n";
echo "  Grupo 9753 'KB Outro' (fora do cenário)\n";
echo "  Tickets (marker: $marker):\n";
echo "    grupo-pai  (id=$t_grp)  grupo 9751 ASSIGN, sem técnico\n";
echo "    delegado-membro (id=$t_deleg)  técnico=kb_colleague\n";
echo "    subgrupo  (id=$t_sub)  grupo 9752 ASSIGN\n";
echo "    fora      (id=$t_outside)  técnico=kb_outsider\n";
echo "    outro-grupo (id=$t_other)  grupo 9753 ASSIGN\n";
echo "    observador (id=$t_obs)  grupo 9751 OBSERVER (invisível)\n";
echo "    estrangeiro (id=$t_ext)  técnico=kb_extuser (grupo 9753)\n";
$conn->close();