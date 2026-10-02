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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Entry point of the board's JSON API.
 *
 * Every action is a public method here and nothing else: front/api.php only
 * dispatches. Reading an action, its accepted parameters and its permission
 * check is a single file lookup instead of a walk through a switch statement
 * interleaved with parameter casting and headers.
 */
class PluginKanbanApi {

   /**
    * Actions that mutate data. They must be POSTed; CSRF is checked by GLPI
    * core before this code runs (see front/api.php for the details).
    */
   const WRITE_ACTIONS = [
      'assign_to_me',
      'change_priority',
      'update_ticket_status',
      'undo_ticket_status',
      'save_preset',
      'delete_preset',
      'rename_preset',
   ];

   /**
    * Dispatch one action and emit its JSON answer.
    */
   public function handle(string $action): void {
      if ($action === '' || !preg_match('/^[a-z0-9_]+$/', $action)) {
         $this->fail(__('Invalid request', 'kanban'), 400);
      }

      if (in_array($action, self::WRITE_ACTIONS, true)
         && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
         $this->fail(__('Invalid request', 'kanban'), 405);
      }

      // The page and the API share the same visibility rules; no action is
      // reachable without at least the read right on tickets.
      if (!PluginKanbanKanban::canView() || !Ticket::canView()) {
         $this->fail(__('Permission denied', 'kanban'), 403);
      }

      $method = 'action' . str_replace(' ', '', ucwords(str_replace('_', ' ', $action)));
      if (!method_exists($this, $method)) {
         $this->fail(__('Unknown action', 'kanban'), 404);
      }

      try {
         $payload = $this->$method();
      } catch (\Throwable $e) {
         // The message can carry SQL from a database error: log it server-side
         // and return a generic error to the browser.
         Toolbox::logInFile('kanban', $action . ' failed: ' . $e->getMessage());
         $this->fail(__('An error occurred while processing the request', 'kanban'), 500);
      }

      if ($payload === null) {
         // The method already answered (error paths below use fail()).
         return;
      }

      $this->respond(array_merge(['success' => true], $payload));
   }

   // =======================================================================
   // Read actions
   // =======================================================================

   /**
    * Board cards, with the real per-column totals and whether more are hidden.
    *
    * The board used to answer a flat {status => [cards]} and let the browser
    * count what it received, which turned a 200-ticket column into "200" and
    * the board header into a number that changed with the display limit. The
    * totals are counted on the server with the same filters, so the count on
    * screen is the count of tickets that match.
    *
    * Passing status + offset returns a single column page, for "load more".
    */
   private function actionGetTickets(): array {
      $filters = [
         'search'     => $this->strParam('search'),
         'technician' => $this->intParam('technician'),
         'requester'  => $this->intParam('requester'),
         'group'      => $this->intParam('group'),
         'ticket_id'  => $this->intParam('ticket_id'),
         'type'       => $this->intParam('type'),
         'category'   => $this->intParam('category'),
      ];
      $sort = [
         'by'    => $this->strParam('sort_by', 'date'),
         'order' => $this->strParam('sort_order', 'DESC'),
      ];

      // The page size is the configured one unless the browser asks for a
      // specific page size, and never outside the range the configuration page
      // accepts: an unbounded limit would turn a query parameter into a way to
      // ask the server for the whole table.
      $limit      = $this->intParam('limit') ?: PluginKanbanConfig::getCardsPerColumn();
      $limit      = max(1, min(200, $limit));
      $offset     = max(0, $this->intParam('offset') ?? 0);
      $onlyStatus = $this->intParam('status') ?? 0;

      $board = PluginKanbanKanban::getBoardData($filters, $sort, $limit, $offset, $onlyStatus);

      return [
         'statuses'  => $board['statuses'],
         'totals'    => $board['totals'],
         'has_more'  => $board['has_more'],
         'limit'     => $limit,
         'metrics'   => PluginKanbanKanban::computeMetrics($board['statuses'], $board['totals']),
      ];
   }

   private function actionGetTicketDetail(): array {
      return PluginKanbanKanban::getTicketDetail($this->requireIntParam('ticket_id'));
   }

