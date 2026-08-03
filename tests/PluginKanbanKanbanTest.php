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

    public function testTechnicianFilterReturnsOnlyAssignedTickets(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban(['technician' => 2], [], 100);
        $this->assertIsArray($result);
        $all_ids = [];
        foreach ($result as $tickets) {
            foreach ($tickets as $ticket) {
                $all_ids[] = $ticket['id'];
                $this->assertIsArray($ticket['assigned_techs']);
                $this->assertNotEmpty($ticket['assigned_techs']);
            }
        }
        // Only tickets with user 2 as ASSIGN should be returned (ticket 2 in seed data).
        $this->assertNotEmpty($all_ids);
        foreach ($all_ids as $id) {
            $this->assertSame(2, (int)$id, 'Only ticket 2 has technician 2 assigned in seed data');
        }
    }

    public function testRequesterFilterReturnsOnlyRequesterTickets(): void
    {
        $result = PluginKanbanKanban::getTicketsForKanban(['requester' => 2], [], 100);
        $this->assertIsArray($result);
        $all_ids = [];
        foreach ($result as $tickets) {
            foreach ($tickets as $ticket) {
                $all_ids[] = $ticket['id'];
            }
        }
        // Tickets 1, 4, 7 have user 2 as REQUESTER in seed data.
        $this->assertNotEmpty($all_ids);
        foreach ($all_ids as $id) {
            $this->assertContains((int)$id, [1, 4, 7]);
        }
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

            $_SESSION['glpiactiveentities'] = [$sub_entity_id];
            $_SESSION['glpishowallentities'] = 0;

            $detail = PluginKanbanKanban::getTicketDetail($sub_ticket_id);

            $this->assertNotNull($detail, 'getTicketDetail returned null in sub-entity context');
            $this->assertSame($sub_ticket_id, (int)$detail['id']);
            $this->assertArrayHasKey('status_name', $detail);
            $this->assertArrayHasKey('date_creation_formatted', $detail);
        } finally {
            if ($sub_ticket_id !== null) {
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
     * Regression test: a profile without the READALL right must only see
     * tickets of the groups the user belongs to (mirrors Ticket::canViewItem()).
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
     * Regression test: selecting a group in the filter must return tickets of
     * the group AND of all of its subgroups (nested groups).
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

            $_SESSION['glpiactiveentities'] = [0];

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
            $DB->delete('glpi_groups', ['id' => [$parent, $child, $grandchild, $other]]);

            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
        }
    }
}
