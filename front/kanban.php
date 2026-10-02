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

// This page only renders the board. The JSON endpoints live in front/api.php,
// dispatched by PluginKanbanApi, so the markup and the data contracts are
// separate files instead of one switch statement.

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

// Apply the admin-configured column order (PluginKanbanConfig) when set.
// Statuses not listed are appended in their native order so a newly-created
// ticket status never disappears from the board.
$status_order = PluginKanbanConfig::getStatusOrder();
if (count($status_order) > 0) {
   $ordered = [];
   foreach ($status_order as $sid) {
      if (isset($kanban_statuses[$sid])) {
         $ordered[$sid] = $kanban_statuses[$sid];
         unset($kanban_statuses[$sid]);
      }
   }
   $kanban_statuses = $ordered + $kanban_statuses;
}

// Get filter data from model
$technicians = PluginKanbanKanban::getTechniciansForFilter();
$requesters  = PluginKanbanKanban::getRequestersForFilter();
$groups      = PluginKanbanKanban::getGroupsForFilter();
$categories  = PluginKanbanKanban::getCategoriesForFilter();

// Inject JS variables.
// JSON_HEX_* keep `<`, `>`, `&`, quotes and apostrophes out of the inline
// <script> context so DB-sourced strings can never break out of the literal.
$kanban_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
echo "<script>var KANBAN_STATUSES = " . json_encode($kanban_statuses, $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_GLPI_ROOT = " . json_encode($CFG_GLPI['root_doc'], $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_TECHNICIANS = " . json_encode($technicians, $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_CURRENT_USER = " . json_encode([
   'id'   => (int)Session::getLoginUserID(),
   'name' => getUserName(Session::getLoginUserID()),
], $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_TIMEZONE_OFFSET = " . json_encode(date('P'), $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_SORT_OPTIONS = " . json_encode(PluginKanbanKanban::getSortOptions(), $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_ENABLE_DRAG_DROP = " . json_encode(PluginKanbanConfig::getEnableDragDrop() ? 1 : 0, $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_ENABLE_UNDO = " . json_encode(PluginKanbanConfig::getEnableUndo() ? 1 : 0, $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_CARDS_PER_COLUMN = " . json_encode(PluginKanbanConfig::getCardsPerColumn(), $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_WIP_LIMIT = " . json_encode(PluginKanbanConfig::getWipLimit(), $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_WIP_BLOCK = " . json_encode(PluginKanbanConfig::getWipBlockExceed() ? 1 : 0, $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_REQUIRE_PENDING_REASON = " . json_encode(PluginKanbanConfig::getRequirePendingReason() ? 1 : 0, $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_REQUIRE_SOLUTION_MODEL = " . json_encode(PluginKanbanConfig::getRequireSolutionModel() ? 1 : 0, $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_REQUIRE_SOLUTION_TYPE = " . json_encode(PluginKanbanConfig::getRequireSolutionType() ? 1 : 0, $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_SOLUTION_TEMPLATES = " . json_encode(PluginKanbanKanban::getSolutionTemplates(), $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_SOLUTION_TYPES = " . json_encode(PluginKanbanKanban::getSolutionTypes(), $kanban_json_flags) . ";</script>";
echo "<script>var KANBAN_PENDING_REASONS = " . json_encode(PluginKanbanKanban::getPendingReasons(), $kanban_json_flags) . ";</script>";
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
    "noTechnician" => __("No Technician", "kanban"),
    "noRequester" => __("No Requester", "kanban"),
    "noGroup" => __("No Group", "kanban"),
    "noType" => __("No Type", "kanban"),
    "noCategoryFilter" => __("No Category", "kanban"),
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
    "save" => __("Save", "kanban"),
    "close" => __("Close", "kanban"),
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
    "statusUpdated" => __("Status updated", "kanban"),
    "moveUndone" => __("The ticket was moved back", "kanban"),
    "undo" => __("Undo", "kanban"),
    "undoMove" => __("Undo the move", "kanban"),
    "loadMore" => __("Load more", "kanban"),
    // sprintf placeholders (%d, %1$d) must stay in single quotes: inside a double
   // quoted string PHP reads "$d" as a variable and warns on an undefined one.
    "showingOf" => __('Showing %1$d of %2$d', 'kanban'),
    "wipReached" => __('This column reached its limit of %d tickets in progress', 'kanban'),
    "saveView" => __("Save this view", "kanban"),
    "viewName" => __("View name", "kanban"),
    "savedViews" => __("Saved views", "kanban"),
    "shareView" => __("Share with everyone", "kanban"),
    "noSavedViews" => __("No saved view yet", "kanban"),
    "deleteView" => __("Delete the view", "kanban"),
    "viewSaved" => __("View saved", "kanban"),
    "viewDeleted" => __("View deleted", "kanban"),
    "metricsPartial" => __("counts based on the cards shown", "kanban"),
    "moveTo" => __("Move to", "kanban"),
    "cancel" => __("Cancel", "kanban"),
    "confirmMove" => __("Move", "kanban"),
    "pendingReason" => __("Pending reason", "kanban"),
    "pendingReasonPlaceholder" => __("Describe why the ticket is pending...", "kanban"),
    "solutionDescription" => __("Solution", "kanban"),
    "solutionModel" => __("Solution model", "kanban"),
    "solutionType" => __("Solution type", "kanban"),
    "noSolutionModel" => __("Select a model...", "kanban"),
    "noSolutionType" => __("No type", "kanban"),
    "moveToPending" => __("Move to Pending", "kanban"),
    "moveToSolved" => __("Move to Solved", "kanban"),
    "followupSkipped" => __("The status changed, but the pending reason was not recorded (no follow-up right).", "kanban"),
    "solutionSkipped" => __("The status changed, but the solution was not recorded (the ticket can no longer be solved).", "kanban"),
    "setStatusPending" => __("Set the status to pending", "kanban"),
    "noPendingReason" => __("Select a reason...", "kanban"),
    "automaticFollowup" => __("Automatic follow-up", "kanban"),
    "automaticResolution" => __("Automatic resolution", "kanban"),
    "pendingDescription" => __("Description", "kanban"),
    "statusUnchanged" => __("Status unchanged; only the follow-up was recorded", "kanban"),
    "requirePendingReason" => __("Select a pending reason before moving the card.", "kanban"),
    "requireSolutionModel" => __("Select a solution model before moving the card.", "kanban"),
    "requireSolutionType" => __("Select a solution type before moving the card.", "kanban"),
    "requiredPendingDescription" => __("A description is required to move to Pending", "kanban"),
    "requiredSolutionDescription" => __("A solution description is required to move to Solved", "kanban"),
   "priorityLabels" => [
      1 => __("Very Low", "kanban"),
      2 => __("Low", "kanban"),
      3 => __("Normal", "kanban"),
      4 => __("High", "kanban"),
      5 => __("Very High", "kanban"),
6 => __("Major", "kanban"),
    ],
], $kanban_json_flags) . ";</script>";

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
               $refresh_opts = class_exists('PluginKanbanConfig')
                  ? PluginKanbanConfig::getRefreshOptions()
                  : [1, 2, 3];
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

            <!-- Saved board views: dropdown + "save this view" button. Populated by JS -->
            <div class="col-auto d-flex align-items-center gap-2">
               <div class="dropdown">
                  <button type="button" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"
                          id="kanban-views-btn" data-bs-toggle="dropdown" aria-expanded="false"
                          title="<?php echo __('Saved views', 'kanban'); ?>">
                     <i class="ti ti-bookmarks"></i>
                     <span class="d-none d-lg-inline" id="kanban-views-label"><?php echo __('Saved views', 'kanban'); ?></span>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end kanban-views-menu" aria-labelledby="kanban-views-btn">
                  </ul>
               </div>
               <button type="button" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"
                       id="kanban-save-view-btn" title="<?php echo __('Save this view', 'kanban'); ?>">
                  <i class="ti ti-bookmark-plus"></i>
                  <span class="d-none d-xl-inline"><?php echo __('Save this view', 'kanban'); ?></span>
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
                         <option value="0"><?php echo __('No Technician', 'kanban'); ?></option>
                         <?php foreach ($technicians as $user): ?>
                           <option value="<?php echo (int)$user['id']; ?>"><?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                     </select>
                  </div>

                  <div class="col-auto kanban-filter-item">
                     <label class="visually-hidden" for="filter-requester"><?php echo __('Requester', 'kanban'); ?></label>
                      <select class="form-select select2-simple" id="filter-requester" name="requester" style="min-width: 150px;">
<option value=""><?php echo __('All Requesters', 'kanban'); ?></option>
                         <option value="0"><?php echo __('No Requester', 'kanban'); ?></option>
                         <?php foreach ($requesters as $user): ?>
                           <option value="<?php echo (int)$user['id']; ?>"><?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                     </select>
                  </div>

                  <div class="col-auto kanban-filter-item">
                     <label class="visually-hidden" for="filter-group"><?php echo __('Group', 'kanban'); ?></label>
                      <select class="form-select select2-simple" id="filter-group" name="group" style="min-width: 150px;">
<option value=""><?php echo __('All Groups', 'kanban'); ?></option>
                         <option value="0"><?php echo __('No Group', 'kanban'); ?></option>
                         <?php foreach ($groups as $group): ?>
                           <option value="<?php echo (int)$group['id']; ?>"><?php echo htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                     </select>
                  </div>

                  <div class="col-auto kanban-filter-item">
                     <label class="visually-hidden" for="filter-type"><?php echo __('Ticket type', 'kanban'); ?></label>
                      <select class="form-select select2-simple" id="filter-type" name="type" style="min-width: 150px;">
<option value=""><?php echo __('All types', 'kanban'); ?></option>
                         <option value="0"><?php echo __('No Type', 'kanban'); ?></option>
                         <?php foreach (Ticket::getTypes() as $type_id => $type_name): ?>
                           <option value="<?php echo (int)$type_id; ?>"><?php echo htmlspecialchars($type_name, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                     </select>
                  </div>

                  <div class="col-auto kanban-filter-item">
                     <label class="visually-hidden" for="filter-category"><?php echo __('Category', 'kanban'); ?></label>
                      <select class="form-select select2-simple" id="filter-category" name="category" style="min-width: 150px;">
<option value=""><?php echo __('All Categories', 'kanban'); ?></option>
                         <option value="0"><?php echo __('No Category', 'kanban'); ?></option>
                         <?php foreach ($categories as $category): ?>
                           <option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
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

   <!-- Metrics bar (gestÃ£o Ã  vista) -->
   <div id="kanban-metrics" class="row g-2 mb-3" aria-label="<?php echo __('Board indicators', 'kanban'); ?>">
      <div class="col-6 col-lg">
         <div class="kanban-metric">
            <div class="kanban-metric-icon"><i class="ti ti-stack-2"></i></div>
            <div class="kanban-metric-body">
               <div class="kanban-metric-value" id="metric-total">0</div>
               <div class="kanban-metric-label"><?php echo __('Visible tickets', 'kanban'); ?></div>
            </div>
            <i class="ti ti-info-circle kanban-metric-hint d-none" id="metric-partial-hint"
               title="<?php echo __('Counts based on the cards shown: SLA and assignee figures cover the loaded cards, while the total is the number of matching tickets.', 'kanban'); ?>"></i>
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



