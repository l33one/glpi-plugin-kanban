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

// Ensure $CFG_GLPI is available (GLPI 11's inc/includes.php is a stub, so the
// global may not be populated through the include alone).
global $CFG_GLPI;

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
              'search'     => isset($_GET['search']) ? trim($_GET['search']) : null,
              'technician' => isset($_GET['technician']) ? (int)$_GET['technician'] : null,
              'requester'  => isset($_GET['requester']) ? (int)$_GET['requester'] : null,
              'group'      => isset($_GET['group']) ? (int)$_GET['group'] : null,
               'ticket_id'  => isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : null,
              'type'       => isset($_GET['type']) ? (int)$_GET['type'] : null,
              'category'   => isset($_GET['category']) ? (int)$_GET['category'] : null,
           ];
          $sort = [
             'by'    => $_GET['sort_by'] ?? 'date',
             'order' => $_GET['sort_order'] ?? 'DESC'
          ];

          try {
             $tickets = PluginKanbanKanban::getTicketsForKanban($filters, $sort);
          } catch (\Throwable $e) {
             http_response_code(500);
             echo json_encode(['error' => $e->getMessage()]);
             exit;
          }

          header("Content-Type: application/json; charset=UTF-8");
          echo json_encode([
             'statuses' => $tickets,
             'metrics'  => PluginKanbanKanban::computeMetrics($tickets)
          ]);
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
              'groups'      => PluginKanbanKanban::getGroupsForFilter(),
              'categories'  => PluginKanbanKanban::getCategoriesForFilter()
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

       case 'assign_to_me':
          Session::checkCSRF($_POST);
          $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
          header("Content-Type: application/json; charset=UTF-8");
          echo json_encode([
             'result'     => PluginKanbanKanban::assignToMe($ticket_id),
             'csrf_token' => Session::getNewCSRFToken(),
          ]);
          exit;

       case 'get_followups':
          $ticket_id = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
          header("Content-Type: application/json; charset=UTF-8");
          echo json_encode([
             'followups' => PluginKanbanKanban::getFollowups($ticket_id)
          ]);
          exit;

       case 'change_priority':
          Session::checkCSRF($_POST);
          $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
          $priority  = isset($_POST['priority']) ? (int)$_POST['priority'] : 0;
          header("Content-Type: application/json; charset=UTF-8");
          echo json_encode([
             'result'     => PluginKanbanKanban::changePriority($ticket_id, $priority),
             'csrf_token' => Session::getNewCSRFToken(),
          ]);
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
$categories  = PluginKanbanKanban::getCategoriesForFilter();

