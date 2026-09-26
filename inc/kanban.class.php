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
      if (isset($filters['technician']) && $filters['technician'] !== null) {
         $tech_id = (int)$filters['technician'];
         if ($tech_id > 0) {
            $criteria['INNER JOIN']['glpi_tickets_users AS tu_tech'] = [
               'ON' => [
                  't'       => 'id',
                  'tu_tech' => 'tickets_id'
               ]
            ];
            $criteria['WHERE']['tu_tech.users_id'] = $tech_id;
            $criteria['WHERE']['tu_tech.type'] = CommonITILActor::ASSIGN;
         } else {
            // "No Technician" – tickets with no assigned technician
            $criteria['LEFT JOIN']['glpi_tickets_users AS tu_tech_none'] = [
               'ON' => [
                  't'              => 'id',
                  'tu_tech_none'   => 'tickets_id',
                  ['AND' => ['tu_tech_none.type' => CommonITILActor::ASSIGN]]
               ]
            ];
            $criteria['WHERE'][] = ['tu_tech_none.tickets_id' => null];
         }
      }

      // Apply Requester filter (joins glpi_tickets_users for REQUESTER type)
      if (isset($filters['requester']) && $filters['requester'] !== null) {
         $req_id = (int)$filters['requester'];
         if ($req_id > 0) {
            $criteria['INNER JOIN']['glpi_tickets_users AS tu_req'] = [
               'ON' => [
                  't'      => 'id',
                  'tu_req' => 'tickets_id'
               ]
            ];
            $criteria['WHERE']['tu_req.users_id'] = $req_id;
            $criteria['WHERE']['tu_req.type'] = CommonITILActor::REQUESTER;
         } else {
            // "No Requester" – tickets with no requester
            $criteria['LEFT JOIN']['glpi_tickets_users AS tu_req_none'] = [
               'ON' => [
                  't'             => 'id',
                  'tu_req_none'   => 'tickets_id',
                  ['AND' => ['tu_req_none.type' => CommonITILActor::REQUESTER]]
               ]
            ];
            $criteria['WHERE'][] = ['tu_req_none.tickets_id' => null];
         }
      }

      // Apply Group filter (joins glpi_groups_tickets).
      // When a group is selected, include the group and all of its subgroups.
      if (isset($filters['group']) && $filters['group'] !== null) {
         $group_id = (int)$filters['group'];
         if ($group_id > 0) {
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
         } else {
            // "No Group" – tickets with no group assigned
            $criteria['LEFT JOIN']['glpi_groups_tickets AS gt_none'] = [
               'ON' => [
                  't'        => 'id',
                  'gt_none'  => 'tickets_id'
               ]
            ];
            $criteria['WHERE'][] = ['gt_none.tickets_id' => null];
         }
      }

      // Exact ticket-number filter: match tickets whose id contains the typed
      // digits (a leading "#" or any  other non-digit character is ignored).
      if (isset($filters['ticket_id']) && $filters['ticket_id'] !== null && $filters['ticket_id'] !== '') {
         $ticket_number = preg_replace('/\D/', '', (string)$filters['ticket_id']);
         if ($ticket_number !== '') {
            $criteria['WHERE']['t.id'] = ['LIKE', '%' . $ticket_number . '%'];
         }
      }

      // Apply omnichannel search: matches ticket number, title, category or
      // description. A leading "#" or any non-digit character is ignored for
      // the numeric part, so typing "12" still finds tickets #12, #120, #512...
      if (!empty($filters['search'])) {
         $term = trim((string)$filters['search']);
         if ($term !== '') {
            $numeric = preg_replace('/\D/', '', $term);
            $conditions = [
               'OR' => [
                  't.name'    => ['LIKE', '%' . $term . '%'],
                  't.content' => ['LIKE', '%' . $term . '%'],
                  'cat.name'  => ['LIKE', '%' . $term . '%'],
               ]
            ];
            if ($numeric !== '') {
               $conditions['OR']['t.id'] = ['LIKE', '%' . $numeric . '%'];
            }
            $criteria['WHERE'][] = $conditions;
         }
      }

      // Apply Ticket type filter (incident or request)
      if (isset($filters['type']) && $filters['type'] !== null) {
         $type = (int)$filters['type'];
         if ($type > 0) {
            $allowed_types = [
               defined('Ticket::INCIDENT_TYPE') ? Ticket::INCIDENT_TYPE : Ticket::INCIDENT,
               defined('Ticket::DEMAND_TYPE') ? Ticket::DEMAND_TYPE : Ticket::DEMAND,
            ];
            if (in_array($type, $allowed_types)) {
               $criteria['WHERE']['t.type'] = $type;
            }
         } else {
            // "No Type" – tickets with type = 0 or NULL
            $criteria['WHERE'][] = [
               'OR' => [
                  't.type' => 0,
                  't.type' => null,
               ]
            ];
         }
      }

      // Apply Category filter (joins nothing extra: glpi_itilcategories is
      // already LEFT JOINed for the category name). When a category is
      // selected, include the category and all of its subcategories.
      if (isset($filters['category']) && $filters['category'] !== null) {
         $category_id = (int)$filters['category'];
         if ($category_id > 0) {
            $category_ids = getSonsOf('glpi_itilcategories', $category_id);
            if (empty($category_ids)) {
               $category_ids = [$category_id => $category_id];
            }
            $criteria['WHERE']['t.itilcategories_id'] = array_values($category_ids);
         } else {
            // "No Category" – tickets with category = 0 or NULL
            $criteria['WHERE'][] = [
               'OR' => [
                  't.itilcategories_id' => 0,
                  't.itilcategories_id' => null,
               ]
            ];
         }
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

           // Decode GLPI-10-encoded plain-text fields (GLPI 11 stores raw
           // values) and sanitize the rich-text content on the server so the
           // browser never parses untrusted stored markup.
           $ticket['title']    = self::decodeStoredValue($ticket['title']);
           $ticket['content']  = self::safeRichText($ticket['content']);
           $ticket['category'] = self::decodeStoredValue($ticket['category']);

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
     * Check whether a ticket is visible to the current user in the kanban.
     *
     * Mirrors the SQL restriction built by getTicketVisibilityCriteria() but
     * for a single already-loaded ticket (see getFollowups()).
     *
     * @param int $ticket_id
     * @return bool
     */
    private static function isTicketVisible(int $ticket_id): bool {
       $user_id = (int)Session::getLoginUserID();
       if (!$user_id) {
          return false;
       }

       global $DB;
       $ticket_id = (int)$ticket_id;

       $assigned = $DB->request([
          'FROM'   => 'glpi_tickets_users',
          'WHERE'  => [
             'tickets_id' => $ticket_id,
             'users_id'   => $user_id,
             'type'       => CommonITILActor::ASSIGN,
          ],
          'COUNT'  => 'c',
       ])->current()['c'] ?? 0;
       if ((int)$assigned > 0) {
          return true;
       }

       $my_groups = self::getUserGroupIds($user_id);
       if (!empty($my_groups)) {
          $in_groups = $DB->request([
             'FROM'   => 'glpi_groups_tickets',
             'WHERE'  => [
                'tickets_id' => $ticket_id,
                'groups_id'  => $my_groups,
                'type'       => CommonITILActor::ASSIGN,
             ],
             'COUNT'  => 'c',
          ])->current()['c'] ?? 0;
          if ((int)$in_groups > 0) {
             return true;
          }
       }

       $managed_members = self::getManagedGroupMemberIds($user_id);
       if (!empty($managed_members)) {
          $managed = $DB->request([
             'FROM'   => 'glpi_tickets_users',
             'WHERE'  => [
                'tickets_id' => $ticket_id,
                'users_id'   => $managed_members,
                'type'       => CommonITILActor::ASSIGN,
             ],
             'COUNT'  => 'c',
          ])->current()['c'] ?? 0;
          if ((int)$managed > 0) {
             return true;
          }
       }

       return false;
    }

    /**
     * Expand a list of group ids with all of their subgroups (nested).
     *
     * @param array $group_ids
     * @return int[]
     */
    public static function expandGroupIds(array $group_ids): array {
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
     * Decode values stored by GLPI 10's input Sanitizer.
     *
     * GLPI 10 stored user input HTML-entity-encoded (e.g. ">" as "&#62;"), so
     * plain-text fields read through $DB->request() need decoding before they
     * are displayed. GLPI 11 stores raw values and escapes them on output, and
     * its Sanitizer is deprecated, so no decoding is applied on GLPI 11.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function decodeStoredValue($value) {
       if (defined('GLPI_VERSION') && version_compare((string)GLPI_VERSION, '11.0.0', '<')) {
          return \Glpi\Toolbox\Sanitizer::unsanitize($value);
       }
       return $value;
    }

    /**
     * Sanitize a rich-text field (ticket/followup content) server-side.
     *
     * Uses GLPI's own sanitizer — the same one the core applies when rendering
     * stored HTML — so untrusted content never reaches the browser as live
     * markup. No client-side sanitizer is required.
     *
     * @param mixed $value Raw stored value (may be null)
     * @return string
     */
    private static function safeRichText($value): string {
       $value = ($value === null || $value === false) ? '' : (string)$value;
       return \Glpi\RichText\RichText::getSafeHtml($value);
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
      * Get list of technicians for filter dropdown.
      *
      * Only users who belong to at least one group of the current user are
      * returned, so a user restricted to their groups only sees the technicians
      * of those groups. The result is sorted alphabetically by display name.
      *
      * @return array Array of ['id' => int, 'name' => string]
      */
    public static function getTechniciansForFilter(): array {
       global $DB;
       $user_id = (int)Session::getLoginUserID();
       if ($user_id <= 0) {
          return [];
       }
       $group_ids = [];
       $group_iterator = $DB->request([
          'SELECT'    => 'groups_id',
          'FROM'      => 'glpi_groups_users',
          'WHERE'     => ['users_id' => $user_id],
          'DISTINCT'  => true
       ]);
       foreach ($group_iterator as $row) {
          $group_ids[(int)$row['groups_id']] = (int)$row['groups_id'];
       }
       if (empty($group_ids)) {
          return [];
       }
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
             ],
             'glpi_groups_users AS gu' => [
                'ON' => [
                   'u'  => 'id',
                   'gu' => 'users_id'
                ]
             ]
          ],
          'WHERE'     => [
             'u.is_deleted' => 0,
             'u.is_active'  => 1,
             'tu.type'      => CommonITILActor::ASSIGN,
             'gu.groups_id' => array_values($group_ids),
          ],
          'DISTINCT'  => true,
          'ORDER'     => ['u.realname ASC', 'u.firstname ASC'],
          'LIMIT'     => 500
       ]);
       foreach ($iterator as $user) {
          $users[] = [
             'id'   => (int)$user['id'],
             'name' => self::decodeStoredValue(getUserName($user['id']))
          ];
       }
       self::sortUsersByName($users);
       return $users;
    }

