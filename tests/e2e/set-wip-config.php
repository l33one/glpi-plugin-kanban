<?php
/**
 * Update the plugin work-in-progress configuration straight in the database.
 *
 * The E2E suite needs a known WIP configuration to assert the per-column
 * limits returned by get_wip_state. Going through the configuration page would
 * require an administrator session and a full form POST, so the fixture writes
 * the same values PluginKanbanConfig::save() would store (the plugin serializes
 * arrays with exportArrayToDB, i.e. json_encode).
 *
 * Variables de ambiente:
 *   KANBAN_DB_HOST / KANBAN_DB_USER / KANBAN_DB_PASS / KANBAN_DB_NAME
 *   KANBAN_WIP_LIMIT       global limit (0 = no limit)
 *   KANBAN_WIP_LIMITS_B64  base64 of a JSON object status id => limit
 *                          (0 = unlimited for that column)
 */

// CLI-only defence in depth: never web-reachable.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$host   = (string)getenv('KANBAN_DB_HOST');
$user   = (string)getenv('KANBAN_DB_USER');
$passdb = (string)getenv('KANBAN_DB_PASS');
$name   = (string)getenv('KANBAN_DB_NAME');
if ($host === '' || $user === '' || $passdb === '' || $name === '') {
    fwrite(STDERR, "Set KANBAN_DB_HOST, KANBAN_DB_USER, KANBAN_DB_PASS and KANBAN_DB_NAME before running.\n");
    exit(1);
}

$global = max(0, min(10000, (int)getenv('KANBAN_WIP_LIMIT')));

$raw = (string)getenv('KANBAN_WIP_LIMITS_B64');
$decoded = [];
if ($raw !== '') {
    // The value arrives base64-encoded because docker compose exec passes the
    // argument through a shell, which would strip the quotes of a JSON object.
    $json = base64_decode($raw, true);
    if ($json === false) {
        fwrite(STDERR, "KANBAN_WIP_LIMITS_B64 is not valid base64.\n");
        exit(1);
    }
    $decoded = json_decode($json, true);
}
if (!is_array($decoded)) {
    fwrite(STDERR, "KANBAN_WIP_LIMITS_B64 must encode a JSON object of status id => limit.\n");
    exit(1);
}

$limits = [];
foreach ($decoded as $status_id => $value) {
    $status_id = (int)$status_id;
    if ($status_id > 0) {
        $limits[$status_id] = max(0, min(10000, (int)$value));
    }
}

$mysqli = new mysqli($host, $user, $passdb, $name);
if ($mysqli->connect_errno) {
    fwrite(STDERR, 'Database connection failed: ' . $mysqli->connect_error . "\n");
    exit(1);
}

$stmt = $mysqli->prepare(
    'INSERT INTO glpi_configs (context, name, value) VALUES (?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE value = VALUES(value)'
);
if ($stmt === false) {
    fwrite(STDERR, 'Could not prepare the config update: ' . $mysqli->error . "\n");
    exit(1);
}

$context = 'plugin:kanban';
$values = [
    'wip_limit'  => (string)$global,
    'wip_limits' => json_encode($limits),
];
foreach ($values as $key => $value) {
    $stmt->bind_param('sss', $context, $key, $value);
    if (!$stmt->execute()) {
        fwrite(STDERR, 'Could not update ' . $key . ': ' . $stmt->error . "\n");
        exit(1);
    }
}
$stmt->close();
$mysqli->close();

echo json_encode(['wip_limit' => $global, 'wip_limits' => $limits]) . PHP_EOL;
