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

class PluginKanbanKanban extends CommonGLPI {

   /**
    * Retrieve tickets formatted and filtered for the Kanban board.
    *
    * @param array $filters Filters (groups, technicians, requesters)
    * @param array $sort Sorting criteria (priority, date, status_duration)
    * @return array Tickets grouped by status ID
    */
   public static function getTicketsForKanban(array $filters = [], array $sort = []) {
      global $DB;

      // Basic visibility check
      if (!Ticket::canView()) {
         return [];
      }

      $tickets_by_status = [];
      
      // Initialize status buckets (standard GLPI Ticket status values 1 to 6)
      // 1: New, 2: Assigned, 3: Planned, 4: Pending, 5: Solved, 6: Closed
      $statuses = [
         Ticket::INCOMING => [],
         Ticket::ASSIGNED => [],
         Ticket::PLANNED  => [],
         Ticket::WAITING  => [],
         Ticket::SOLVED   => [],
         Ticket::CLOSED   => []
      ];

      // Build criteria
      $criteria = [
         'SELECT' => [
            't.id',
            't.name AS title',
            't.status',
            't.priority',
            't.date AS date_creation',
            't.date_mod',
            't.time_to_resolve',
            't.solve_delay_stat',
            'cat.name AS category'
         ],
         'FROM' => 'glpi_tickets AS t',
         'LEFT JOIN' => [
            'glpi_itilcategories AS cat' => [
               'ON' => [
                  't'   => 'itilcategories_id',
                  'cat' => 'id'
               ]
            ]
         ],
         'WHERE' => [
            't.is_deleted' => 0
         ]
      ];

      // Enforce active entity restrictions
      $criteria['WHERE'][] = getEntitiesRestrictCriteria('t', '', $_SESSION['glpiactiveentities'], true);

      // Apply Technician filter (joins glpi_tickets_users for ASSIGN type)
      if (!empty($filters['technician'])) {
         $criteria['INNER JOIN']['glpi_tickets_users AS tu_tech'] = [
            'ON' => [
               't'       => 'id',
               'tu_tech' => 'tickets_id'
            ]
         ];
         $criteria['WHERE']['tu_tech.users_id'] = (int)$filters['technician'];
         $criteria['WHERE']['tu_tech.type'] = CommonITILActor::ASSIGN;
      }

      // Apply Requester filter (joins glpi_tickets_users for REQUESTER type)
      if (!empty($filters['requester'])) {
         $criteria['INNER JOIN']['glpi_tickets_users AS tu_req'] = [
            'ON' => [
               't'      => 'id',
               'tu_req' => 'tickets_id'
            ]
         ];
         $criteria['WHERE']['tu_req.users_id'] = (int)$filters['requester'];
         $criteria['WHERE']['tu_req.type'] = CommonITILActor::REQUESTER;
      }

      // Apply Group filter (joins glpi_groups_tickets)
      if (!empty($filters['group'])) {
         $criteria['INNER JOIN']['glpi_groups_tickets AS gt'] = [
            'ON' => [
               't'  => 'id',
               'gt' => 'tickets_id'
            ]
         ];
         $criteria['WHERE']['gt.groups_id'] = (int)$filters['group'];
      }

      // Apply Sorting
      $sort_by = $sort['by'] ?? 'date';
      $sort_order = $sort['order'] ?? 'DESC';
      $allowed_orders = ['ASC', 'DESC'];
      if (!in_array(strtoupper($sort_order), $allowed_orders)) {
         $sort_order = 'DESC';
      }

      switch ($sort_by) {
         case 'priority':
            $criteria['ORDER'] = "t.priority $sort_order";
            break;
         case 'status_duration':
            // Approximate duration in status using modified date
            $criteria['ORDER'] = "t.date_mod $sort_order";
            break;
         case 'date':
         default:
            $criteria['ORDER'] = "t.date $sort_order";
            break;
      }

      $iterator = $DB->request($criteria);

      foreach ($iterator as $ticket) {
         $status = $ticket['status'];
         if (!isset($statuses[$status])) {
            $statuses[$status] = [];
         }

         // Fetch assigned technicians details for metadata
         $ticket['assigned_techs'] = self::getAssignedTechnicians($ticket['id']);
         
         // Calculate SLA progress
         $ticket['sla_progress'] = self::calculateSlaProgress($ticket);

         $statuses[$status][] = $ticket;
      }

      return $statuses;
   }

   /**
    * Fetch technicians assigned to a specific ticket
    *
    * @param int $ticket_id
    * @return array
    */
   private static function getAssignedTechnicians($ticket_id) {
      global $DB;
      $techs = [];
      $iterator = $DB->request([
         'SELECT'    => ['u.id', 'u.realname', 'u.firstname'],
         'FROM'      => 'glpi_tickets_users AS tu',
         'LEFT JOIN' => [
            'glpi_users AS u' => [
               'ON' => [
                  'tu' => 'users_id',
                  'u'  => 'id'
               ]
            ]
         ],
         'WHERE'     => [
            'tu.tickets_id' => $ticket_id,
            'tu.type'       => CommonITILActor::ASSIGN
         ]
      ]);

      foreach ($iterator as $user) {
         if ($user['id']) {
            $techs[] = getUserName($user['id']);
         }
      }
      return $techs;
   }

   /**
    * Calculate SLA progress percentage and color class
    *
    * @param array $ticket
    * @return array ['percent' => int, 'color' => string]
    */
   private static function calculateSlaProgress(array $ticket) {
      if (empty($ticket['time_to_resolve'])) {
         return ['percent' => 0, 'color' => 'success', 'status' => 'no_sla'];
      }

      // Use GLPI's internal current time session to avoid timezone mismatch
      $now = isset($_SESSION['glpi_currenttime']) ? strtotime($_SESSION['glpi_currenttime']) : time();
      $created = strtotime($ticket['date_creation']);
      $deadline = strtotime($ticket['time_to_resolve']);

      if ($deadline <= $created) {
         return ['percent' => 100, 'color' => 'danger', 'status' => 'overdue'];
      }

      $total_sla = $deadline - $created;
      $elapsed = $now - $created;

      if ($elapsed < 0) {
         $elapsed = 0;
      }

      $percent = round(($elapsed / $total_sla) * 100);

      $color = 'success';
      if ($percent >= 100) {
         $percent = 100;
         $color = 'danger';
      } elseif ($percent >= 80) {
         $color = 'warning';
      }

      return [
         'percent' => $percent,
         'color'   => $color,
         'status'  => $percent >= 100 ? 'overdue' : 'active'
      ];
   }
}
