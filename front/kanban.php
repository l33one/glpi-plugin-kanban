<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Kanban Plugin
 * -------------------------------------------------------------------------
 *
 * @package   Plugin Kanban
 * @author    Leewan Meneses
 * @license   GPL-2.0+
 * @since     2026
 *
 * -------------------------------------------------------------------------
 */

include ('../../../inc/includes.php');

// Ensure user is logged in
Session::checkLoginUser();

// Check rights to view tickets
if (!Ticket::canView()) {
   Html::displayRightError();
}

$kanban = new PluginKanbanKanban();

// Handle AJAX actions
if (isset($_POST['action']) || isset($_GET['action'])) {
   $action = $_POST['action'] ?? $_GET['action'];

   // CSRF validation for modifying actions
   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      Session::checkCSRF($_POST);
   }

   switch ($action) {
      case 'get_tickets':
         $filters = [
            'technician' => $_GET['technician'] ?? null,
            'requester'  => $_GET['requester'] ?? null,
            'group'      => $_GET['group'] ?? null,
         ];
         $sort = [
            'by'    => $_GET['sort_by'] ?? 'date',
            'order' => $_GET['sort_order'] ?? 'DESC'
         ];

          $tickets = PluginKanbanKanban::getTicketsForKanban($filters, $sort);

          header("Content-Type: application/json; charset=UTF-8");
          echo json_encode($tickets);
          exit;

       case 'update_ticket_status':
          $ticket_id  = (int)($_POST['ticket_id'] ?? 0);
          $new_status = (int)($_POST['status'] ?? 0);

          if ($ticket_id > 0 && $new_status > 0) {
             $ticket = new Ticket();
             if ($ticket->getFromDB($ticket_id)) {
                if ($ticket->canUpdateItem()) {
                   $updated = $ticket->update([
                      'id'     => $ticket_id,
                      'status' => $new_status
                   ]);

                   header("Content-Type: application/json; charset=UTF-8");
                   echo json_encode(['success' => $updated]);
                   exit;
                } else {
                   http_response_code(403);
                   echo json_encode(['success' => false, 'error' => 'Permission Denied']);
                   exit;
                }
             }
          }
          http_response_code(400);
          echo json_encode(['success' => false, 'error' => 'Bad Request']);
          exit;
   }
}

// Render Page Header
Html::header(
   __('Kanban', 'kanban'),
   $_SERVER['PHP_SELF'],
   "assistance",
   "plugin_kanban_menu"
);

// Prepare localized statuses for Kanban columns
$glpi_statuses = Ticket::getAllStatusArray();
$status_colors = [
   Ticket::INCOMING => 'primary',
   Ticket::ASSIGNED => 'info',
   Ticket::PLANNED  => 'warning',
   Ticket::WAITING  => 'secondary',
   Ticket::SOLVED   => 'success',
   Ticket::CLOSED   => 'dark'
];
$kanban_statuses = [];
foreach ($glpi_statuses as $id => $name) {
   $kanban_statuses[$id] = [
      'name'  => $name,
      'color' => $status_colors[$id] ?? 'secondary'
   ];
}
echo "<script>var KANBAN_STATUSES = " . json_encode($kanban_statuses) . ";</script>";
echo "<script>var KANBAN_GLPI_ROOT = " . json_encode($CFG_GLPI['root_doc']) . ";</script>";


echo Html::script($CFG_GLPI['root_doc'] . "/plugins/kanban/public/js/kanban.js?v=" . time());
echo Html::css($CFG_GLPI['root_doc'] . "/plugins/kanban/public/css/kanban.css?v=" . time());