   private function actionGetFilterData(): array {
      return [
         'technicians' => PluginKanbanKanban::getTechniciansForFilter(),
         'requesters'  => PluginKanbanKanban::getRequestersForFilter(),
         'groups'      => PluginKanbanKanban::getGroupsForFilter(),
         'categories'  => PluginKanbanKanban::getCategoriesForFilter(),
      ];
   }

   private function actionGetGroupTechnicians(): array {
      $group_id = $this->intParam('group') ?? 0;
      if ($group_id > 0) {
         // Only allow enumerating groups the caller can see on the board
         // (their own groups expanded with subgroups), so the membership
         // of unrelated groups is never disclosed.
         $allowed = PluginKanbanKanban::expandGroupIds(
            array_column(PluginKanbanKanban::getGroupsForFilter(), 'id')
         );
         if (!in_array($group_id, $allowed, true)) {
            $group_id = 0;
         }
      }
      $technicians = $group_id > 0
         ? PluginKanbanKanban::getTechniciansForGroup($group_id)
         : PluginKanbanKanban::getTechniciansForFilter();

      return ['technicians' => $technicians];
   }

   private function actionGetFollowups(): array {
      return ['followups' => PluginKanbanKanban::getFollowups($this->requireIntParam('ticket_id'))];
   }

   /**
    * Work-in-progress state of every column, for the board highlight.
    *
    * The count ignores the active filters on purpose: a limit on the queue is
    * not a limit on the current view.
    */
   private function actionGetWipState(): array {
      $limit = PluginKanbanConfig::getWipLimit();
      $state = [];
      foreach (array_keys(Ticket::getAllStatusArray()) as $status_id) {
         $current = PluginKanbanKanban::countVisibleTicketsInStatus((int)$status_id);
         $state[$status_id] = [
            'current' => $current,
            'limit'   => $limit,
            'full'    => $limit > 0 && $current >= $limit,
         ];
      }

      return ['wip' => $state, 'wip_limit' => $limit];
   }

   // =======================================================================
   // Write actions
   // =======================================================================

   private function actionAssignToMe(): array {
      return [
         'result'     => PluginKanbanKanban::assignToMe($this->requireIntParam('ticket_id')),
         'csrf_token' => Session::getNewCSRFToken(),
      ];
   }

   private function actionChangePriority(): array {
      return [
         'result'     => PluginKanbanKanban::changePriority($this->requireIntParam('ticket_id'), $this->requireIntParam('priority')),
         'csrf_token' => Session::getNewCSRFToken(),
      ];
   }

   private function actionUpdateTicketStatus(): array {
      $extra = [
         'pending_reason'              => $this->strParam('pending_reason'),
         'pending'                     => $this->strParam('pending'),
         'pendingreasons_id'           => $this->intParam('pendingreasons_id'),
         'followup_frequency'          => $this->intParam('followup_frequency'),
         'followups_before_resolution' => $this->intParam('followups_before_resolution'),
         'solution'                    => $this->strParam('solution'),
         'solution_type_id'            => $this->intParam('solution_type_id'),
         'solution_template_id'        => $this->intParam('solution_template_id'),
      ];

      return [
         'result'     => PluginKanbanKanban::updateTicketStatus(
            $this->requireIntParam('ticket_id'),
            $this->requireIntParam('status'),
            $extra
         ),
         // The single-use CSRF token is consumed on every POST, so a fresh
         // token must accompany every response or the next action in the same
         // page session would fail.
         'csrf_token' => Session::getNewCSRFToken(),
      ];
   }

   /**
    * Put a ticket back where it came from after a board move.
    */
   private function actionUndoTicketStatus(): array {
      return [
         'result'     => PluginKanbanKanban::undoStatusChange(
            $this->requireIntParam('ticket_id'),
            $this->requireIntParam('from_status')
         ),
         'csrf_token' => Session::getNewCSRFToken(),
      ];
   }

   private function actionGetPresets(): array {
      return ['presets' => PluginKanbanFilterPreset::listForUser()];
   }

