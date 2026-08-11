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

   /** Right name that gates access to the Kanban page */
   public static $rightname = 'plugin_kanban';

   /** Maximum tickets per status to prevent performance issues */
   const MAX_TICKETS_PER_STATUS = 200;

   /**
    * Define the rights displayed in the profile configuration.
    *
    * @param string $interface Interface ('central' or 'helpdesk')
    * @return array
    */
   public static function getRights($interface = 'central') {
      return [
         READ => __('View the kanban board', 'kanban'),
      ];
   }

    /**
     * Retrieve tickets formatted and filtered for the Kanban board.
     *
     * @param array $filters Filters (groups, technicians, requesters, category, ...)
     * @param array $sort Sorting criteria (priority, date, status_duration)
     * @param int $limit Max tickets per status (0 = use default MAX_TICKETS_PER_STATUS)
     * @return array Tickets grouped by status ID
     */
   public static function getTicketsForKanban(array $filters = [], array $sort = [], int $limit = 0) {
      global $DB;

      // Basic visibility check: user must have the plugin right and be able to view tickets
      if (!self::canView() || !Ticket::canView()) {
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
          't.type',
          't.date AS date',
          't.date_creation',
           't.date_mod',
           't.time_to_resolve',
           't.solvedate',
           't.begin_waiting_date',
           't.content',
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

      // Apply Ticket number search (matches the ticket id by its digits).
      // A leading "#" or any non-digit character is ignored, so typing
      // "12" finds tickets #12, #120, #512... as long as they are visible.
      if (!empty($filters['ticket_id'])) {
         $search = preg_replace('/\D/', '', (string)$filters['ticket_id']);
         if ($search !== '') {
            $criteria['WHERE']['t.id'] = ['LIKE', '%' . $search . '%'];
         }
      }

      // Apply Ticket type filter (incident or request)
      if (!empty($filters['type'])) {
         $type = (int)$filters['type'];
         $allowed_types = [
            defined('Ticket::INCIDENT_TYPE') ? Ticket::INCIDENT_TYPE : Ticket::INCIDENT,
            defined('Ticket::DEMAND_TYPE') ? Ticket::DEMAND_TYPE : Ticket::DEMAND,
         ];
         if (in_array($type, $allowed_types)) {
            $criteria['WHERE']['t.type'] = $type;
         }
      }

      // Apply Category filter (joins nothing extra: glpi_itilcategories is
      // already LEFT JOINed for the category name). When a category is
      // selected, include the category and all of its subcategories.
      if (!empty($filters['category'])) {
         $category_id = (int)$filters['category'];
         $category_ids = getSonsOf('glpi_itilcategories', $category_id);
         if (empty($category_ids)) {
            $category_ids = [$category_id => $category_id];
         }
         $criteria['WHERE']['t.itilcategories_id'] = array_values($category_ids);
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
          case 'sla':
             // Approximate SLA urgency by resolution deadline. The client
             // sorts by elapsed percent (DESC = most elapsed / closest to
             // expiry first), so the deadline order is inverted here: DESC
             // fetches the earliest deadlines first.
             $criteria['ORDER'] = "t.time_to_resolve " . ($sort_order === 'ASC' ? 'DESC' : 'ASC');
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

           // Decode GLPI-sanitized text fields before sending them to the frontend
           $ticket['title']    = self::unsanitizeOutput($ticket['title']);
           $ticket['content']  = self::unsanitizeOutput($ticket['content']);
           $ticket['category'] = self::unsanitizeOutput($ticket['category']);

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
     * Build the SQL restriction that shows each user only their service queue.
     *
     * The restriction applies to every profile, regardless of profile rights
     * (including READALL). A ticket is visible when any of the following holds:
     *  - the user is assigned to it as technician (ASSIGN);
     *  - one of the user's groups (including subgroups) is assigned to it,
     *    even when no individual technician is assigned yet, so the user can
     *    self-assign it;
     *  - the user manages a group (glpi_groups_users.is_manager) and the ticket
     *    is assigned to any member of the managed groups (including subgroups).
     *
     * Tickets where the user or one of their groups is only an observer are
     * never visible.
     *
     * @return \QueryExpression WHERE expression (AND-ed with the rest of the
     *         criteria). Always a restriction (never null).
     */
    private static function getTicketVisibilityCriteria() {
       $user_id = (int)Session::getLoginUserID();
       if (!$user_id) {
          return new \QueryExpression('FALSE');
       }

       $conditions = [];

       // Ticket assigned to the user as technician.
       $conditions[] = 't.id IN (SELECT DISTINCT tu.tickets_id FROM glpi_tickets_users AS tu WHERE tu.users_id = '
          . $user_id . ' AND tu.type = ' . CommonITILActor::ASSIGN . ')';

       // Tickets assigned to one of the user's groups (including subgroups).
       $my_groups = self::getUserGroupIds($user_id);
       if (!empty($my_groups)) {
          $conditions[] = 't.id IN (SELECT DISTINCT gt.tickets_id FROM glpi_groups_tickets AS gt WHERE gt.groups_id IN ('
             . implode(',', $my_groups) . ') AND gt.type = ' . CommonITILActor::ASSIGN . ')';
       }

       // Group managers: tickets assigned to any member of the managed groups (including subgroups).
       $managed_members = self::getManagedGroupMemberIds($user_id);
       if (!empty($managed_members)) {
          $conditions[] = 't.id IN (SELECT DISTINCT tu.tickets_id FROM glpi_tickets_users AS tu WHERE tu.type = '
             . CommonITILActor::ASSIGN . ' AND tu.users_id IN (' . implode(',', $managed_members) . '))';
       }

       return new \QueryExpression('(' . implode(' OR ', $conditions) . ')');
    }

   /**
     * Expand a list of group ids with all of their subgroups (nested).
     *
     * @param array $group_ids
     * @return int[]
     */
    private static function expandGroupIds(array $group_ids): array {
       $expanded = [];
       foreach (array_filter(array_map('intval', $group_ids)) as $gid) {
          $sons = getSonsOf('glpi_groups', $gid);
          if (empty($sons)) {
             $sons = [$gid => $gid];
          }
          foreach ($sons as $sid) {
             $expanded[(int)$sid] = (int)$sid;
          }
       }
       return array_values($expanded);
    }

    /**
     * Decode GLPI-sanitized data before sending it to the frontend.
     *
     * GLPI stores user input with HTML entities (e.g. ">" is stored as "&#62;").
     * Fields read via raw SQL must be unsanitized on output, otherwise titles
     * show "&#62;" and descriptions show raw HTML code.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function unsanitizeOutput($value) {
       return \Glpi\Toolbox\Sanitizer::unsanitize($value);
    }

    /**
     * Get the ids of all groups the current user belongs to (+ subgroups).
     *
     * Groups are read from glpi_groups_users at query time instead of from
     * $_SESSION['glpigroups'], so newly added memberships are honored without
     * re-login and groups outside the currently active entities are not lost.
     *
     * @param int $user_id
     * @return int[]
     */
    private static function getUserGroupIds(int $user_id): array {
       global $DB;

       $group_ids = [];
       $iterator = $DB->request([
          'SELECT' => ['groups_id'],
          'FROM'   => 'glpi_groups_users',
          'WHERE'  => ['users_id' => $user_id],
       ]);
       foreach ($iterator as $row) {
          $group_ids[(int)$row['groups_id']] = (int)$row['groups_id'];
       }

       return self::expandGroupIds(array_keys($group_ids));
    }

   /**
     * Get the ids of all members of the groups the user manages (+ subgroups).
     *
     * @param int $user_id
     * @return int[]
     */
    private static function getManagedGroupMemberIds(int $user_id): array {
       global $DB;

       $managed = [];
       $iterator = $DB->request([
          'SELECT' => ['groups_id'],
          'FROM'   => 'glpi_groups_users',
          'WHERE'  => ['users_id' => $user_id, 'is_manager' => 1],
       ]);
       foreach ($iterator as $row) {
          $managed[(int)$row['groups_id']] = (int)$row['groups_id'];
       }

       $managed = self::expandGroupIds(array_keys($managed));
       if (empty($managed)) {
          return [];
       }

       $members = [];
       $iterator = $DB->request([
          'SELECT'    => ['users_id'],
          'FROM'      => 'glpi_groups_users',
          'WHERE'     => ['groups_id' => $managed],
          'DISTINCT'  => true,
       ]);
       foreach ($iterator as $row) {
          $members[(int)$row['users_id']] = (int)$row['users_id'];
       }
       return array_values($members);
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
               'name' => self::unsanitizeOutput(getUserName($user['id']))
            ];
         }
         return $users;
      }

     /**
      * Get technicians (users) who are members of a group or any of its subgroups.
     *
     * @param int $group_id
     * @return array Array of ['id' => int, 'name' => string]
     */
    public static function getTechniciansForGroup(int $group_id): array {
       global $DB;
       $group_ids = getSonsOf('glpi_groups', $group_id);
       if (empty($group_ids)) {
          $group_ids = [$group_id => $group_id];
       }
       $users = [];
       $iterator = $DB->request([
          'SELECT'     => ['u.id', 'u.realname', 'u.firstname'],
          'FROM'       => 'glpi_users AS u',
          'INNER JOIN' => [
             'glpi_groups_users AS gu' => [
                'ON' => [
                   'u'  => 'id',
                   'gu' => 'users_id'
                ]
             ]
          ],
          'WHERE'      => [
             'gu.groups_id' => array_values($group_ids),
             'u.is_deleted' => 0,
             'u.is_active'  => 1,
          ],
          'DISTINCT'   => true,
          'ORDER'      => 'u.realname ASC',
          'LIMIT'      => 500
       ]);
        foreach ($iterator as $user) {
           $users[] = [
              'id'   => (int)$user['id'],
              'name' => self::unsanitizeOutput(getUserName($user['id']))
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
              'name' => self::unsanitizeOutput(getUserName($user['id']))
           ];
        }
        return $requesters;
    }

    /**
     * Get list of groups for filter dropdown.
     *
     * Only the groups the current user belongs to are returned.
     *
     * @return array Array of ['id' => int, 'name' => string]
     */
    public static function getGroupsForFilter(): array {
       global $DB;
       $user_id = (int)Session::getLoginUserID();
       if ($user_id <= 0) {
          return [];
       }
       $groups = [];
       $criteria = [
          'SELECT' => ['glpi_groups.id', 'glpi_groups.name'],
          'FROM'   => 'glpi_groups',
          'INNER JOIN' => [
             'glpi_groups_users AS gu' => [
                'ON' => [
                   'glpi_groups' => 'id',
                   'gu'          => 'groups_id'
                ]
             ]
          ],
          'WHERE'  => ['gu.users_id' => $user_id],
          'DISTINCT' => true,
          'ORDER'  => 'name ASC',
          'LIMIT'  => 500
       ];
       $entity_restriction = getEntitiesRestrictCriteria('glpi_groups', '', $_SESSION['glpiactiveentities'], true);
       if (!empty($entity_restriction)) {
          $criteria['WHERE'][] = $entity_restriction;
       }
        $iterator = $DB->request($criteria);
        foreach ($iterator as $group) {
           $groups[] = [
              'id'   => (int)$group['id'],
              'name' => self::unsanitizeOutput($group['name'])
           ];
        }
        return $groups;
     }

    /**
     * Get list of categories for the filter dropdown.
     *
     * Only the categories of the active entities are returned.
     *
     * @return array Array of ['id' => int, 'name' => string]
     */
    public static function getCategoriesForFilter(): array {
       global $DB;
       $categories = [];
       $criteria = [
          'SELECT' => ['id', 'name'],
          'FROM'   => 'glpi_itilcategories',
          'ORDER'  => 'name ASC',
          'LIMIT'  => 500
       ];
       if ($DB->fieldExists('glpi_itilcategories', 'is_deleted')) {
          $criteria['WHERE']['is_deleted'] = 0;
       }
       $entity_restriction = getEntitiesRestrictCriteria('glpi_itilcategories', '', $_SESSION['glpiactiveentities'] ?? [0], true);
       if (!empty($entity_restriction)) {
          $criteria['WHERE'][] = $entity_restriction;
       }
        $iterator = $DB->request($criteria);
        foreach ($iterator as $category) {
           $categories[] = [
              'id'   => (int)$category['id'],
              'name' => self::unsanitizeOutput($category['name'])
           ];
        }
        return $categories;
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
              $techs[] = self::unsanitizeOutput(getUserName($user['id']));
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
              $requester = self::unsanitizeOutput(getUserName($user['id']));
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

      if (!self::canView() || !Ticket::canView()) {
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
             't.type',
             't.urgency',
             't.impact',
             't.date AS date_creation',
              't.date_mod',
              't.time_to_resolve',
              't.solvedate',
              't.begin_waiting_date',
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

       // Decode GLPI-sanitized text fields before sending them to the frontend
       $ticket['title']    = self::unsanitizeOutput($ticket['title']);
       $ticket['content']  = self::unsanitizeOutput($ticket['content']);
       $ticket['category'] = self::unsanitizeOutput($ticket['category']);

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
    * The SLA clock only runs while a ticket is actually being worked on:
    *  - solved/closed tickets stop counting at the resolution time (solvedate);
    *  - pending (waiting) tickets stop counting while they are waiting, the
    *    clock resumes from where it stopped when the ticket leaves pending;
    *  - every other status keeps counting in real time.
    *
    * When the clock is stopped, the returned payload is flagged as "frozen"
    * and carries the frozen reference timestamp so the frontend can display a
    * static bar/countdown (while the ticket's open duration keeps running).
    *
    * @param array $ticket
    * @return array ['percent' => int, 'color' => string, 'status' => string,
    *                'frozen' => bool, 'frozen_at' => string|null]
    */
   private static function calculateSlaProgress(array $ticket) {
      if (empty($ticket['time_to_resolve'])) {
         return ['percent' => 0, 'color' => 'success', 'status' => 'no_sla', 'frozen' => false, 'frozen_at' => null];
      }

      // Use GLPI's internal current time session to avoid timezone mismatch
      $now = isset($_SESSION['glpi_currenttime']) ? strtotime($_SESSION['glpi_currenttime']) : time();
      $created = strtotime($ticket['date_creation']);
      $deadline = strtotime($ticket['time_to_resolve']);

      // Reference timestamp used to compute the elapsed time. Defaults to now.
      $reference = $now;
      $frozen = false;

      $status = (int)($ticket['status'] ?? 0);
      if (in_array($status, [Ticket::SOLVED, Ticket::CLOSED])) {
         // Frozen at the resolution time (fallback to the last modification).
         $freeze = self::getFreezeReference($ticket, ['solvedate', 'date_mod']);
         if ($freeze !== null) {
            $reference = $freeze;
            $frozen = true;
         }
      } elseif ($status === Ticket::WAITING) {
         // SLA is paused while the ticket is pending.
         $freeze = self::getFreezeReference($ticket, ['begin_waiting_date']);
         if ($freeze !== null) {
            $reference = $freeze;
            $frozen = true;
         }
      }

      if ($deadline <= $created) {
         return [
            'percent' => 100,
            'color'   => 'danger',
            'status'  => 'overdue',
            'frozen'  => $frozen,
            'frozen_at' => $frozen ? date('Y-m-d H:i:s', $reference) : null,
         ];
      }

      $total_sla = $deadline - $created;
      $elapsed = $reference - $created;

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
         'status'  => $percent >= 100 ? 'overdue' : 'active',
         'frozen'  => $frozen,
         'frozen_at' => $frozen ? date('Y-m-d H:i:s', $reference) : null,
      ];
   }

   /**
    * Resolve the timestamp at which the SLA clock stopped.
    *
    * The first non-empty, parseable datetime of the given fields wins.
    *
    * @param array $ticket
    * @param string[] $fields
    * @return int|null Unix timestamp, or null when no usable value exists
    */
   private static function getFreezeReference(array $ticket, array $fields): ?int {
      foreach ($fields as $field) {
         if (!empty($ticket[$field])) {
            $ts = strtotime($ticket[$field]);
            if ($ts > 0) {
               return $ts;
            }
         }
      }
      return null;
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
          'sla_DESC'              => __('SLA (Closest to expiry first)', 'kanban'),
          'sla_ASC'               => __('SLA (Farthest from expiry first)', 'kanban'),
       ];
   }
}