// Inject JS variables
echo "<script>var KANBAN_STATUSES = " . json_encode($kanban_statuses) . ";</script>";
echo "<script>var KANBAN_GLPI_ROOT = " . json_encode($CFG_GLPI['root_doc']) . ";</script>";
echo "<script>var KANBAN_TECHNICIANS = " . json_encode($technicians) . ";</script>";
echo "<script>var KANBAN_CURRENT_USER = " . json_encode([
   'id'   => (int)Session::getLoginUserID(),
   'name' => getUserName(Session::getLoginUserID()),
]) . ";</script>";
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
    "allCategories" => __("All Categories", "kanban"),
    "searchByNumber" => __("Search by ticket number", "kanban"),
    "searchPlaceholder" => __("Search: number, title, category, description...", "kanban"),
    "advancedFilters" => __("Advanced Filters", "kanban"),
    "assignedToMe" => __("Assigned to me", "kanban"),
    "clearFilters" => __("Clear", "kanban"),
    "quickActions" => __("Quick actions", "kanban"),
    "assignToMe" => __("Assign to me", "kanban"),
    "listFollowups" => __("List follow-ups", "kanban"),
    "changePriority" => __("Change priority", "kanban"),
    "followupsTitle" => __("Ticket follow-ups", "kanban"),
    "noFollowups" => __("No follow-ups for this ticket", "kanban"),
    "metricTotal" => __("Visible tickets", "kanban"),
    "metricSlaOnTime" => __("Within SLA", "kanban"),
    "metricSlaOverdue" => __("SLA overdue", "kanban"),
    "metricAssignedToMe" => __("Assigned to me", "kanban"),
    "metricUnassigned" => __("Unassigned", "kanban"),
    "autoRefresh" => __("Auto-refresh", "kanban"),
    "autoRefreshOff" => __("Off", "kanban"),
    "saved" => __("Saved", "kanban"),
    "assigned" => __("Assigned", "kanban"),
    "justNow" => __("just now", "kanban"),
    "minutesAgo" => __("X min ago", "kanban"),
    "hoursAgo" => __("X h ago", "kanban"),
    "daysAgo" => __("X d ago", "kanban"),
    "today" => __("Today", "kanban"),
    "yesterday" => __("Yesterday", "kanban"),
    "tomorrow" => __("Tomorrow", "kanban"),
    "inDays" => __("in X d", "kanban"),
    "fieldPriority" => __("Priority", "kanban"),
    "fieldDateCreation" => __("Opening date", "kanban"),
    "fieldCategory" => __("Category", "kanban"),
    "fieldTechnician" => __("Technician", "kanban"),
    "fieldSla" => __("SLA", "kanban"),
    "fieldDuration" => __("Open duration", "kanban"),
    "ticketDuration" => __("Open duration", "kanban"),
    "slaFrozenHint" => __("SLA paused", "kanban"),
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
   <script>
      (function () {
         // Follow GLPI's native theme: dark when GLPI is dark.
         // GLPI 11 flags it on <html data-glpi-theme-dark="1">, GLPI 10 exposes
         // the --is-dark CSS variable on :root.
         var dark = document.documentElement.getAttribute('data-glpi-theme-dark');
         if (dark !== '1' && dark !== '0') {
            dark = getComputedStyle(document.documentElement).getPropertyValue('--is-dark').trim() === 'true' ? '1' : '0';
         }
         if (dark === '1') {
            var wrapper = document.querySelector('.kanban-page-wrapper');
            if (wrapper) {
               wrapper.classList.add('kanban-theme-dark');
            }
         }
      })();
   </script>
   <!-- Title bar -->
   <div class="kanban-titlebar">
      <div class="kanban-titlebar-left">
         <h1 class="kanban-titlebar-title"><i class="ti ti-layout-kanban"></i><span><?php echo __('Kanban Board', 'kanban'); ?></span></h1>
         <span id="kanban-total" class="badge rounded-pill fs-6" aria-live="polite" title="<?php echo __('Total tickets', 'kanban'); ?>">0</span>
      </div>
      <div class="kanban-titlebar-actions">
         <a href="<?php echo $CFG_GLPI['root_doc']; ?>/front/ticket.php"
            class="btn btn-sm btn-outline-light d-flex align-items-center gap-1"
            title="<?php echo __('Open in GLPI', 'kanban'); ?>">
            <i class="ti ti-external-link"></i>
            <span class="d-none d-xl-inline"><?php echo __('Open in GLPI', 'kanban'); ?></span>
         </a>
         <a href="<?php echo $CFG_GLPI['root_doc']; ?>/front/ticket.form.php"
            class="btn btn-sm btn-light d-flex align-items-center gap-1 fw-semibold"
            title="<?php echo __('New Ticket', 'kanban'); ?>">
            <i class="ti ti-plus"></i>
            <span class="d-none d-xl-inline"><?php echo __('New Ticket', 'kanban'); ?></span>
         </a>
         <button type="button" class="btn btn-sm btn-outline-light d-flex align-items-center gap-1" id="kanban-refresh-btn" title="<?php echo __('Refresh', 'kanban'); ?>">
            <i class="ti ti-reload"></i>
         </button>
         <div class="dropdown">
            <button type="button" class="btn btn-sm btn-outline-light d-flex align-items-center gap-1" id="kanban-autorefresh-btn" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo __('Auto-refresh', 'kanban'); ?>">
               <i class="ti ti-clock-play"></i>
               <span class="d-none d-xl-inline"><?php echo __('Auto-refresh', 'kanban'); ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" id="kanban-autorefresh-menu">
               <li><a class="dropdown-item active" href="#" data-interval="0"><?php echo __('Off', 'kanban'); ?></a></li>
               <?php
               // Get available refresh options from config (default: 1,2,3 min)
               $refresh_opts = PluginKanbanConfig::getRefreshOptions();
               foreach ($refresh_opts as $mins) {
                  $label = $mins . ' min';
                  echo '<li><a class="dropdown-item" href="#" data-interval="' . ($mins * 60) . '">' . htmlspecialchars($label) . '</a></li>';
               }
               ?>
            </ul>
         </div>
         <div class="dropdown">
            <button type="button" class="btn btn-sm btn-outline-light d-flex align-items-center gap-1" id="kanban-columns-btn" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo __('Show / hide columns', 'kanban'); ?>">
               <i class="ti ti-columns-3"></i>
               <span class="d-none d-lg-inline"><?php echo __('Columns', 'kanban'); ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end kanban-columns-menu" aria-labelledby="kanban-columns-btn">
               <!-- Populated by JavaScript -->
            </ul>
         </div>
         <div class="dropdown">
            <button type="button" class="btn btn-sm btn-outline-light d-flex align-items-center gap-1" id="kanban-fields-btn" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo __('Card fields', 'kanban'); ?>">
               <i class="ti ti-list-check"></i>
               <span class="d-none d-lg-inline"><?php echo __('Fields', 'kanban'); ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end kanban-fields-menu" aria-labelledby="kanban-fields-btn">
               <!-- Populated by JavaScript -->
            </ul>
         </div>
      </div>
   </div>

   <!-- Filters -->
   <div class="row mb-4 g-3">
      <div class="col-12">
         <form id="kanban-filter-form" class="row g-2 justify-content-lg-end">
            <input type="hidden" name="_glpi_csrf_token" value="<?php echo Session::getNewCSRFToken(); ?>">

            <!-- Omnichannel search: number, title, category or description -->
            <div class="col-auto kanban-filter-item d-flex align-items-center">
               <label class="visually-hidden" for="filter-search"><?php echo __('Search by ticket number', 'kanban'); ?></label>
               <input type="search" class="form-control" id="filter-search" name="search"
                      placeholder="<?php echo __('Search: number, title, category, description...', 'kanban'); ?>"
                      autocomplete="off" style="min-width: 240px;">
            </div>

            <!-- Quick actions -->
            <div class="col-auto d-flex align-items-center gap-2">
               <button type="button" class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1 kanban-quick-btn"
                       id="kanban-assigned-me-btn" title="<?php echo __('Assigned to me', 'kanban'); ?>">
                  <i class="ti ti-user-check"></i>
                  <span class="d-none d-lg-inline"><?php echo __('Assigned to me', 'kanban'); ?></span>
               </button>
               <button type="button" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1 kanban-quick-btn"
                       id="kanban-clear-filters-btn" title="<?php echo __('Clear', 'kanban'); ?>">
                  <i class="ti ti-filter-off"></i>
                  <span class="d-none d-lg-inline"><?php echo __('Clear', 'kanban'); ?></span>
               </button>
            </div>

            <!-- Advanced filters toggle -->
            <div class="col-auto d-flex align-items-center">
               <button type="button" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"
                       id="kanban-adv-filters-btn" data-bs-toggle="collapse" data-bs-target="#kanban-advanced-filters"
                       aria-expanded="false" aria-controls="kanban-advanced-filters"
                       title="<?php echo __('Advanced Filters', 'kanban'); ?>">
                  <i class="ti ti-sliders"></i>
                  <span class="d-none d-lg-inline"><?php echo __('Advanced Filters', 'kanban'); ?></span>
                  <span class="badge text-bg-primary rounded-pill d-none" id="kanban-adv-count">0</span>
               </button>
            </div>

            <!-- Secondary filters, hidden until expanded -->
            <div class="col-12 collapse" id="kanban-advanced-filters">
               <div class="row g-2 justify-content-lg-end">

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

                  <div class="col-auto kanban-filter-item">
                     <label class="visually-hidden" for="filter-type"><?php echo __('Ticket type', 'kanban'); ?></label>
                     <select class="form-select select2-simple" id="filter-type" name="type" style="min-width: 150px;">
                        <option value=""><?php echo __('All types', 'kanban'); ?></option>
                        <?php foreach (Ticket::getTypes() as $type_id => $type_name): ?>
                           <option value="<?php echo $type_id; ?>"><?php echo $type_name; ?></option>
                        <?php endforeach; ?>
                     </select>
                  </div>

                  <div class="col-auto kanban-filter-item">
                     <label class="visually-hidden" for="filter-category"><?php echo __('Category', 'kanban'); ?></label>
                     <select class="form-select select2-simple" id="filter-category" name="category" style="min-width: 150px;">
                        <option value=""><?php echo __('All Categories', 'kanban'); ?></option>
                        <?php foreach ($categories as $category): ?>
                           <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                        <?php endforeach; ?>
                     </select>
                  </div>

                  <!-- Sort hint: per-column sort dropdowns are in each column header -->
                  <div class="col-auto d-none d-xxl-inline">
                     <span class="text-muted small fst-italic"><?php echo __('Sort via column dropdowns', 'kanban'); ?></span>
                  </div>
               </div>
            </div>
         </form>
      </div>
   </div>

   <!-- Metrics bar (gestão à vista) -->
   <div id="kanban-metrics" class="row g-2 mb-3" aria-label="<?php echo __('Board indicators', 'kanban'); ?>">
      <div class="col-6 col-lg">
         <div class="kanban-metric">
            <div class="kanban-metric-icon"><i class="ti ti-stack-2"></i></div>
            <div class="kanban-metric-body">
               <div class="kanban-metric-value" id="metric-total">0</div>
               <div class="kanban-metric-label"><?php echo __('Visible tickets', 'kanban'); ?></div>
            </div>
         </div>
      </div>
      <div class="col-6 col-lg">
         <div class="kanban-metric">
            <div class="kanban-metric-icon text-success"><i class="ti ti-shield-check"></i></div>
            <div class="kanban-metric-body">
               <div class="kanban-metric-value text-success" id="metric-sla">--</div>
               <div class="kanban-metric-label"><?php echo __('Within SLA', 'kanban'); ?></div>
            </div>
         </div>
      </div>
      <div class="col-6 col-lg">
         <div class="kanban-metric">
            <div class="kanban-metric-icon text-danger"><i class="ti ti-shield-alert"></i></div>
            <div class="kanban-metric-body">
               <div class="kanban-metric-value text-danger" id="metric-overdue">0</div>
               <div class="kanban-metric-label"><?php echo __('SLA overdue', 'kanban'); ?></div>
            </div>
         </div>
      </div>
      <div class="col-6 col-lg">
         <div class="kanban-metric">
            <div class="kanban-metric-icon text-info"><i class="ti ti-user-check"></i></div>
            <div class="kanban-metric-body">
               <div class="kanban-metric-value" id="metric-assigned">0</div>
               <div class="kanban-metric-label"><?php echo __('Assigned to me', 'kanban'); ?></div>
            </div>
         </div>
      </div>
      <div class="col-6 col-lg">
         <div class="kanban-metric">
            <div class="kanban-metric-icon text-warning"><i class="ti ti-user-off"></i></div>
            <div class="kanban-metric-body">
               <div class="kanban-metric-value" id="metric-unassigned">0</div>
               <div class="kanban-metric-label"><?php echo __('Unassigned', 'kanban'); ?></div>
            </div>
         </div>
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

    <!-- Follow-ups Modal -->
    <div class="modal fade" id="kanbanFollowupsModal" tabindex="-1" aria-hidden="true">
       <div class="modal-dialog modal-lg">
          <div class="modal-content">
             <div class="modal-header">
                <h5 class="modal-title" id="kanbanFollowupsModalLabel"><?php echo __("Ticket follow-ups", "kanban"); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __("Close", "kanban"); ?>"></button>
             </div>
             <div class="modal-body" id="kanbanFollowupsModalBody">
                <div class="text-center py-3">
                   <div class="spinner-border text-primary" role="status">
                      <span class="visually-hidden">Loading...</span>
                   </div>
                </div>
             </div>
             <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
             </div>
          </div>
       </div>
    </div>
 </div>
<?php

Html::footer();