   private function actionSavePreset(): array {
      $preset  = new PluginKanbanFilterPreset();
      $name    = $this->strParam('name');
      // Every POST burns the single-use CSRF token, so each answer must carry a
      // fresh one. The early return below included it because without it the
      // next POST from the same page session is rejected by GLPI core.
      $refresh = ['csrf_token' => Session::getNewCSRFToken()];

      if ($name === '') {
         return [
            'result'     => ['success' => false, 'error' => __('Please give the view a name', 'kanban')],
         ] + $refresh;
      }

      return [
         'result' => $preset->saveForUser(
            $name,
            [
               'search'     => $this->strParam('search'),
               'technician' => $this->intParam('technician'),
               'requester'  => $this->intParam('requester'),
               'group'      => $this->intParam('group'),
               'type'       => $this->intParam('type'),
               'category'   => $this->intParam('category'),
               'assigned_to_me' => $this->boolParam('assigned_to_me'),
            ],
            (int)$this->strParam('id') ?: null,
            $this->boolParam('is_private')
         ),
      ] + $refresh;
   }

   private function actionRenamePreset(): array {
      $preset = new PluginKanbanFilterPreset();

      return [
         'result'     => $preset->renameForUser($this->requireIntParam('id'), $this->strParam('name')),
         'csrf_token' => Session::getNewCSRFToken(),
      ];
   }

   private function actionDeletePreset(): array {
      $preset = new PluginKanbanFilterPreset();

      return [
         'result'     => $preset->deleteForUser($this->requireIntParam('id')),
         'csrf_token' => Session::getNewCSRFToken(),
      ];
   }

   // =======================================================================
   // Request helpers
   // =======================================================================

   /**
    * A parameter, trimmed. Empty and "0" stay distinguishable: "0" is how the
    * board spells "no technician", so it cannot be folded into null here.
    */
   private function strParam(string $name, string $default = ''): string {
      $value = $_POST[$name] ?? $_GET[$name] ?? $default;
      if (!is_string($value)) {
         $value = (string)$value;
      }
      $value = trim($value);

      return $value === '' ? $default : $value;
   }

   /**
    * An integer parameter; null when absent or blank, so the model can tell
    * "no filter" from "filter by 0".
    */
   private function intParam(string $name): ?int {
      $value = $_POST[$name] ?? $_GET[$name] ?? null;
      if ($value === null || $value === '' || !is_scalar($value)) {
         return null;
      }

      return (int)$value;
   }

   /**
    * A parameter the action cannot run without.
    *
    * intParam() answers null so the filter array can tell "no filter" from
    * "filter by 0", but handing that null to a model method typed `int` raises
    * a TypeError, which the dispatcher turns into an HTTP 500. A missing
    * identifier is a bad request, so it is refused as one.
    */
   private function requireIntParam(string $name): ?int {
      $value = $this->intParam($name);
      if ($value === null) {
         $this->fail(__('Invalid request', 'kanban'), 400);
      }

      return $value;
   }

   private function boolParam(string $name): bool {
      $value = $_POST[$name] ?? $_GET[$name] ?? null;
      if ($value === null) {
         return false;
      }

      return in_array((string)$value, ['1', 'on', 'true'], true);
   }

   // =======================================================================
   // JSON output
   // =======================================================================

   private function respond(array $payload): void {
      http_response_code(200);
      header('Content-Type: application/json; charset=UTF-8');
      header('Cache-Control: no-store');
      echo json_encode($payload);
   }

   private function fail(string $message, int $code): void {
      http_response_code($code);
      header('Content-Type: application/json; charset=UTF-8');
      header('Cache-Control: no-store');

      $payload = ['success' => false, 'error' => $message];
      // GLPI has already spent the single-use CSRF token on this POST, so hand
      // back a fresh one: otherwise the browser goes on posting with a dead
      // token and every further write fails until the page is reloaded.
      if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
         $payload['csrf_token'] = Session::getNewCSRFToken();
      }

      echo json_encode($payload);
      exit;
   }
}