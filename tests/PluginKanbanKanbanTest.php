<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for PluginKanbanKanban (the core kanban board logic).
 */
class PluginKanbanKanbanTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Authenticate as the super-admin user so that Ticket::canView() returns true.
        $user = new User();
        if ($user->getFromDB(2)) {
            $auth = new Auth();
            $auth->auth_succeded = true;
            $auth->user = $user;
            Session::init($auth);
        }
    }

    public function testMaxTicketsPerStatusConstantIsPositive(): void
    {
        $this->assertGreaterThan(0, PluginKanbanKanban::MAX_TICKETS_PER_STATUS);
    }

    public function testGetTicketsForKanbanReturnsArray(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban();
        $this->assertIsArray($result);
    }

    public function testGetTicketsForKanbanReturnsTicketsGroupedByStatus(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban([], [], 50);
        foreach ($result as $status_id => $tickets) {
            $this->assertIsInt($status_id);
            $this->assertIsArray($tickets);
            $this->assertLessThanOrEqual(50, count($tickets));
            foreach ($tickets as $ticket) {
                $this->assertArrayHasKey('id', $ticket);
                $this->assertArrayHasKey('status', $ticket);
                $this->assertArrayHasKey('title', $ticket);
                $this->assertArrayHasKey('assigned_techs', $ticket);
                $this->assertArrayHasKey('sla_progress', $ticket);
                $this->assertArrayHasKey('percent', $ticket['sla_progress']);
                $this->assertArrayHasKey('color', $ticket['sla_progress']);
            }
        }
    }

    public function testGetTicketsForKanbanHonorsPerStatusLimit(): void
    {
        $limit = 5;
        $result = PluginKanbanKanban::getTicketsForKanban([], ['by' => 'date', 'order' => 'DESC'], $limit);
        foreach ($result as $tickets) {
            $this->assertLessThanOrEqual($limit, count($tickets));
        }
    }

    public function testGetTechniciansForFilterReturnsArray(): void
    {
        $result = PluginKanbanKanban::getTechniciansForFilter();
        $this->assertIsArray($result);
        foreach ($result as $technician) {
            $this->assertArrayHasKey('id', $technician);
            $this->assertArrayHasKey('name', $technician);
        }
    }

    public function testGetGroupsForFilterReturnsArray(): void
    {
        $result = PluginKanbanKanban::getGroupsForFilter();
        $this->assertIsArray($result);
        foreach ($result as $group) {
            $this->assertArrayHasKey('id', $group);
            $this->assertArrayHasKey('name', $group);
        }
    }

    public function testGetGroupsForFilterOnlyReturnsUserGroups(): void
    {
        global $DB;

        $member_group = 9601;
        $other_group = 9602;
        $user_id = (int)Session::getLoginUserID();
        $this->assertGreaterThan(0, $user_id);

        try {
            $DB->insert('glpi_groups', ['id' => $member_group, 'name' => 'Kanban Member Group', 'entities_id' => 0, 'groups_id' => 0]);
            $DB->insert('glpi_groups', ['id' => $other_group, 'name' => 'Kanban Other Group', 'entities_id' => 0, 'groups_id' => 0]);
            $DB->insert('glpi_groups_users', ['groups_id' => $member_group, 'users_id' => $user_id]);

            $result = PluginKanbanKanban::getGroupsForFilter();
            $ids = array_column($result, 'id');

            $this->assertContains($member_group, $ids, 'Groups the user belongs to must be returned');
            $this->assertNotContains($other_group, $ids, 'Groups the user does not belong to must not be returned');
        } finally {
            $DB->delete('glpi_groups_users', ['groups_id' => [$member_group, $other_group]]);
            $DB->delete('glpi_groups', ['id' => [$member_group, $other_group]]);
        }
    }

    public function testTechnicianFilterReturnsOnlyAssignedTickets(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban(['technician' => 2], [], 100);
        $this->assertIsArray($result);
        $all_ids = [];
        foreach ($result as $tickets) {
            foreach ($tickets as $ticket) {
                $all_ids[] = (int)$ticket['id'];
                $this->assertIsArray($ticket['assigned_techs']);
                $this->assertNotEmpty($ticket['assigned_techs']);
                $this->assertTrue(
                    $this->ticketHasActor((int)$ticket['id'], 2, CommonITILActor::ASSIGN),
                    'Filtered ticket must have user 2 assigned as technician'
                );
            }
        }
        $this->assertNotEmpty($all_ids);
    }

    public function testRequesterFilterReturnsOnlyRequesterTickets(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban(['requester' => 2], [], 100);
        $this->assertIsArray($result);
        $all_ids = [];
        foreach ($result as $tickets) {
            foreach ($tickets as $ticket) {
                $all_ids[] = (int)$ticket['id'];
                $this->assertTrue(
                    $this->ticketHasActor((int)$ticket['id'], 2, CommonITILActor::REQUESTER),
                    'Filtered ticket must have user 2 as requester'
                );
            }
        }
        $this->assertNotEmpty($all_ids);
    }

    public function testSortByPriorityHonorsOrderWithinStatus(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban([], ['by' => 'priority', 'order' => 'DESC'], 100);
        $this->assertIsArray($result);
        foreach ($result as $tickets) {
            if (count($tickets) < 2) continue;
            $priorities = array_map(static fn ($t) => (int)$t['priority'], $tickets);
            $sorted = $priorities;
            rsort($sorted);
            $this->assertSame($sorted, $priorities, 'Tickets within a status must be sorted by priority DESC');
        }
    }

    public function testSortByPriorityAscendingHonorsOrder(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban([], ['by' => 'priority', 'order' => 'ASC'], 100);
        $this->assertIsArray($result);
        foreach ($result as $tickets) {
            if (count($tickets) < 2) continue;
            $priorities = array_map(static fn ($t) => (int)$t['priority'], $tickets);
            $sorted = $priorities;
            sort($sorted);
            $this->assertSame($sorted, $priorities, 'Tickets within a status must be sorted by priority ASC');
        }
    }

    public function testGetRequestersForFilterReturnsArray(): void
    {
        $result = PluginKanbanKanban::getRequestersForFilter();
        $this->assertIsArray($result);
        foreach ($result as $requester) {
            $this->assertArrayHasKey('id', $requester);
            $this->assertArrayHasKey('name', $requester);
        }
    }

    public function testGetTicketDetailReturnsTicketData(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban([], [], 100);
        if (empty($result)) {
            $this->markTestSkipped('No tickets available for testing');
        }

        $first_status = array_key_first($result);
        $tickets = $result[$first_status];
        if (empty($tickets)) {
            $this->markTestSkipped('No tickets available for testing');
        }

        $ticket_id = $tickets[0]['id'];
        $detail = PluginKanbanKanban::getTicketDetail($ticket_id);

        $this->assertNotNull($detail);
        $this->assertArrayHasKey('id', $detail);
        $this->assertArrayHasKey('title', $detail);
        $this->assertArrayHasKey('status_name', $detail);
        $this->assertArrayHasKey('assigned_techs', $detail);
        $this->assertArrayHasKey('sla_progress', $detail);
    }

    public function testGetTicketDetailReturnsNullForNonexistentTicket(): void
    {
        $detail = PluginKanbanKanban::getTicketDetail(999999);
        $this->assertNull($detail);
    }

    public function testGetTicketsForKanbanIncludesStatusNameAndRequester(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban([], [], 50);
        foreach ($result as $tickets) {
            foreach ($tickets as $ticket) {
                $this->assertArrayHasKey('status_name', $ticket);
                $this->assertArrayHasKey('requester_name', $ticket);
                $this->assertArrayHasKey('content', $ticket);
            }
        }
    }

    public function testInvalidSortOrderFallsBackToDesc(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban([], ['by' => 'date', 'order' => 'INVALID'], 100);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Regression test: tickets in a sub-entity must appear on the board.
     *
     * The old code used getEntitiesRestrictCriteria('t', '', $entities, true),
     * which on GLPI 10 generates a condition on the non-existent column
     * glpi_tickets.is_recursive when the active entity is not the root,
     * causing a SQL error (1054) and an empty board.
     */
    public function testGetTicketsForKanbanReturnsTicketsFromSubEntity(): void
    {
        global $DB;

        $sub_entity_id = 9001;
        $sub_ticket_id = null;

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;
        $saved_show_all = $_SESSION['glpishowallentities'] ?? null;

        try {
            $DB->insert('glpi_entities', [
                'id' => $sub_entity_id,
                'name' => 'Kanban Test Sub-Entity',
                'entities_id' => 0,
                'level' => 1,
            ]);

            $now = date('Y-m-d H:i:s');
            $DB->insert('glpi_tickets', [
                'name' => 'Ticket in sub-entity',
                'content' => 'Regression test for entity restriction',
                'status' => 1,
                'priority' => 3,
                'urgency' => 2,
                'impact' => 2,
                'type' => 1,
                'date' => $now,
                'date_creation' => $now,
                'date_mod' => $now,
                'entities_id' => $sub_entity_id,
                'is_deleted' => 0,
                'itilcategories_id' => 0,
                'requesttypes_id' => 0,
                'users_id_lastupdater' => 2,
                'users_id_recipient' => 2,
                'actiontime' => 0,
                'time_to_resolve' => null,
            ]);

            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_tickets',
                'WHERE' => ['name' => 'Ticket in sub-entity'],
            ]) as $row) {
                $sub_ticket_id = (int)$row['id'];
            }
            $this->assertNotNull($sub_ticket_id, 'Failed to read back the inserted ticket id');

            // Assign the ticket to user 2 so it passes the visibility rule.
            $DB->insert('glpi_tickets_users', ['tickets_id' => $sub_ticket_id, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);

            // Active entity = the sub-entity only.
            $_SESSION['glpiactiveentities'] = [$sub_entity_id];
            $_SESSION['glpishowallentities'] = 0;

            $result = PluginKanbanKanban::getTicketsForKanban([], ['by' => 'date', 'order' => 'DESC'], 100);

            $found = false;
            foreach ($result as $tickets) {
                foreach ($tickets as $ticket) {
                    if ((int)$ticket['id'] === $sub_ticket_id) {
                        $found = true;
                    }
                }
            }
            $this->assertTrue($found, 'Ticket from sub-entity not returned on the board (SQL error / entity restriction broken)');

            // Tickets from other entities must NOT be included when the sub-entity is active.
            foreach ($result as $tickets) {
                foreach ($tickets as $ticket) {
                    $this->assertSame(
                        $sub_ticket_id,
                        (int)$ticket['id'],
                        'Board returned a ticket outside the active sub-entity'
                    );
                }
            }
        } finally {
            if ($sub_ticket_id !== null) {
                $DB->delete('glpi_tickets_users', ['tickets_id' => $sub_ticket_id]);
                $DB->delete('glpi_tickets', ['id' => $sub_ticket_id]);
            }
            $DB->delete('glpi_entities', ['id' => $sub_entity_id]);

            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
            if ($saved_show_all !== null) {
                $_SESSION['glpishowallentities'] = $saved_show_all;
            } else {
                unset($_SESSION['glpishowallentities']);
            }
        }
    }

    public function testGetTicketDetailWorksInSubEntityContext(): void
    {
        global $DB;

        $sub_entity_id = 9002;
        $sub_ticket_id = null;

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;
        $saved_show_all = $_SESSION['glpishowallentities'] ?? null;

        try {
            $DB->insert('glpi_entities', [
                'id' => $sub_entity_id,
                'name' => 'Kanban Test Sub-Entity (detail)',
                'entities_id' => 0,
                'level' => 1,
            ]);

            $now = date('Y-m-d H:i:s');
            $DB->insert('glpi_tickets', [
                'name' => 'Ticket in sub-entity detail',
                'content' => 'Regression test for ticket detail',
                'status' => 1,
                'priority' => 3,
                'urgency' => 2,
                'impact' => 2,
                'type' => 1,
                'date' => $now,
                'date_creation' => $now,
                'date_mod' => $now,
                'entities_id' => $sub_entity_id,
                'is_deleted' => 0,
                'itilcategories_id' => 0,
                'requesttypes_id' => 0,
                'users_id_lastupdater' => 2,
                'users_id_recipient' => 2,
                'actiontime' => 0,
                'time_to_resolve' => null,
            ]);

            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_tickets',
                'WHERE' => ['name' => 'Ticket in sub-entity detail'],
            ]) as $row) {
                $sub_ticket_id = (int)$row['id'];
            }
            $this->assertNotNull($sub_ticket_id, 'Failed to read back the inserted ticket id');

            // Assign the ticket to user 2 so it passes the visibility rule.
            $DB->insert('glpi_tickets_users', ['tickets_id' => $sub_ticket_id, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);

            $_SESSION['glpiactiveentities'] = [$sub_entity_id];
            $_SESSION['glpishowallentities'] = 0;

            $detail = PluginKanbanKanban::getTicketDetail($sub_ticket_id);

            $this->assertNotNull($detail, 'getTicketDetail returned null in sub-entity context');
            $this->assertSame($sub_ticket_id, (int)$detail['id']);
            $this->assertArrayHasKey('status_name', $detail);
            $this->assertArrayHasKey('date_creation_formatted', $detail);
        } finally {
            if ($sub_ticket_id !== null) {
                $DB->delete('glpi_tickets_users', ['tickets_id' => $sub_ticket_id]);
                $DB->delete('glpi_tickets', ['id' => $sub_ticket_id]);
            }
            $DB->delete('glpi_entities', ['id' => $sub_entity_id]);

            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
            if ($saved_show_all !== null) {
                $_SESSION['glpishowallentities'] = $saved_show_all;
            } else {
                unset($_SESSION['glpishowallentities']);
            }
        }
    }

    /**
     * Insert a test ticket in entity 0 and return its real id.
     */
    private static function insertKanbanTestTicket(string $name): int
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $DB->insert('glpi_tickets', [
            'name' => $name,
            'content' => 'Kanban plugin regression test ticket',
            'status' => 1,
            'priority' => 3,
            'urgency' => 2,
            'impact' => 2,
            'type' => 1,
            'date' => $now,
            'date_creation' => $now,
            'date_mod' => $now,
            'entities_id' => 0,
            'is_deleted' => 0,
            'itilcategories_id' => 0,
            'requesttypes_id' => 0,
            'users_id_lastupdater' => 2,
            'users_id_recipient' => 2,
            'actiontime' => 0,
            'time_to_resolve' => null,
        ]);

        $id = null;
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_tickets',
            'WHERE' => ['name' => $name],
        ]) as $row) {
            $id = (int)$row['id'];
        }
        return $id ?? 0;
    }

    /**
     * Regression test: a user without any assignment must only see tickets of
     * the groups they belong to. The restriction applies to every profile,
     * regardless of profile rights (including READALL).
     */
    public function testVisibilityRestrictsNonAdminUserToTheirGroupsTickets(): void
    {
        global $DB;

        $group1 = 9101;
        $group2 = 9102;
        $ticket_a = 0;
        $ticket_b = 0;
        $ticket_c = 0;

        $saved_profile = $_SESSION['glpiactiveprofile']['ticket'] ?? null;
        $saved_groups = $_SESSION['glpigroups'] ?? null;
        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;

        try {
            $DB->insert('glpi_groups', ['id' => $group1, 'name' => 'Kanban Test Group 1', 'entities_id' => 0, 'groups_id' => 0]);
            $DB->insert('glpi_groups', ['id' => $group2, 'name' => 'Kanban Test Group 2', 'entities_id' => 0, 'groups_id' => 0]);
            $DB->insert('glpi_groups_users', ['groups_id' => $group1, 'users_id' => 2]);

            $ticket_a = self::insertKanbanTestTicket('Kanban vis ticket A (group1)');
            $ticket_b = self::insertKanbanTestTicket('Kanban vis ticket B (group2)');
            $ticket_c = self::insertKanbanTestTicket('Kanban vis ticket C (no group)');

            $DB->insert('glpi_groups_tickets', ['tickets_id' => $ticket_a, 'groups_id' => $group1, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_groups_tickets', ['tickets_id' => $ticket_b, 'groups_id' => $group2, 'type' => CommonITILActor::ASSIGN]);

            // Simulate a non-admin profile: can only read items assigned to their groups.
            $_SESSION['glpiactiveprofile']['ticket'] = Ticket::READASSIGN | Ticket::READGROUP;
            $_SESSION['glpigroups'] = [$group1];
            $_SESSION['glpiactiveentities'] = [0];

            $result = PluginKanbanKanban::getTicketsForKanban([], [], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }

            $this->assertContains($ticket_a, $ids, 'Ticket assigned to the user group must be visible');
            $this->assertNotContains($ticket_b, $ids, 'Ticket assigned to another group must NOT be visible');
            $this->assertNotContains($ticket_c, $ids, 'Ticket with no assignment must NOT be visible to a restricted profile');

            // Same restriction must apply to the detail query.
            $this->assertNotNull(PluginKanbanKanban::getTicketDetail($ticket_a));
            $this->assertNull(PluginKanbanKanban::getTicketDetail($ticket_b));
        } finally {
            if ($ticket_a > 0) { $DB->delete('glpi_tickets', ['id' => $ticket_a]); }
            if ($ticket_b > 0) { $DB->delete('glpi_tickets', ['id' => $ticket_b]); }
            if ($ticket_c > 0) { $DB->delete('glpi_tickets', ['id' => $ticket_c]); }
            $DB->delete('glpi_groups_tickets', ['groups_id' => [$group1, $group2]]);
            $DB->delete('glpi_groups_users', ['groups_id' => $group1, 'users_id' => 2]);
            $DB->delete('glpi_groups', ['id' => [$group1, $group2]]);

            if ($saved_profile !== null) {
                $_SESSION['glpiactiveprofile']['ticket'] = $saved_profile;
            } else {
                unset($_SESSION['glpiactiveprofile']['ticket']);
            }
            if ($saved_groups !== null) {
                $_SESSION['glpigroups'] = $saved_groups;
            } else {
                unset($_SESSION['glpigroups']);
            }
            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
        }
    }

    /**
     * The board must show a regular user only the tickets assigned to them
     * (ASSIGN). Tickets where the user is only an observer or requester, or
     * unassigned incoming tickets, must be hidden. The same applies to the
     * ticket detail query.
     */
    public function testBoardOnlyShowsTicketsAssignedToUser(): void
    {
        global $DB;

        $group = 9301;
        $other_user = 0;
        $t_mine = 0;
        $t_other = 0;
        $t_obs = 0;
        $t_req = 0;
        $t_incoming = 0;

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;
        $saved_groups = $_SESSION['glpigroups'] ?? null;

        try {
            $DB->insert('glpi_groups', ['id' => $group, 'name' => 'Kanban Visibility Group', 'entities_id' => 0, 'groups_id' => 0]);
            $other_user = self::insertKanbanTestUser('kanban_vis_other');

            $t_mine = self::insertKanbanTestTicket('Kanban vis assigned to user 2');
            $t_other = self::insertKanbanTestTicket('Kanban vis assigned to other user');
            $t_obs = self::insertKanbanTestTicket('Kanban vis user 2 observer');
            $t_req = self::insertKanbanTestTicket('Kanban vis user 2 requester');
            $t_incoming = self::insertKanbanTestTicket('Kanban vis incoming unassigned');

            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_mine, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_other, 'users_id' => $other_user, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_obs, 'users_id' => 2, 'type' => CommonITILActor::OBSERVER]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_req, 'users_id' => 2, 'type' => CommonITILActor::REQUESTER]);

            // No group membership, no assignment for the user.
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpigroups'] = [];

            $result = PluginKanbanKanban::getTicketsForKanban([], [], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }

            $this->assertContains($t_mine, $ids, 'Ticket assigned to the user must be visible');
            $this->assertNotContains($t_other, $ids, 'Ticket assigned to another technician must NOT be visible');
            $this->assertNotContains($t_obs, $ids, 'Ticket where the user is only an observer must NOT be visible');
            $this->assertNotContains($t_req, $ids, 'Ticket where the user is only a requester must NOT be visible');
            $this->assertNotContains($t_incoming, $ids, 'Unassigned incoming ticket must NOT be visible');

            $this->assertNotNull(PluginKanbanKanban::getTicketDetail($t_mine));
            $this->assertNull(PluginKanbanKanban::getTicketDetail($t_other));
            $this->assertNull(PluginKanbanKanban::getTicketDetail($t_obs));
            $this->assertNull(PluginKanbanKanban::getTicketDetail($t_req));
            $this->assertNull(PluginKanbanKanban::getTicketDetail($t_incoming));
        } finally {
            foreach ([$t_mine, $t_other, $t_obs, $t_req, $t_incoming] as $tid) {
                if ($tid > 0) {
                    $DB->delete('glpi_tickets_users', ['tickets_id' => $tid]);
                    $DB->delete('glpi_tickets', ['id' => $tid]);
                }
            }
            if ($other_user > 0) { $DB->delete('glpi_users', ['id' => $other_user]); }
            $DB->delete('glpi_groups', ['id' => $group]);

            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
            if ($saved_groups !== null) {
                $_SESSION['glpigroups'] = $saved_groups;
            } else {
                unset($_SESSION['glpigroups']);
            }
        }
    }

    /**
     * A regular member of a group must see tickets assigned to the group
     * (even without an individual technician, so they can self-assign), but
     * must NOT see tickets assigned to a colleague of the same group.
     */
    public function testBoardShowsGroupAssignedTicketsToMembers(): void
    {
        global $DB;

        $group = 9302;
        $member = 0;
        $t_group = 0;
        $t_member = 0;

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;
        $saved_groups = $_SESSION['glpigroups'] ?? null;

        try {
            $DB->insert('glpi_groups', ['id' => $group, 'name' => 'Kanban Member Group', 'entities_id' => 0, 'groups_id' => 0]);
            $member = self::insertKanbanTestUser('kanban_vis_member');
            $DB->insert('glpi_groups_users', ['groups_id' => $group, 'users_id' => 2]);
            $DB->insert('glpi_groups_users', ['groups_id' => $group, 'users_id' => $member]);

            $t_group = self::insertKanbanTestTicket('Kanban group assigned no tech');
            $t_member = self::insertKanbanTestTicket('Kanban assigned to member');

            // Group assigned as technician, no individual technician.
            $DB->insert('glpi_groups_tickets', ['tickets_id' => $t_group, 'groups_id' => $group, 'type' => CommonITILActor::ASSIGN]);
            // Assigned to a colleague of the group; the group is NOT the actor.
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_member, 'users_id' => $member, 'type' => CommonITILActor::ASSIGN]);

            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpigroups'] = [$group];

            $result = PluginKanbanKanban::getTicketsForKanban([], [], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }

            $this->assertContains($t_group, $ids, 'Ticket assigned to the user group (no individual tech) must be visible for self-assignment');
            $this->assertNotContains($t_member, $ids, 'Ticket assigned to a colleague of the group must NOT be visible to a regular member');

            $this->assertNotNull(PluginKanbanKanban::getTicketDetail($t_group));
            $this->assertNull(PluginKanbanKanban::getTicketDetail($t_member));
        } finally {
            foreach ([$t_group, $t_member] as $tid) {
                if ($tid > 0) {
                    $DB->delete('glpi_tickets_users', ['tickets_id' => $tid]);
                    $DB->delete('glpi_groups_tickets', ['tickets_id' => $tid]);
                    $DB->delete('glpi_tickets', ['id' => $tid]);
                }
            }
            $DB->delete('glpi_groups_users', ['groups_id' => $group]);
            if ($member > 0) { $DB->delete('glpi_users', ['id' => $member]); }
            $DB->delete('glpi_groups', ['id' => $group]);

            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
            if ($saved_groups !== null) {
                $_SESSION['glpigroups'] = $saved_groups;
            } else {
                unset($_SESSION['glpigroups']);
            }
        }
    }

    /**
     * A group manager (glpi_groups_users.is_manager) must see every ticket of
     * the managed groups: assigned to the group, to any member, and to members
     * of subgroups. Tickets where the group is only an observer, or assigned
     * to members of unrelated groups, must stay hidden.
     */
    public function testGroupManagerSeesAllGroupTickets(): void
    {
        global $DB;

        $group = 9303;
        $subgroup = 9304;
        $external = 9305;
        $member = 0;
        $sub_member = 0;
        $ext_member = 0;
        $t_group = 0;
        $t_member = 0;
        $t_subgroup = 0;
        $t_obs = 0;
        $t_ext = 0;

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;
        $saved_groups = $_SESSION['glpigroups'] ?? null;

        try {
            $DB->insert('glpi_groups', ['id' => $group, 'name' => 'Kanban Managed Group', 'entities_id' => 0, 'groups_id' => 0]);
            $DB->insert('glpi_groups', ['id' => $subgroup, 'name' => 'Kanban Managed Subgroup', 'entities_id' => 0, 'groups_id' => $group]);
            $DB->insert('glpi_groups', ['id' => $external, 'name' => 'Kanban External Group', 'entities_id' => 0, 'groups_id' => 0]);

            $member = self::insertKanbanTestUser('kanban_vis_manager_member');
            $sub_member = self::insertKanbanTestUser('kanban_vis_manager_submember');
            $ext_member = self::insertKanbanTestUser('kanban_vis_manager_extmember');

            // User 2 is the manager of $group (member + is_manager).
            $DB->insert('glpi_groups_users', ['groups_id' => $group, 'users_id' => 2, 'is_manager' => 1]);
            $DB->insert('glpi_groups_users', ['groups_id' => $group, 'users_id' => $member]);
            $DB->insert('glpi_groups_users', ['groups_id' => $subgroup, 'users_id' => $sub_member]);
            $DB->insert('glpi_groups_users', ['groups_id' => $external, 'users_id' => $ext_member]);

            $t_group = self::insertKanbanTestTicket('Kanban manager group assigned');
            $t_member = self::insertKanbanTestTicket('Kanban manager member assigned');
            $t_subgroup = self::insertKanbanTestTicket('Kanban manager subgroup member assigned');
            $t_obs = self::insertKanbanTestTicket('Kanban manager group observer');
            $t_ext = self::insertKanbanTestTicket('Kanban manager external member assigned');

            $DB->insert('glpi_groups_tickets', ['tickets_id' => $t_group, 'groups_id' => $group, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_groups_tickets', ['tickets_id' => $t_obs, 'groups_id' => $group, 'type' => CommonITILActor::OBSERVER]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_member, 'users_id' => $member, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_subgroup, 'users_id' => $sub_member, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_ext, 'users_id' => $ext_member, 'type' => CommonITILActor::ASSIGN]);

            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpigroups'] = [$group];

            $result = PluginKanbanKanban::getTicketsForKanban([], [], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }

            $this->assertContains($t_group, $ids, 'Ticket assigned to the managed group must be visible to the manager');
            $this->assertContains($t_member, $ids, 'Ticket assigned to a member of the managed group must be visible to the manager');
            $this->assertContains($t_subgroup, $ids, 'Ticket assigned to a member of a subgroup must be visible to the manager');
            $this->assertNotContains($t_obs, $ids, 'Ticket where the managed group is only an observer must NOT be visible');
            $this->assertNotContains($t_ext, $ids, 'Ticket assigned to a member of an unrelated group must NOT be visible');

            $this->assertNotNull(PluginKanbanKanban::getTicketDetail($t_group));
            $this->assertNotNull(PluginKanbanKanban::getTicketDetail($t_member));
            $this->assertNotNull(PluginKanbanKanban::getTicketDetail($t_subgroup));
            $this->assertNull(PluginKanbanKanban::getTicketDetail($t_obs));
            $this->assertNull(PluginKanbanKanban::getTicketDetail($t_ext));
        } finally {
            foreach ([$t_group, $t_member, $t_subgroup, $t_obs, $t_ext] as $tid) {
                if ($tid > 0) {
                    $DB->delete('glpi_tickets_users', ['tickets_id' => $tid]);
                    $DB->delete('glpi_groups_tickets', ['tickets_id' => $tid]);
                    $DB->delete('glpi_tickets', ['id' => $tid]);
                }
            }
            $DB->delete('glpi_groups_users', ['groups_id' => [$group, $subgroup, $external]]);
            foreach ([$member, $sub_member, $ext_member] as $uid) {
                if ($uid > 0) { $DB->delete('glpi_users', ['id' => $uid]); }
            }
            $DB->delete('glpi_groups', ['id' => [$group, $subgroup, $external]]);

            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
            if ($saved_groups !== null) {
                $_SESSION['glpigroups'] = $saved_groups;
            } else {
                unset($_SESSION['glpigroups']);
            }
        }
    }

    /**
     * Regression test: selecting a group in the filter must return tickets of
     * the group AND of all of its subgroups (nested groups).
     *
     * User 2 is a member of the parent group so that, under the visibility rule,
     * the tickets assigned to the child and grandchild groups are visible.
     */
    public function testGroupFilterIncludesSubgroups(): void
    {
        global $DB;

        $parent = 9201;
        $child = 9202;
        $grandchild = 9203;
        $other = 9204;
        $ticket_child = 0;
        $ticket_grandchild = 0;
        $ticket_other = 0;

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;
        $saved_groups = $_SESSION['glpigroups'] ?? null;

        try {
            $DB->insert('glpi_groups', ['id' => $parent, 'name' => 'Kanban Parent Group', 'entities_id' => 0, 'groups_id' => 0]);
            $DB->insert('glpi_groups', ['id' => $child, 'name' => 'Kanban Child Group', 'entities_id' => 0, 'groups_id' => $parent]);
            $DB->insert('glpi_groups', ['id' => $grandchild, 'name' => 'Kanban Grandchild Group', 'entities_id' => 0, 'groups_id' => $child]);
            $DB->insert('glpi_groups', ['id' => $other, 'name' => 'Kanban Other Group', 'entities_id' => 0, 'groups_id' => 0]);

            $ticket_child = self::insertKanbanTestTicket('Kanban group filter ticket (child)');
            $ticket_grandchild = self::insertKanbanTestTicket('Kanban group filter ticket (grandchild)');
            $ticket_other = self::insertKanbanTestTicket('Kanban group filter ticket (other)');

            $DB->insert('glpi_groups_tickets', ['tickets_id' => $ticket_child, 'groups_id' => $child, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_groups_tickets', ['tickets_id' => $ticket_grandchild, 'groups_id' => $grandchild, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_groups_tickets', ['tickets_id' => $ticket_other, 'groups_id' => $other, 'type' => CommonITILActor::ASSIGN]);

            // Make user 2 a member of the parent group so the subgroup tickets are visible.
            $DB->insert('glpi_groups_users', ['groups_id' => $parent, 'users_id' => 2]);

            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpigroups'] = [$parent];

            $result = PluginKanbanKanban::getTicketsForKanban(['group' => $parent], [], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }

            $this->assertContains($ticket_child, $ids, 'Filtering a parent group must include tickets of direct subgroups');
            $this->assertContains($ticket_grandchild, $ids, 'Filtering a parent group must include tickets of nested subgroups');
            $this->assertNotContains($ticket_other, $ids, 'Tickets of unrelated groups must not be returned');
        } finally {
            if ($ticket_child > 0) { $DB->delete('glpi_tickets', ['id' => $ticket_child]); }
            if ($ticket_grandchild > 0) { $DB->delete('glpi_tickets', ['id' => $ticket_grandchild]); }
            if ($ticket_other > 0) { $DB->delete('glpi_tickets', ['id' => $ticket_other]); }
            $DB->delete('glpi_groups_tickets', ['groups_id' => [$parent, $child, $grandchild, $other]]);
            $DB->delete('glpi_groups_users', ['groups_id' => $parent, 'users_id' => 2]);
            $DB->delete('glpi_groups', ['id' => [$parent, $child, $grandchild, $other]]);

            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
            if ($saved_groups !== null) {
                $_SESSION['glpigroups'] = $saved_groups;
            } else {
                unset($_SESSION['glpigroups']);
            }
        }
    }

    public function testGetTechniciansForGroupIncludesSubgroupMembers(): void
    {
        global $DB;

        $parent = 9701;
        $child = 9702;
        $other = 9703;
        $user_parent = 0;
        $user_child = 0;
        $user_other = 0;

        try {
            $DB->insert('glpi_groups', ['id' => $parent, 'name' => 'Kanban Tech Parent Group', 'entities_id' => 0, 'groups_id' => 0]);
            $DB->insert('glpi_groups', ['id' => $child, 'name' => 'Kanban Tech Child Group', 'entities_id' => 0, 'groups_id' => $parent]);
            $DB->insert('glpi_groups', ['id' => $other, 'name' => 'Kanban Tech Other Group', 'entities_id' => 0, 'groups_id' => 0]);

            $user_parent = self::insertKanbanTestUser('kanban_tech_parent');
            $user_child = self::insertKanbanTestUser('kanban_tech_child');
            $user_other = self::insertKanbanTestUser('kanban_tech_other');

            $DB->insert('glpi_groups_users', ['groups_id' => $parent, 'users_id' => $user_parent]);
            $DB->insert('glpi_groups_users', ['groups_id' => $child, 'users_id' => $user_child]);
            $DB->insert('glpi_groups_users', ['groups_id' => $other, 'users_id' => $user_other]);

            $result = PluginKanbanKanban::getTechniciansForGroup($parent);
            $ids = array_column($result, 'id');

            $this->assertContains($user_parent, $ids, 'Members of the selected group must be returned');
            $this->assertContains($user_child, $ids, 'Members of subgroups must be returned');
            $this->assertNotContains($user_other, $ids, 'Members of unrelated groups must not be returned');
        } finally {
            if ($user_parent > 0) { $DB->delete('glpi_users', ['id' => $user_parent]); }
            if ($user_child > 0) { $DB->delete('glpi_users', ['id' => $user_child]); }
            if ($user_other > 0) { $DB->delete('glpi_users', ['id' => $user_other]); }
            $DB->delete('glpi_groups_users', ['groups_id' => [$parent, $child, $other]]);
            $DB->delete('glpi_groups', ['id' => [$parent, $child, $other]]);
        }
    }

    private static function insertKanbanTestUser(string $name): int
    {
        global $DB;

        $DB->insert('glpi_users', [
            'name' => $name,
            'password' => Auth::getPasswordHash('kanban-test-pass'),
            'auths_id' => 1,
            'entities_id' => 0,
            'is_active' => 1,
            'is_deleted' => 0,
        ]);

        $id = null;
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_users',
            'WHERE' => ['name' => $name],
        ]) as $row) {
            $id = (int)$row['id'];
        }
        return $id ?? 0;
    }

    /**
     * Check whether the given user participates in a ticket with the given actor type.
     */
    private function ticketHasActor(int $ticket_id, int $user_id, int $type): bool
    {
        global $DB;

        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => $ticket_id, 'users_id' => $user_id, 'type' => $type],
            'LIMIT' => 1,
        ]) as $row) {
            return true;
        }
        return false;
    }
}