// Render the template container
// In next steps, templates/kanban.html.twig will be loaded.
// Here we output the base layout structure where JavaScript will load the data.
?>
<div class="kanban-page-wrapper container-fluid py-4">
   <!-- Header and Filters -->
   <div class="row mb-4 align-items-center">
      <div class="col-md-4 d-flex align-items-center gap-3">
         <h1 class="h2 text-primary m-0"><i class="ti ti-layout-kanban me-2"></i><?php echo __('Kanban Board', 'kanban'); ?></h1>
         <!-- Button: Open native GLPI ticket list -->
         <a href="<?php echo $CFG_GLPI['root_doc']; ?>/front/ticket.php"
            class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"
            title="<?php echo __('Open in GLPI', 'kanban'); ?>">
            <i class="ti ti-external-link"></i>
            <?php echo __('Open in GLPI', 'kanban'); ?>
         </a>
         <!-- Button: Create a new ticket -->
         <a href="<?php echo $CFG_GLPI['root_doc']; ?>/front/ticket.form.php"
            class="btn btn-sm btn-primary d-flex align-items-center gap-1"
            title="<?php echo __('New Ticket', 'kanban'); ?>">
            <i class="ti ti-plus"></i>
            <?php echo __('New Ticket', 'kanban'); ?>
         </a>
         <!-- Button: Refresh Kanban -->
         <button type="button" class="btn btn-sm btn-outline-info d-flex align-items-center gap-1" id="kanban-refresh-btn" title="<?php echo __('Refresh', 'kanban'); ?>">
            <i class="ti ti-reload"></i>
         </button>
      </div>
      <div class="col-md-8">
         <form id="kanban-filter-form" class="row g-2 justify-content-end">
            <!-- CSRF Token -->
            <input type="hidden" name="_glpi_csrf_token" value="<?php echo Session::getNewCSRFToken(); ?>">

            <!-- Technician Filter -->
            <div class="col-auto">
               <label class="visually-hidden" for="filter-technician"><?php echo __('Technician', 'kanban'); ?></label>
               <select class="form-select select2-simple" id="filter-technician" name="technician" style="min-width: 180px;">
                  <option value=""><?php echo __('All Technicians', 'kanban'); ?></option>
                  <?php
                     // Get active users who can be assigned to tickets
                     global $DB;
                     $iterator = $DB->request([
                        'SELECT' => ['id', 'realname', 'firstname'],
                        'FROM'   => 'glpi_users',
                        'WHERE'  => ['is_deleted' => 0],
                        'ORDER'  => 'realname ASC',
                        'LIMIT'  => 100
                     ]);
                     $users_array = [];
                     foreach ($iterator as $user) {
                         $name = getUserName($user['id']);
                         $users_array[] = ['id' => $user['id'], 'name' => $name];
                         echo "<option value='{$user['id']}'>{$name}</option>";
                     }
                   ?>
                </select>
             </div>

             <!-- Requester Filter -->
             <div class="col-auto">
                <label class="visually-hidden" for="filter-requester"><?php echo __('Requester', 'kanban'); ?></label>
                <select class="form-select select2-simple" id="filter-requester" name="requester" style="min-width: 180px;">
                   <option value=""><?php echo __('All Requesters', 'kanban'); ?></option>
                   <?php
                      foreach ($users_array as $user) {
                         echo "<option value='{$user['id']}'>{$user['name']}</option>";
                      }
                  ?>
               </select>
            </div>

            <!-- Group Filter -->
            <div class="col-auto">
               <label class="visually-hidden" for="filter-group"><?php echo __('Group', 'kanban'); ?></label>
               <select class="form-select select2-simple" id="filter-group" name="group" style="min-width: 180px;">
                  <option value=""><?php echo __('All Groups', 'kanban'); ?></option>
                  <?php
                     $group_iterator = $DB->request([
                        'SELECT' => ['id', 'name'],
                        'FROM'   => 'glpi_groups',
                        'ORDER'  => 'name ASC',
                        'LIMIT'  => 100
                     ]);
                     foreach ($group_iterator as $group) {
                        echo "<option value='{$group['id']}'>{$group['name']}</option>";
                     }
                  ?>
               </select>
            </div>

            <!-- Sorting Select -->
            <div class="col-auto">
               <select class="form-select" id="kanban-sort-by" name="sort_by">
                  <option value="date" selected><?php echo __('Date Open', 'kanban'); ?></option>
                  <option value="priority"><?php echo __('Priority', 'kanban'); ?></option>
                  <option value="status_duration"><?php echo __('Status Duration', 'kanban'); ?></option>
               </select>
            </div>

            <div class="col-auto">
               <select class="form-select" id="kanban-sort-order" name="sort_order">
                  <option value="DESC" selected><?php echo __('Descending', 'kanban'); ?></option>
                  <option value="ASC"><?php echo __('Ascending', 'kanban'); ?></option>
               </select>
            </div>
         </form>
      </div>
   </div>

   <!-- Kanban Board Container -->
   <div id="kanban-board" class="kanban-board-container row flex-nowrap overflow-auto py-2">
      <!-- Dynamically filled by JavaScript -->
   </div>
</div>
<?php

Html::footer();
