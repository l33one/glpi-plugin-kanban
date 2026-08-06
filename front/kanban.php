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

// Check rights to view the Kanban page and tickets
if (!PluginKanbanKanban::canView() || !Ticket::canView()) {
   Html::displayRightError();
}

// Handle AJAX actions
if (isset($_POST['action']) || isset($_GET['action'])) {
    $action = $_POST['action'] ?? $_GET['action'];

switch ($action) {
       case 'get_tickets':
          $filters = [
             'technician' => isset($_GET['technician']) ? (int)$_GET['technician'] : null,
             'requester'  => isset($_GET['requester']) ? (int)$_GET['requester'] : null,
             'group'      => isset($_GET['group']) ? (int)$_GET['group'] : null,
             'ticket_id'  => isset($_GET['ticket_id']) ? $_GET['ticket_id'] : null,
          ];
          $sort = [
             'by'    => $_GET['sort_by'] ?? 'date',
             'order' => $_GET['sort_order'] ?? 'DESC'
          ];

          $tickets = PluginKanbanKanban::getTicketsForKanban($filters, $sort);

          header("Content-Type: application/json; charset=UTF-8");
          echo json_encode($tickets);
          exit;

       case 'get_ticket_detail':
          $ticket_id = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
          $detail = PluginKanbanKanban::getTicketDetail($ticket_id);

          header("Content-Type: application/json; charset=UTF-8");
          echo json_encode($detail);
          exit;

       case 'get_filter_data':
          header("Content-Type: application/json; charset=UTF-8");
          echo json_encode([
             'technicians' => PluginKanbanKanban::getTechniciansForFilter(),
             'requesters'  => PluginKanbanKanban::getRequestersForFilter(),
             'groups'      => PluginKanbanKanban::getGroupsForFilter()
          ]);
          exit;

       case 'get_group_technicians':
          $group_id = isset($_GET['group']) ? (int)$_GET['group'] : 0;
          $technicians = $group_id > 0
             ? PluginKanbanKanban::getTechniciansForGroup($group_id)
             : PluginKanbanKanban::getTechniciansForFilter();

          header("Content-Type: application/json; charset=UTF-8");
          echo json_encode(['technicians' => $technicians]);
          exit;

       case 'update_ticket_status':
          Session::checkCSRF($_POST);

          $ticket_id  = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
          $new_status = isset($_POST['status']) ? (int)$_POST['status'] : 0;

          if ($ticket_id > 0 && $new_status > 0) {
             $ticket = new Ticket();
             if ($ticket->getFromDB($ticket_id)) {
                if ($ticket->canUpdateItem()) {
                   $ticket_entities = $ticket->getEntities();
                   if (!in_array($_SESSION['glpiactive_entity'], $ticket_entities)
                       && !in_array(0, $ticket_entities)) {
                      http_response_code(403);
                      echo json_encode(['success' => false, 'error' => 'Entity mismatch']);
                      exit;
                   }
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
   "helpdesk",
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

// Get filter data from model
$technicians = PluginKanbanKanban::getTechniciansForFilter();
$requesters  = PluginKanbanKanban::getRequestersForFilter();
$groups      = PluginKanbanKanban::getGroupsForFilter();

// Inject JS variables
echo "<script>var KANBAN_STATUSES = " . json_encode($kanban_statuses) . ";</script>";
echo "<script>var KANBAN_GLPI_ROOT = " . json_encode($CFG_GLPI['root_doc']) . ";</script>";
echo "<script>var KANBAN_TECHNICIANS = " . json_encode($technicians) . ";</script>";
echo "<script>var KANBAN_TIMEZONE_OFFSET = " . json_encode(date('P')) . ";</script>";
echo "<script>var KANBAN_SORT_OPTIONS = " . json_encode(PluginKanbanKanban::getSortOptions()) . ";</script>";
echo "<script>var KANBAN_TRANSLATIONS = " . json_encode([
   "open" => __("Open", "kanban"),
   "unassigned" => __("Unassigned", "kanban"),
   "noCategory" => __("No Category", "kanban"),
   "loading" => __("Loading...", "kanban"),
    "sortHint" => __("Sort via column dropdowns", "kanban"),
    "sortBy" => __("Sort by", "kanban"),
    "noTickets" => __("No tickets", "kanban"),
    "columns" => __("Columns", "kanban"),
    "hideColumn" => __("Hide column", "kanban"),
    "showColumn" => __("Show column", "kanban"),
    "showAllColumns" => __("Show all columns", "kanban"),
    "cardFields" => __("Card fields", "kanban"),
    "allTechnicians" => __("All Technicians", "kanban"),
    "searchByNumber" => __("Search by ticket number", "kanban"),
    "fieldPriority" => __("Priority", "kanban"),
    "fieldDateCreation" => __("Opening date", "kanban"),
    "fieldCategory" => __("Category", "kanban"),
    "fieldTechnician" => __("Technician", "kanban"),
    "fieldSla" => __("SLA", "kanban"),
   "priorityLabels" => [
      1 => __("Very Low", "kanban"),
      2 => __("Low", "kanban"),
      3 => __("Normal", "kanban"),
      4 => __("High", "kanban"),
      5 => __("Very High", "kanban"),
      6 => __("Major", "kanban"),
   ],
]) . ";</script>";

// Load assets via setup.php hooks (add_css / add_javascript)
?>

<div class="kanban-page-wrapper container-fluid py-4">
   <!-- Header and Filters -->
   <div class="row mb-4 g-3 align-items-center">
      <div class="col-12 col-lg-5 col-xxl-4 d-flex flex-wrap align-items-center gap-2 gap-lg-3">
         <h1 class="h2 text-primary m-0"><i class="ti ti-layout-kanban me-2"></i><?php echo __('Kanban Board', 'kanban'); ?></h1>
         <span id="kanban-total" class="badge text-bg-primary rounded-pill fs-6" aria-live="polite" title="<?php echo __('Total tickets', 'kanban'); ?>">0</span>
         <div class="d-flex flex-wrap align-items-center gap-2 ms-lg-auto">
            <a href="<?php echo $CFG_GLPI['root_doc']; ?>/front/ticket.php"
               class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"
               title="<?php echo __('Open in GLPI', 'kanban'); ?>">
               <i class="ti ti-external-link"></i>
               <span class="d-none d-xl-inline"><?php echo __('Open in GLPI', 'kanban'); ?></span>
            </a>
            <a href="<?php echo $CFG_GLPI['root_doc']; ?>/front/ticket.form.php"
               class="btn btn-sm btn-primary d-flex align-items-center gap-1"
               title="<?php echo __('New Ticket', 'kanban'); ?>">
               <i class="ti ti-plus"></i>
               <span class="d-none d-xl-inline"><?php echo __('New Ticket', 'kanban'); ?></span>
            </a>
            <button type="button" class="btn btn-sm btn-outline-info d-flex align-items-center gap-1" id="kanban-refresh-btn" title="<?php echo __('Refresh', 'kanban'); ?>">
               <i class="ti ti-reload"></i>
            </button>
            <div class="dropdown">
               <button type="button" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" id="kanban-columns-btn" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo __('Show / hide columns', 'kanban'); ?>">
                  <i class="ti ti-columns-3"></i>
                  <span class="d-none d-lg-inline"><?php echo __('Columns', 'kanban'); ?></span>
               </button>
               <ul class="dropdown-menu dropdown-menu-end kanban-columns-menu" aria-labelledby="kanban-columns-btn">
                  <!-- Populated by JavaScript -->
               </ul>
            </div>
            <div class="dropdown">
               <button type="button" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" id="kanban-fields-btn" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo __('Card fields', 'kanban'); ?>">
                  <i class="ti ti-list-check"></i>
                  <span class="d-none d-lg-inline"><?php echo __('Fields', 'kanban'); ?></span>
               </button>
               <ul class="dropdown-menu dropdown-menu-end kanban-fields-menu" aria-labelledby="kanban-fields-btn">
                  <!-- Populated by JavaScript -->
               </ul>
            </div>
         </div>
      </div>
      <div class="col-12 col-lg-7 col-xxl-8">
         <form id="kanban-filter-form" class="row g-2 justify-content-lg-end">
            <input type="hidden" name="_glpi_csrf_token" value="<?php echo Session::getNewCSRFToken(); ?>">

            <div class="col-auto kanban-filter-item">
               <label class="visually-hidden" for="filter-technician"><?php echo __('Technician', 'kanban'); ?></label>
               <select class="form-select select2-simple" id="filter-technician" name="technician" style="min-width: 150px;">
                  <option value=""><?php echo __('All Technicians', 'kanban'); ?></option>
                  <?php foreach ($technicians as $user): ?>
                     <option value="<?php echo $user['id']; ?>"><?php echo $user['name']; ?></option>
                  <?php endforeach; ?>
               </select>
            </div>

            <div class="col-auto kanban-filter-item">
               <label class="visually-hidden" for="filter-requester"><?php echo __('Requester', 'kanban'); ?></label>
               <select class="form-select select2-simple" id="filter-requester" name="requester" style="min-width: 150px;">
                  <option value=""><?php echo __('All Requesters', 'kanban'); ?></option>
                  <?php foreach ($requesters as $user): ?>
                     <option value="<?php echo $user['id']; ?>"><?php echo $user['name']; ?></option>
                  <?php endforeach; ?>
               </select>
            </div>

            <div class="col-auto kanban-filter-item">
               <label class="visually-hidden" for="filter-group"><?php echo __('Group', 'kanban'); ?></label>
               <select class="form-select select2-simple" id="filter-group" name="group" style="min-width: 150px;">
                  <option value=""><?php echo __('All Groups', 'kanban'); ?></option>
                  <?php foreach ($groups as $group): ?>
                     <option value="<?php echo $group['id']; ?>"><?php echo $group['name']; ?></option>
                  <?php endforeach; ?>
               </select>
            </div>

            <!-- Ticket number search -->
            <div class="col-auto kanban-filter-item">
               <label class="visually-hidden" for="filter-ticket-id"><?php echo __('Search by ticket number', 'kanban'); ?></label>
               <input type="search" class="form-control" id="filter-ticket-id" name="ticket_id"
                      placeholder="<?php echo __('Search by ticket number', 'kanban'); ?>"
                      autocomplete="off" style="min-width: 170px;">
            </div>

            <!-- Sort hint: per-column sort dropdowns are in each column header -->
            <div class="col-auto d-none d-xxl-inline">
               <span class="text-muted small fst-italic"><?php echo __('Sort via column dropdowns', 'kanban'); ?></span>
            </div>
         </form>
      </div>
   </div>

   <!-- Kanban Board Container -->
   <a href="#kanban-board" class="skip-link"><?php echo __("Skip to board", "kanban"); ?></a>
   <div id="kanban-board" class="kanban-board-container row flex-nowrap overflow-auto py-2">
      <!-- Dynamically filled by JavaScript -->
   </div>

    <!-- Loading overlay (hidden by default) -->
    <div id="kanban-loading-overlay" class="kanban-loading-overlay" style="display:none;">
       <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden"><?php echo __('Loading...', 'kanban'); ?></span>
       </div>
    </div>

    <!-- Ticket Detail Modal -->
    <div class="modal fade" id="kanbanTicketModal" tabindex="-1" aria-hidden="true">
       <div class="modal-dialog modal-lg">
          <div class="modal-content">
             <div class="modal-header">
                <h5 class="modal-title" id="kanbanTicketModalLabel"><?php echo __("Ticket Details", "kanban"); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __("Close", "kanban"); ?>"></button>
             </div>
             <div class="modal-body" id="kanbanTicketModalBody">
                <div class="text-center py-3">
                   <div class="spinner-border text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                   </div>
                </div>
             </div>
             <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" class="btn btn-primary" id="kanbanTicketModalOpen" target="_blank"><?php echo __("Open in GLPI", "kanban"); ?></a>
             </div>
          </div>
       </div>
    </div>
 </div>
<?php

Html::footer();