/**
      * Get technicians (users) who are members of a group or any of its subgroups.
      *
      * The caller must be authorized to see the group (the front controller
      * validates the id against the caller's own groups before calling this),
      * and only members that belong to the caller's active entities are
      * returned.
      *
      * @param int $group_id
      * @return array Array of ['id' => int, 'name' => string]
      */
    public static function getTechniciansForGroup(int $group_id): array {
       global $DB;
       $group_id = (int)$group_id;
       if ($group_id <= 0) {
          return [];
       }
       $group_ids = getSonsOf('glpi_groups', $group_id);
       if (empty($group_ids)) {
          $group_ids = [$group_id => $group_id];
       }
       $users = [];
       $criteria = [
          'SELECT'     => ['u.id', 'u.realname', 'u.firstname'],
          'FROM'       => 'glpi_users AS u',
          'INNER JOIN' => [
             'glpi_groups_users AS gu' => [
                'ON' => [
                   'u'  => 'id',
                   'gu' => 'users_id'
                ]
             ],
             'glpi_groups AS g' => [
                'ON' => [
                   'gu' => 'groups_id',
                   'g'  => 'id'
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
       ];
        $active_entities = $_SESSION['glpiactiveentities'] ?? [0];
        // The group itself must live in an active entity: restricting only the
        // members is not enough, a foreign-entity group would still be expanded.
        $group_entity_restriction = getEntitiesRestrictCriteria('g', 'entities_id', $active_entities, true);
        if (!empty($group_entity_restriction)) {
           $criteria['WHERE'][] = $group_entity_restriction;
        }
        $entity_restriction = getEntitiesRestrictCriteria('u', 'entities_id', $active_entities, true);
        if (!empty($entity_restriction)) {
           $criteria['WHERE'][] = $entity_restriction;
        }
       $iterator = $DB->request($criteria);
       foreach ($iterator as $user) {
          $users[] = [
             'id'   => (int)$user['id'],
             'name' => self::decodeStoredValue(getUserName($user['id']))
          ];
       }
       self::sortUsersByName($users);
       return $users;
    }

     /**
      * Sort a list of users alphabetically by display name.
      *
      * @param array $users List of ['id' => int, 'name' => string]
      * @return void
      */
    private static function sortUsersByName(array &$users): void {
       usort($users, static function ($a, $b) {
          return strnatcasecmp($a['name'], $b['name']);
       });
    }

/**
      * Get list of requesters for filter dropdown.
      *
      * Only active users of the caller's active entities who actually appear
      * as requesters (type REQUESTER) on at least one ticket are returned, so
      * the board never discloses accounts from unrelated entities.
      *
      * @return array Array of ['id' => int, 'name' => string]
      */
    public static function getRequestersForFilter(): array {
       global $DB;
       $requesters = [];
       // Never expose the whole user directory: the list is restricted to users
       // that hold a profile in the caller's active entities AND that actually
       // appear as requesters on a ticket the caller is allowed to see.
       $criteria = [
          'SELECT'     => ['u.id', 'u.realname', 'u.firstname'],
          'FROM'       => 'glpi_users AS u',
          'INNER JOIN' => [
             'glpi_tickets_users AS tu' => [
                'ON' => [
                   'u'  => 'id',
                   'tu' => 'users_id'
                ]
             ],
             'glpi_tickets AS t' => [
                'ON' => [
                   'tu' => 'tickets_id',
                   't'  => 'id'
                ]
             ],
             'glpi_profiles_users AS pu' => [
                'ON' => [
                   'u'  => 'id',
                   'pu' => 'users_id'
                ]
             ]
          ],
          'WHERE'  => [
             'u.is_deleted' => 0,
             'u.is_active'  => 1,
             'tu.type'      => CommonITILActor::REQUESTER,
          ],
          'DISTINCT' => true,
          'ORDER'    => 'realname ASC',
          'LIMIT'    => 500
       ];
        $active_entities = $_SESSION['glpiactiveentities'] ?? [0];
        $entity_restriction = getEntitiesRestrictCriteria('u', 'entities_id', $active_entities, true);
        if (!empty($entity_restriction)) {
           $criteria['WHERE'][] = $entity_restriction;
        }
        // The profile assignment must live in an active entity as well, otherwise a
        // user who only holds a profile elsewhere would still be disclosed.
        $profile_entity_restriction = getEntitiesRestrictCriteria('pu', 'entities_id', $active_entities, true);
        if (!empty($profile_entity_restriction)) {
           $criteria['WHERE'][] = $profile_entity_restriction;
        }
        // Only requesters of tickets visible on this board.
        $visibility = self::getTicketVisibilityCriteria();
        if ($visibility !== null) {
           $criteria['WHERE'][] = $visibility;
        }
        $iterator = $DB->request($criteria);
        foreach ($iterator as $user) {
           $requesters[] = [
              'id'   => (int)$user['id'],
              'name' => self::decodeStoredValue(getUserName($user['id']))
           ];
        }
        self::sortUsersByName($requesters);
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
              'name' => self::decodeStoredValue($group['name'])
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
              'name' => self::decodeStoredValue($category['name'])
           ];
        }
        return $categories;
     }

    /**
     * Fetch technicians assigned to a specific ticket
     *
     * @param int $ticket_id
     * @return array Array of ['id' => int, 'name' => string, 'firstname' => string,
     *                         'realname' => string, 'picture' => string|null]
     */
    private static function getAssignedTechnicians($ticket_id) {
       global $DB;
       $techs = [];
       $iterator = $DB->request([
          'SELECT'    => ['u.id', 'u.realname', 'u.firstname', 'u.picture'],
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
             $techs[] = [
                'id'        => (int)$user['id'],
                'name'      => self::decodeStoredValue(getUserName($user['id'])),
                'firstname' => self::decodeStoredValue($user['firstname']),
                'realname'  => self::decodeStoredValue($user['realname']),
                'picture'   => self::getPictureUrlCompat($user['picture']),
             ];
          }
       }
       return $techs;
    }

   /**
     * Resolve the user picture URL across GLPI versions.
     *
     * GLPI 10 exposes the helper as the global \Toolbox class, while GLPI 11
     * moved it to the namespaced Glpi\Toolbox\Toolbox.
     *
     * @param string $picture Raw picture path stored on the user (may be empty)
     * @return string|null Full picture URL, or null when the user has no picture
     */
    private static function getPictureUrlCompat($picture) {
       if (empty($picture)) {
          return null;
       }
       if (class_exists('\\Glpi\\Toolbox\\Toolbox')) {
          return \Glpi\Toolbox\Toolbox::getPictureUrl($picture, true);
       }
       if (class_exists('\\Toolbox')) {
          return \Toolbox::getPictureUrl($picture, true);
       }
       return null;
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
              $requester = self::decodeStoredValue(getUserName($user['id']));
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

// Decode GLPI-10-encoded plain-text fields (GLPI 11 stores raw
           // values) and sanitize the rich-text content on the server so the
           // browser never parses untrusted stored markup.
           $ticket['title']    = self::decodeStoredValue($ticket['title']);
           $ticket['content']  = self::safeRichText($ticket['content']);
           $ticket['category'] = self::decodeStoredValue($ticket['category']);

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

    /**
     * Compute key indicators ("gestão à vista") from the tickets currently
     * displayed on the board (already filtered by the active filters).
     *
     * @param array $statuses Tickets grouped by status (getTicketsForKanban output)
     * @return array ['total', 'with_sla', 'sla_on_time', 'sla_overdue',
     *                'sla_percent', 'assigned_to_me', 'unassigned']
     */
   public static function computeMetrics(array $statuses): array {
      $total = 0;
      $with_sla = 0;
      $sla_on_time = 0;
      $sla_overdue = 0;
      $assigned_to_me = 0;
      $unassigned = 0;
      $user_id = (int)Session::getLoginUserID();

      foreach ($statuses as $tickets) {
         foreach ($tickets as $ticket) {
            $total++;
            $sla = $ticket['sla_progress'] ?? null;
            if (!empty($sla) && ($sla['status'] ?? 'no_sla') !== 'no_sla') {
               $with_sla++;
               if (($sla['status'] ?? '') === 'overdue' || ($sla['percent'] ?? 0) >= 100) {
                  $sla_overdue++;
               } else {
                  $sla_on_time++;
               }
            }

            $techs = $ticket['assigned_techs'] ?? [];
            if ($user_id > 0 && !empty($techs)) {
               foreach ($techs as $tech) {
                  if ((int)($tech['id'] ?? 0) === $user_id) {
                     $assigned_to_me++;
                     break;
                  }
               }
            }

            if (empty($techs)) {
               $unassigned++;
            }
         }
      }

      $sla_percent = $with_sla > 0 ? (int)round(($sla_on_time / $with_sla) * 100) : 100;

      return [
         'total'           => $total,
         'with_sla'        => $with_sla,
         'sla_on_time'     => $sla_on_time,
         'sla_overdue'     => $sla_overdue,
         'sla_percent'     => $sla_percent,
         'assigned_to_me'  => $assigned_to_me,
         'unassigned'      => $unassigned,
      ];
   }

   /**
     * Assign the current user as technician (ASSIGN) to a ticket.
     *
     * @param int $ticket_id
     * @return array ['success' => bool, 'error' => string|null]
     */
   public static function assignToMe(int $ticket_id): array {
      $ticket_id = (int)$ticket_id;
      $user_id = (int)Session::getLoginUserID();
      if ($ticket_id <= 0 || $user_id <= 0) {
         return ['success' => false, 'error' => __('Invalid request', 'kanban')];
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticket_id)) {
         return ['success' => false, 'error' => __('Ticket not found', 'kanban')];
      }
      // Technician-level write right is required: "requester may edit their own
      // ticket" (canUpdateItem) alone must not allow actor changes.
      if (!Session::haveRight(Ticket::$rightname, UPDATE) || !$ticket->canUpdateItem()) {
         return ['success' => false, 'error' => __('Permission denied', 'kanban')];
      }
      // The ticket must be one the board would have shown to this user.
      if (!self::isTicketVisible($ticket_id)) {
         return ['success' => false, 'error' => __('Permission denied', 'kanban')];
      }

      global $DB;
      $already = $DB->request([
         'FROM'   => 'glpi_tickets_users',
         'WHERE'  => [
            'tickets_id' => $ticket_id,
            'users_id'   => $user_id,
            'type'       => CommonITILActor::ASSIGN,
         ],
         'COUNT'  => 'c',
      ])->current()['c'] ?? 0;

      if ((int)$already === 0) {
         $result = $ticket->addTeamMember('User', $user_id, ['role' => CommonITILActor::ASSIGN]);
         if (!$result) {
            return ['success' => false, 'error' => __('Could not assign ticket', 'kanban')];
         }
      }
      return ['success' => true];
   }

   /**
     * Get the followups (acompanhamentos) of a ticket.
     *
     * Private followups written by other users are never returned.
     *
     * @param int $ticket_id
     * @return array List of ['id', 'content', 'date', 'user', 'private']
     */
   public static function getFollowups(int $ticket_id): array {
      $ticket_id = (int)$ticket_id;
      if ($ticket_id <= 0 || !self::canView() || !Ticket::canView()) {
         return [];
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticket_id) || !self::isTicketVisible($ticket_id)) {
         return [];
      }

      $current_user = (int)Session::getLoginUserID();
      $followups = [];
      $fu = new ITILFollowup();
      $rows = $fu->find(
         ['items_id' => $ticket_id, 'itemtype' => 'Ticket'],
         'date DESC'
      );
      foreach ($rows as $row) {
         if (!empty($row['is_private']) && (int)$row['users_id'] !== $current_user) {
            continue;
         }
         $followups[] = [
            'id'      => (int)$row['id'],
            'content' => self::safeRichText($row['content']),
            'date'    => $row['date'],
            'user'    => $row['users_id'] ? self::decodeStoredValue(getUserName((int)$row['users_id'])) : __('Unknown', 'kanban'),
            'private' => (bool)$row['is_private'],
         ];
      }
      return $followups;
   }

   /**
     * Change the priority of a ticket.
     *
     * @param int $ticket_id
     * @param int $priority
     * @return array ['success' => bool, 'error' => string|null]
     */
   public static function changePriority(int $ticket_id, int $priority): array {
      $ticket_id = (int)$ticket_id;
      $priority = (int)$priority;
      if ($ticket_id <= 0 || !in_array($priority, [1, 2, 3, 4, 5, 6], true)) {
         return ['success' => false, 'error' => __('Invalid request', 'kanban')];
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticket_id)) {
         return ['success' => false, 'error' => __('Ticket not found', 'kanban')];
      }
      // Technician-level write right is required: "requester may edit their own
      // ticket" (canUpdateItem) alone must not allow priority changes.
      if (!Session::haveRight(Ticket::$rightname, UPDATE) || !$ticket->canUpdateItem()) {
         return ['success' => false, 'error' => __('Permission denied', 'kanban')];
      }
      // The ticket must be one the board would have shown to this user.
      if (!self::isTicketVisible($ticket_id)) {
         return ['success' => false, 'error' => __('Permission denied', 'kanban')];
      }

      $ok = $ticket->update(['id' => $ticket_id, 'priority' => $priority]);
      return ['success' => (bool)$ok, 'error' => $ok ? null : __('Could not update priority', 'kanban')];
   }

   /**
    * Transition a ticket to a new status (drag & drop).
    *
* Depending on the destination the associated GLPI data is recorded too:
     *   - WAITING (4): the pending reason is stored as a ticket follow-up and
     *                   linked through PendingReason_Item (like GLPI's native
     *                   "Set the status to pending" widget).
     *   - SOLVED  (5): a solution (description, optional type, or from a
     *                  solution template) is stored as an ITILSolution.
     *
* GLPI rights are always respected: if the caller may not create follow-ups
      * or solutions, that part is skipped (flagged) but the status transition
      * still happens, mirroring what GLPI itself allows the user to do.
      *
      * The description is ALWAYS required when moving to Pending or Solved
      * (a selected solution template auto-fills the Solved description).
      * The plugin configuration can additionally require a pending reason
      * (to Pending), a solution model and/or a solution type (to Solved);
      * in those cases the transition is rejected unless the field is provided.
      *
     * @param int   $ticket_id
     * @param int   $new_status   Destination status id.
     * @param array $extra        'pending' (flag), 'pending_reason',
     *                            'pendingreasons_id', 'followup_frequency',
     *                            'followups_before_resolution', 'solution',
     *                            'solution_type_id', 'solution_template_id'.
     * @return array ['success' => bool, 'error' => string|null,
     *                'followup_skipped' => bool, 'solution_skipped' => bool,
     *                'status_unchanged' => bool]
    */
   public static function updateTicketStatus(int $ticket_id, int $new_status, array $extra = []): array {
      $ticket_id  = (int)$ticket_id;
      $new_status = (int)$new_status;
      if ($ticket_id <= 0 || $new_status <= 0) {
         return ['success' => false, 'error' => __('Invalid request', 'kanban')];
      }

      $ticket = new Ticket();
      if (!$ticket->getFromDB($ticket_id)) {
         return ['success' => false, 'error' => __('Ticket not found', 'kanban')];
      }
      // Technician-level write right is required (same as the other actions).
      if (!Session::haveRight(Ticket::$rightname, UPDATE) || !$ticket->canUpdateItem()) {
         return ['success' => false, 'error' => __('Permission denied', 'kanban')];
      }
      // The ticket must be one the board would have shown to this user.
      if (!self::isTicketVisible($ticket_id)) {
         return ['success' => false, 'error' => __('Permission denied', 'kanban')];
      }

      $user_id = (int)Session::getLoginUserID();
      $out = [
         'success'           => false,
         'error'             => null,
         'followup_skipped'  => false,
         'solution_skipped'  => false,
      ];

      // WAITING: record the pending reason.
      // The "pending" flag mirrors GLPI's "Set the status to pending" checkbox.
      // When it is off, the follow-up is recorded (if any text) but the status
      // is left untouched, exactly like in the native "add follow-up" screen.
      $set_pending    = in_array((string)($extra['pending'] ?? ''), ['1', 'on', 'true', 'yes'], true);
      $pending_reason = trim((string)($extra['pending_reason'] ?? ''));
      if ($new_status === Ticket::WAITING) {
         // The description is ALWAYS required (no configuration toggle).
         if ($pending_reason === '') {
            return [
               'success' => false,
               'error'   => __('A description is required to move to Pending', 'kanban'),
            ];
         }
         if (!$set_pending) {
            if ($pending_reason !== '') {
               if (ITILFollowup::canCreate()) {
                  $fu = new ITILFollowup();
                  $result = $fu->add([
                     'items_id'   => $ticket_id,
                     'itemtype'   => 'Ticket',
                     'users_id'   => $user_id,
                     'content'    => $pending_reason,
                     'is_private' => 0,
                  ]);
                  if (!$result) {
                     $out['followup_skipped'] = true;
                  }
               } else {
                  $out['followup_skipped'] = true;
               }
            }
            $out['success']          = true;
            $out['status_unchanged'] = true;
            return $out;
         }

         if ($set_pending && PluginKanbanConfig::getRequirePendingReason()
            && (int)($extra['pendingreasons_id'] ?? 0) <= 0) {
            return [
               'success' => false,
               'error'   => __('A pending reason is required to move to Pending', 'kanban'),
            ];
         }

         // Follow-up carrying the reason text, as GLPI does for pendency blocks.
         $fu = null;
         if ($pending_reason !== '') {
            if (ITILFollowup::canCreate()) {
               $fu = new ITILFollowup();
               $result = $fu->add([
                  'items_id'   => $ticket_id,
                  'itemtype'   => 'Ticket',
                  'users_id'   => $user_id,
                  'content'    => $pending_reason,
                  'is_private' => 0,
               ]);
               if (!$result) {
                  $out['followup_skipped'] = true;
                  $fu = null;
               }
            } else {
               $out['followup_skipped'] = true;
            }
         }

         // Store the pending reason link on the ticket (and the follow-up),
         // mirroring the native widget so the timeline shows it exactly like
         // when the status is changed from the ticket form.
         $pending_fields = [
            'pendingreasons_id'           => (int)($extra['pendingreasons_id'] ?? 0),
            'followup_frequency'          => (int)($extra['followup_frequency'] ?? 0),
            'followups_before_resolution' => (int)($extra['followups_before_resolution'] ?? 0),
         ];
         if (class_exists('PendingReason_Item')) {
            PendingReason_Item::createForItem($ticket, $pending_fields);
            if ($fu !== null) {
               PendingReason_Item::createForItem($fu, $pending_fields);
            }
         }
      }

      // SOLVED: store the solution (description, type or from a template).
      if ($new_status === Ticket::SOLVED) {
         $solution = trim((string)($extra['solution'] ?? ''));
         $template_id = (int)($extra['solution_template_id'] ?? 0);
         $solution_type_id = (int)($extra['solution_type_id'] ?? 0);
         if (PluginKanbanConfig::getRequireSolutionModel() && $template_id <= 0) {
            return [
               'success' => false,
               'error'   => __('A solution model is required to move to Solved', 'kanban'),
            ];
         }
         if ($solution === '') {
            if ($template_id > 0) {
               $tmpl = new SolutionTemplate();
               if ($tmpl->getFromDB($template_id)) {
                  $solution = (string)$tmpl->fields['content'];
                  if ($solution_type_id <= 0) {
                     $solution_type_id = (int)$tmpl->fields['solutiontypes_id'];
                  }
               }
            }
         }
         if (PluginKanbanConfig::getRequireSolutionType() && $solution_type_id <= 0) {
            return [
               'success' => false,
               'error'   => __('A solution type is required to move to Solved', 'kanban'),
            ];
         }
         // The solution description is ALWAYS required (no configuration toggle).
         // A selected template auto-fills it; otherwise the text must be provided.
         if ($solution === '') {
            return [
               'success' => false,
               'error'   => __('A solution description is required to move to Solved', 'kanban'),
            ];
         }
         if ($solution !== '') {
            if ($ticket->canSolve()) {
               $sol = new ITILSolution();
               $ok = $sol->add([
                  'itemtype'         => 'Ticket',
                  'items_id'         => $ticket_id,
                  'users_id'         => $user_id,
                  'content'          => $solution,
                  'solutiontypes_id' => max(0, $solution_type_id),
               ]);
               if (!$ok) {
                  $out['solution_skipped'] = true;
               }
            } else {
               $out['solution_skipped'] = true;
            }
         }
      }

      $updated = $ticket->update(['id' => $ticket_id, 'status' => $new_status]);
      $out['success'] = (bool)$updated;
      if (!$updated) {
         $out['error'] = __('Could not update status', 'kanban');
      }
      return $out;
   }

   /**
    * Solution templates available for the "Solved" drag & drop modal.
    *
    * @return array List of ['id', 'name', 'content', 'solutiontypes_id']
    */
   public static function getSolutionTemplates(): array {
      global $DB;
      $criteria = [
         'SELECT' => ['id', 'name', 'content', 'solutiontypes_id'],
         'FROM'   => 'glpi_solutiontemplates',
         'ORDER'  => 'name ASC',
         'LIMIT'  => 200,
      ];
      $entity_restriction = getEntitiesRestrictCriteria('glpi_solutiontemplates', '', $_SESSION['glpiactiveentities'] ?? [0], true);
      if (!empty($entity_restriction)) {
         $criteria['WHERE'][] = $entity_restriction;
      }
      $out = [];
      foreach ($DB->request($criteria) as $row) {
         $out[] = [
            'id'               => (int)$row['id'],
            'name'             => self::decodeStoredValue($row['name']),
            'content'          => (string)$row['content'],
            'solutiontypes_id' => (int)$row['solutiontypes_id'],
         ];
      }
      return $out;
   }

   /**
    * Solution types available for the "Solved" drag & drop modal.
    *
    * @return array List of ['id', 'name']
    */
   public static function getSolutionTypes(): array {
      global $DB;
      $criteria = [
         'SELECT' => ['id', 'name'],
         'FROM'   => 'glpi_solutiontypes',
         'ORDER'  => 'name ASC',
         'LIMIT'  => 200,
      ];
      $entity_restriction = getEntitiesRestrictCriteria('glpi_solutiontypes', '', $_SESSION['glpiactiveentities'] ?? [0], true);
      if (!empty($entity_restriction)) {
         $criteria['WHERE'][] = $entity_restriction;
      }
      $out = [];
      foreach ($DB->request($criteria) as $row) {
         $out[] = [
            'id'   => (int)$row['id'],
            'name' => self::decodeStoredValue($row['name']),
         ];
      }
      return $out;
   }

   /**
    * Pending reasons available for the "Pending" drag & drop modal.
    *
    * Same data source as the native "Set the status to pending" widget:
    * the PendingReason dropdown (glpi_pendingreasons).
    *
    * @return array List of ['id', 'name', 'comment', 'followup_frequency',
    *                         'followups_before_resolution']
    */
   public static function getPendingReasons(): array {
      global $DB;
      $criteria = [
         'SELECT' => ['id', 'name', 'comment', 'followup_frequency', 'followups_before_resolution'],
         'FROM'   => PendingReason::getTable(),
         'ORDER'  => 'name ASC',
         'LIMIT'  => 200,
      ];
      $entity_restriction = getEntitiesRestrictCriteria(PendingReason::getTable(), '', $_SESSION['glpiactiveentities'] ?? [0], true);
      if (!empty($entity_restriction)) {
         $criteria['WHERE'][] = $entity_restriction;
      }
      $out = [];
      foreach ($DB->request($criteria) as $row) {
         $out[] = [
            'id'                          => (int)$row['id'],
            'name'                        => self::decodeStoredValue($row['name']),
            'comment'                     => self::decodeStoredValue($row['comment'] ?? ''),
            'followup_frequency'          => (int)($row['followup_frequency'] ?? 0),
            'followups_before_resolution' => (int)($row['followups_before_resolution'] ?? 0),
         ];
      }
      return $out;
   }
}
