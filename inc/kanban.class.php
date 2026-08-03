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

   /** Maximum tickets per status to prevent performance issues */
   const MAX_TICKETS_PER_STATUS = 200;

   /**
    * Retrieve tickets formatted and filtered for the Kanban board.
    *
    * @param array $filters Filters (groups, technicians, requesters)
    * @param array $sort Sorting criteria (priority, date, status_duration)
    * @param int $limit Max tickets per status (0 = use default MAX_TICKETS_PER_STATUS)
    * @return array Tickets grouped by status ID
    */
   public static function getTicketsForKanban(array $filters = [], array $sort = [], int $limit = 0) {
      global $DB;

      // Basic visibility check
      if (!Ticket::canView()) {
         return [];
      }

      if ($limit <= 0) {
         $limit = self::MAX_TICKETS_PER_STATUS;
      }

// Build criteria
       $criteria = [
          'SELECT' => [
             't.id',
             't.name AS title',
         't.status',
         't.priority',
         't.date AS date',
         't.date_creation',
         't.date_mod',
         't.time_to_resolve',
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
          ],
          'LIMIT' => $limit * 6 // Multiply by number of status columns for fair distribution
       ];

      // Enforce active entity restrictions
      // Do not use recursive criteria here: glpiactiveentities already contains the active entity
      // expanded with its children, and glpi_tickets has no is_recursive column. Forcing recursive
      // would generate `t.is_recursive` in the query and break multi-entity setups.
      $active_entities = $_SESSION['glpiactiveentities'] ?? [0];
      $criteria['WHERE'][] = getEntitiesRestrictCriteria('t', 'entities_id', $active_entities, false);

      // Enforce item-level visibility (mirrors Ticket::canViewItem()).
      $visibility = self::getTicketVisibilityCriteria();
      if ($visibility !== null) {
         $criteria['WHERE'][] = $visibility;
      }

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

      // Apply Group filter (joins glpi_groups_tickets).
      // When a group is selected, include the group and all of its subgroups.
      if (!empty($filters['group'])) {
         $group_id = (int)$filters['group'];
         $group_ids = getSonsOf('glpi_groups', $group_id);
         if (empty($group_ids)) {
            $group_ids = [$group_id => $group_id];
         }
         $criteria['INNER JOIN']['glpi_groups_tickets AS gt'] = [
            'ON' => [
               't'  => 'id',
               'gt' => 'tickets_id'
            ]
         ];
         $criteria['WHERE']['gt.groups_id'] = array_values($group_ids);
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

// Group tickets by status, enforcing per-status limit
       $statuses = [];
       $counts = [];
       $all_statuses = Ticket::getAllStatusArray();
       foreach ($iterator as $ticket) {
          $status = (int)$ticket['status'];
          if (!isset($statuses[$status])) {
             $statuses[$status] = [];
             $counts[$status] = 0;
          }

          // Enforce per-status limit
          if ($counts[$status] >= $limit) {
             continue;
          }

          // Add status name for display
          $ticket['status_name'] = $all_statuses[$status] ?? __('Unknown status', 'kanban');

          // Add requester name
          $ticket['requester_name'] = self::getTicketRequesterName($ticket['id']);

          // Fetch assigned technicians details for metadata
          $ticket['assigned_techs'] = self::getAssignedTechnicians($ticket['id']);

          // Calculate SLA progress
          $ticket['sla_progress'] = self::calculateSlaProgress($ticket);

          $statuses[$status][] = $ticket;
          $counts[$status]++;
       }

       return $statuses;
   }

   /**
     * Build the SQL restriction that mirrors Ticket::canViewItem().
     *
     * Users without the READALL right may only see tickets involving them:
     *  - READMY: as recipient, requester or observer;
     *  - READGROUP: through one of their groups as requester or observer;
     *  - READASSIGN: assigned to them or to one of their groups, or incoming
     *    tickets when they also hold the ASSIGN right;
     *  - ticketvalidation: tickets they are allowed to validate.
     *
     * @return \QueryExpression|null WHERE expression (AND-ed with the rest of
     *         the criteria) or null when no restriction applies (READALL right).
     */
    private static function getTicketVisibilityCriteria() {
       $user_id = (int)Session::getLoginUserID();
       if (!$user_id) {
          return new \QueryExpression('FALSE');
       }

       // Full read right: no additional restriction (entity scope already applied).
       if (Session::haveRight('ticket', Ticket::READALL)) {
          return null;
       }

       $my_groups = $_SESSION['glpigroups'] ?? [];
       if (!is_array($my_groups)) {
          $my_groups = [];
       }
       $my_groups = array_values(array_map('intval', $my_groups));

       $conditions = [];

       // READMY: user is the recipient, a requester or an observer.
       if (Session::haveRight('ticket', Ticket::READMY)) {
          $conditions[] = "t.users_id_recipient = $user_id";
          $conditions[] = 't.id IN (SELECT DISTINCT tu.tickets_id FROM glpi_tickets_users AS tu WHERE tu.users_id = '
             . $user_id . ' AND tu.type IN (' . CommonITILActor::REQUESTER . ',' . CommonITILActor::OBSERVER . '))';
       }

       // READGROUP: one of the user's groups is requester or observer.
       if (Session::haveRight('ticket', Ticket::READGROUP) && !empty($my_groups)) {
          $conditions[] = 't.id IN (SELECT DISTINCT gt.tickets_id FROM glpi_groups_tickets AS gt WHERE gt.groups_id IN ('
             . implode(',', $my_groups) . ') AND gt.type IN (' . CommonITILActor::REQUESTER . ',' . CommonITILActor::OBSERVER . '))';
       }

       // READASSIGN: assigned to the user, to one of their groups, or assignable incoming tickets.
       if (Session::haveRight('ticket', Ticket::READASSIGN)) {
          $conditions[] = 't.id IN (SELECT DISTINCT tu.tickets_id FROM glpi_tickets_users AS tu WHERE tu.users_id = '
             . $user_id . ' AND tu.type = ' . CommonITILActor::ASSIGN . ')';
          if (!empty($my_groups)) {
             $conditions[] = 't.id IN (SELECT DISTINCT gt.tickets_id FROM glpi_groups_tickets AS gt WHERE gt.groups_id IN ('
                . implode(',', $my_groups) . ') AND gt.type = ' . CommonITILActor::ASSIGN . ')';
          }
          if (Session::haveRight('ticket', Ticket::ASSIGN)) {
             $conditions[] = 't.status = ' . (int)Ticket::INCOMING;
          }
       }

       // Ticket validation: tickets where the user is the assigned validator.
       if (Session::haveRightsOr('ticketvalidation', TicketValidation::getValidateRights())) {
          $conditions[] = 't.id IN (SELECT DISTINCT tv.tickets_id FROM glpi_ticketvalidations AS tv WHERE tv.users_id_validate = '
             . $user_id . ')';
       }

       if (empty($conditions)) {
          // No item-level right: the user cannot view any ticket.
          return new \QueryExpression('FALSE');
       }

       return new \QueryExpression('(' . implode(' OR ', $conditions) . ')');
    }

   /**
     * Get list of technicians for filter dropdown
     *
     * @return array Array of ['id' => int, 'name' => string]
     */
    public static function getTechniciansForFilter(): array {
       global $DB;
       $users = [];
       $iterator = $DB->request([
          'SELECT'    => ['u.id', 'u.realname', 'u.firstname'],
          'FROM'      => 'glpi_users AS u',
          'INNER JOIN' => [
             'glpi_tickets_users AS tu' => [
                'ON' => [
                   'u'  => 'id',
                   'tu' => 'users_id'
                ]
             ]
          ],
          'WHERE'     => [
             'u.is_deleted' => 0,
             'u.is_active'  => 1,
             'tu.type'      => CommonITILActor::ASSIGN,
          ],
          'DISTINCT'  => true,
          'ORDER'     => 'u.realname ASC',
          'LIMIT'     => 500
       ]);
       foreach ($iterator as $user) {
          $users[] = [
             'id'   => (int)$user['id'],
             'name' => getUserName($user['id'])
          ];
       }
       return $users;
    }

    /**
     * Get list of requesters for filter dropdown
     *
     * @return array Array of ['id' => int, 'name' => string]
     */
    public static function getRequestersForFilter(): array {
       global $DB;
       $requesters = [];
       $iterator = $DB->request([
          'SELECT' => ['id', 'realname', 'firstname'],
          'FROM'   => 'glpi_users',
          'WHERE'  => [
             'is_deleted' => 0,
             'is_active'  => 1,
          ],
          'ORDER'  => 'realname ASC',
          'LIMIT'  => 500
       ]);
       foreach ($iterator as $user) {
          $requesters[] = [
             'id'   => (int)$user['id'],
             'name' => getUserName($user['id'])
          ];
       }
       return $requesters;
    }

    /**
     * Get list of groups for filter dropdown
     *
     * @return array Array of ['id' => int, 'name' => string]
     */
    public static function getGroupsForFilter(): array {
       global $DB;
       $groups = [];
       $iterator = $DB->request([
          'SELECT' => ['id', 'name'],
          'FROM'   => 'glpi_groups',
          'WHERE'  => getEntitiesRestrictCriteria('glpi_groups', '', $_SESSION['glpiactiveentities'], true),
          'ORDER'  => 'name ASC',
          'LIMIT'  => 500
       ]);
       foreach ($iterator as $group) {
          $groups[] = [
             'id'   => (int)$group['id'],
             'name' => $group['name']
          ];
       }
       return $groups;
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
     * Get the requester name for a specific ticket
     *
     * @param int $ticket_id
     * @return string
     */
    private static function getTicketRequesterName($ticket_id) {
       global $DB;
       $requester = '';
       $iterator = $DB->request([
          'SELECT' => ['u.id', 'u.realname', 'u.firstname'],
          'FROM'   => 'glpi_users AS u',
          'INNER JOIN' => [
             'glpi_tickets_users AS tu' => [
                'ON' => [
                   'u'  => 'id',
                   'tu' => 'users_id'
                ]
             ]
          ],
          'WHERE'  => [
             'tu.tickets_id' => $ticket_id,
             'tu.type'       => CommonITILActor::REQUESTER
          ],
          'LIMIT'  => 1
       ]);

       foreach ($iterator as $user) {
          if ($user['id']) {
             $requester = getUserName($user['id']);
          }
       }
       return $requester;
    }

    /**
     * Get detailed ticket data for modal display
     *
     * @param int $ticket_id
     * @return array|null
     */
    public static function getTicketDetail($ticket_id) {
       global $DB;

       if (!Ticket::canView()) {
          return null;
       }

       $ticket_id = (int)$ticket_id;

       $criteria = [
          'SELECT' => [
             't.id',
             't.name AS title',
             't.content',
             't.status',
             't.priority',
             't.urgency',
             't.impact',
             't.date AS date_creation',
             't.date_mod',
             't.time_to_resolve',
             't.actiontime',
             'cat.name AS category',
             't.entities_id'
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
             't.id'         => $ticket_id,
             't.is_deleted' => 0
          ]
       ];

       // Enforce active entity restrictions (non-recursive, see getTicketsForKanban)
       $active_entities = $_SESSION['glpiactiveentities'] ?? [0];
       $criteria['WHERE'][] = getEntitiesRestrictCriteria('t', 'entities_id', $active_entities, false);

       // Enforce item-level visibility (mirrors Ticket::canViewItem()).
       $visibility = self::getTicketVisibilityCriteria();
       if ($visibility !== null) {
          $criteria['WHERE'][] = $visibility;
       }

       $iterator = $DB->request($criteria);

       $ticket = null;
       foreach ($iterator as $row) {
          $ticket = $row;
          break;
       }
       if ($ticket === null) {
          return null;
       }

       $status = (int)$ticket['status'];
       $all_statuses = Ticket::getAllStatusArray();

       $ticket['status_name'] = $all_statuses[$status] ?? __('Unknown status', 'kanban');
       $ticket['priority_label'] = Ticket::getPriorityName($ticket['priority'] ?? 0);
       $ticket['urgency_label'] = Ticket::getUrgencyName($ticket['urgency'] ?? 0);
       $ticket['impact_label'] = Ticket::getImpactName($ticket['impact'] ?? 0);
       $ticket['assigned_techs'] = self::getAssignedTechnicians($ticket_id);
       $ticket['requester_name'] = self::getTicketRequesterName($ticket_id);
       $ticket['sla_progress'] = self::calculateSlaProgress($ticket);
       $ticket['date_creation_formatted'] = Html::convDateTime($ticket['date_creation']);

       return $ticket;
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

   /**
     * Get translated sort option labels for the frontend
     *
     * @return array Array of sort value => translated label
     */
   public static function getSortOptions(): array {
      return [
         'date_DESC'             => __('Date Open (Newest first)', 'kanban'),
         'date_ASC'              => __('Date Open (Oldest first)', 'kanban'),
         'priority_DESC'         => __('Priority (High to Low)', 'kanban'),
         'priority_ASC'          => __('Priority (Low to High)', 'kanban'),
         'status_duration_DESC'  => __('Time in Status (Longest first)', 'kanban'),
         'status_duration_ASC'   => __('Time in Status (Shortest first)', 'kanban'),
      ];
   }
}
