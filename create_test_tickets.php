<?php
/**
 * Create test tickets directly via MySQL
 * Execute via: docker exec glpi-app php /var/www/html/glpi/plugins/kanban/create_test_tickets.php
 */

// Connect directly to MariaDB
$host = 'mariadb';
$user = 'glpi';
$pass = 'glpi_password';
$dbname = 'glpi';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    // Try using docker service name
    $conn = new mysqli('glpi-db', $user, $pass, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error . "\n");
    }
}

echo "Connected to MariaDB successfully.\n\n";

$now = date('Y-m-d H:i:s');

$tickets = [
    ['Ticket Kanban - Alpha (Novo)',          1],
    ['Ticket Kanban - Beta (Em Atendimento)', 2],
    ['Ticket Kanban - Gamma (Planejado)',      3],
];

foreach ($tickets as $i => $data) {
    $name   = $conn->real_escape_string($data[0]);
    $status = (int)$data[1];

    $sql = "INSERT INTO glpi_tickets 
        (name, content, status, priority, urgency, impact, type, date, date_creation, date_mod, 
         entities_id, is_deleted, itilcategories_id, requesttypes_id, users_id_lastupdater, users_id_recipient, actiontime, begin_waiting_date)
        VALUES 
        ('$name', 'Chamado de teste para o plugin Kanban.', $status, 3, 2, 2, 1, '$now', '$now', '$now', 
         0, 0, 0, 0, 2, 2, 0, NULL)";

    if ($conn->query($sql)) {
        echo "Ticket " . ($i + 1) . " - '$name' (status=$status): CRIADO (ID=" . $conn->insert_id . ")\n";
    } else {
        echo "Ticket " . ($i + 1) . " - FALHOU: " . $conn->error . "\n";
    }
}

echo "\nPronto! Acesse: http://localhost:8088/glpi/plugins/kanban/front/kanban.php\n";
$conn->close();
