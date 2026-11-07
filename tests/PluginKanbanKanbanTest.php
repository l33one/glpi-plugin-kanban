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
        // Ensure the plugin right is granted for the default test session.
        $_SESSION['glpiactiveprofile']['plugin_kanban'] = READ;

        // The suite needs its own tickets. Without this it silently depends on
        // whatever happens to be in the database: locally it passed on leftovers
        // from earlier manual runs, while CI starts from an empty database where
        // the E2E-seeded tickets are invisible to the super-admin, because from
        // 1.3.0 a ticket is only shown to members of its groups -- super-admin
        // included. Every getTicketsForKanban() assertion then saw an empty board.
        global $DB;
        $existing = $DB->request([
            'FROM'  => 'glpi_tickets',
            'WHERE' => ['name' => 'Erro de login - Portal cliente (Novo)'],
        ])->count();
        if ($existing === 0) {
            kanban_plugin_create_test_tickets();
        }
    }

    public function testRightNameIsPluginKanban(): void
    {
        $this->assertSame('plugin_kanban', PluginKanbanKanban::$rightname);
    }

    public function testGetRightsReturnsViewRight(): void
    {
        $rights = PluginKanbanKanban::getRights();
        $this->assertIsArray($rights);
        $this->assertArrayHasKey(READ, $rights);
    }

    public function testMaxTicketsPerStatusConstantIsPositive(): void
    {
        $this->assertGreaterThan(0, PluginKanbanKanban::MAX_TICKETS_PER_STATUS);
    }

    public function testGetTicketsForKanbanRequiresPluginRight(): void
    {
        $saved = $_SESSION['glpiactiveprofile']['plugin_kanban'] ?? null;
        $_SESSION['glpiactiveprofile']['plugin_kanban'] = 0;
        try {
            $this->assertSame([], PluginKanbanKanban::getTicketsForKanban());
            $this->assertNull(PluginKanbanKanban::getTicketDetail(1));
        } finally {
            if ($saved !== null) {
                $_SESSION['glpiactiveprofile']['plugin_kanban'] = $saved;
            } else {
                unset($_SESSION['glpiactiveprofile']['plugin_kanban']);
            }
        }
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

    /**
     * The ticket number search must return only tickets whose id contains the
     * typed digits, regardless of a leading "#" or other characters.
     */
    public function testTicketNumberFilterReturnsMatchingTickets(): void
    {
        global $DB;

        $ticket_a = 0;
        $ticket_b = 0;

        try {
            $ticket_a = self::insertKanbanTestTicket('Kanban search ticket A');
            $ticket_b = self::insertKanbanTestTicket('Kanban search ticket B');
            $DB->insert('glpi_tickets_users', ['tickets_id' => $ticket_a, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $ticket_b, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);

            // Exact id match
            $result = PluginKanbanKanban::getTicketsForKanban(['ticket_id' => (string)$ticket_a], [], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }
            $this->assertContains($ticket_a, $ids, 'Matching ticket number must be returned');
            $this->assertNotContains($ticket_b, $ids, 'Non-matching ticket must not be returned');

            // A leading "#" must be ignored
            $result = PluginKanbanKanban::getTicketsForKanban(['ticket_id' => '#' . $ticket_a], [], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }
            $this->assertContains($ticket_a, $ids, 'Search with leading # must still match');

// A number with no matching ticket must leave every column empty.
         // The columns themselves stay: the board is built from the configured
         // statuses, not from the rows that happened to match.
         $result = PluginKanbanKanban::getTicketsForKanban(['ticket_id' => '999999'], [], 100);
         $this->assertNotEmpty($result);
         foreach ($result as $status => $tickets) {
             $this->assertSame([], $tickets, "Column $status must be empty");
         }
        } finally {
            foreach ([$ticket_a, $ticket_b] as $tid) {
                if ($tid > 0) {
                    $DB->delete('glpi_tickets_users', ['tickets_id' => $tid]);
                    $DB->delete('glpi_tickets', ['id' => $tid]);
                }
            }
        }
    }

    public function testTypeFilterReturnsOnlyTicketsOfRequestedType(): void
    {
        global $DB;

        $t_incident = 0;
        $t_request = 0;

        try {
            $t_incident = self::insertKanbanTestTicket('Kanban type filter incident');
            $t_request = self::insertKanbanTestTicket('Kanban type filter request');
            $DB->update('glpi_tickets', ['type' => Ticket::DEMAND_TYPE], ['id' => $t_request]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_incident, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_request, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);

            $idsFor = function (array $result) {
                $ids = [];
                foreach ($result as $tickets) {
                    foreach ($tickets as $t) {
                        $ids[] = (int)$t['id'];
                    }
                }
                return $ids;
            };

            $incident_ids = $idsFor(PluginKanbanKanban::getTicketsForKanban(['type' => Ticket::INCIDENT_TYPE], [], 100));
            $this->assertContains($t_incident, $incident_ids, 'Incident tickets must be returned');
            $this->assertNotContains($t_request, $incident_ids, 'Request tickets must be excluded');

            $request_ids = $idsFor(PluginKanbanKanban::getTicketsForKanban(['type' => Ticket::DEMAND_TYPE], [], 100));
            $this->assertContains($t_request, $request_ids, 'Request tickets must be returned');
            $this->assertNotContains($t_incident, $request_ids, 'Incident tickets must be excluded');

            // An invalid type value must be ignored (no restriction applied)
            $all_ids = $idsFor(PluginKanbanKanban::getTicketsForKanban(['type' => 999], [], 100));
            $this->assertContains($t_incident, $all_ids, 'Invalid type must not restrict tickets');
            $this->assertContains($t_request, $all_ids, 'Invalid type must not restrict tickets');
        } finally {
            foreach ([$t_incident, $t_request] as $tid) {
                if ($tid > 0) {
                    $DB->delete('glpi_tickets_users', ['tickets_id' => $tid]);
                    $DB->delete('glpi_tickets', ['id' => $tid]);
                }
            }
        }
    }

    public function testCategoryFilterReturnsOnlyTicketsOfRequestedCategory(): void
    {
        global $DB;

        $cat_a = 9801;
        $cat_b = 9802;
        $t_a = 0;
        $t_b = 0;

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;

        try {
            $DB->insert('glpi_itilcategories', [
                'id' => $cat_a,
                'name' => 'Kanban Category A',
                'completename' => 'Kanban Category A',
                'entities_id' => 0,
            ]);
            $DB->insert('glpi_itilcategories', [
                'id' => $cat_b,
                'name' => 'Kanban Category B',
                'completename' => 'Kanban Category B',
                'entities_id' => 0,
            ]);

            $t_a = self::insertKanbanTestTicket('Kanban category filter A');
            $t_b = self::insertKanbanTestTicket('Kanban category filter B');
            $DB->update('glpi_tickets', ['itilcategories_id' => $cat_a], ['id' => $t_a]);
            $DB->update('glpi_tickets', ['itilcategories_id' => $cat_b], ['id' => $t_b]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_a, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $t_b, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);

            $_SESSION['glpiactiveentities'] = [0];

            $idsFor = function (array $result) {
                $ids = [];
                foreach ($result as $tickets) {
                    foreach ($tickets as $t) {
                        $ids[] = (int)$t['id'];
                    }
                }
                return $ids;
            };

            $cat_a_ids = $idsFor(PluginKanbanKanban::getTicketsForKanban(['category' => $cat_a], [], 100));
            $this->assertContains($t_a, $cat_a_ids, 'Tickets of the selected category must be returned');
            $this->assertNotContains($t_b, $cat_a_ids, 'Tickets of another category must be excluded');

            $cat_b_ids = $idsFor(PluginKanbanKanban::getTicketsForKanban(['category' => $cat_b], [], 100));
            $this->assertContains($t_b, $cat_b_ids, 'Tickets of the second category must be returned');
            $this->assertNotContains($t_a, $cat_b_ids, 'Tickets of the first category must be excluded');
        } finally {
            foreach ([$t_a, $t_b] as $tid) {
                if ($tid > 0) {
                    $DB->delete('glpi_tickets_users', ['tickets_id' => $tid]);
                    $DB->delete('glpi_tickets', ['id' => $tid]);
                }
            }
            $DB->delete('glpi_itilcategories', ['id' => [$cat_a, $cat_b]]);

            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
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

    public function testSortBySlaHonorsOrder(): void
    {
        global $DB;

        $t_far = 0;
        $t_near = 0;
        $t_overdue = 0;
        $base = strtotime('today');

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;

        try {
            $t_far = self::insertKanbanTestTicket('Kanban SLA far');
            $t_near = self::insertKanbanTestTicket('Kanban SLA near');
            $t_overdue = self::insertKanbanTestTicket('Kanban SLA overdue');

            $DB->update('glpi_tickets', [
                'date_creation' => date('Y-m-d H:i:s', strtotime('-1 day', $base)),
                'time_to_resolve' => date('Y-m-d H:i:s', strtotime('+10 days', $base)),
            ], ['id' => $t_far]);
            $DB->update('glpi_tickets', [
                'date_creation' => date('Y-m-d H:i:s', strtotime('-1 day', $base)),
                'time_to_resolve' => date('Y-m-d H:i:s', strtotime('+1 day', $base)),
            ], ['id' => $t_near]);
            $DB->update('glpi_tickets', [
                'date_creation' => date('Y-m-d H:i:s', strtotime('-2 days', $base)),
                'time_to_resolve' => date('Y-m-d H:i:s', strtotime('-1 day', $base)),
            ], ['id' => $t_overdue]);

            // Make the tickets visible to user 2.
            foreach ([$t_far, $t_near, $t_overdue] as $tid) {
                $DB->insert('glpi_tickets_users', ['tickets_id' => $tid, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);
            }

            $_SESSION['glpiactiveentities'] = [0];

            // sla_ASC = farthest from expiry first (latest deadline first).
            $result = PluginKanbanKanban::getTicketsForKanban([], ['by' => 'sla', 'order' => 'ASC'], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }
            $this->assertSame(
                [$t_far, $t_near, $t_overdue],
                array_values(array_filter($ids, static fn ($id) => in_array($id, [$t_far, $t_near, $t_overdue], true))),
                'SLA ASC must order tickets from farthest to closest to expiry'
            );

            // sla_DESC = closest to expiry / longest expired first (earliest deadline first).
            $result = PluginKanbanKanban::getTicketsForKanban([], ['by' => 'sla', 'order' => 'DESC'], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }
            $this->assertSame(
                [$t_overdue, $t_near, $t_far],
                array_values(array_filter($ids, static fn ($id) => in_array($id, [$t_far, $t_near, $t_overdue], true))),
                'SLA DESC must order tickets from closest to expiry (or expired) to farthest'
            );
        } finally {
            foreach ([$t_far, $t_near, $t_overdue] as $tid) {
                if ($tid > 0) {
                    $DB->delete('glpi_tickets_users', ['tickets_id' => $tid]);
                    $DB->delete('glpi_tickets', ['id' => $tid]);
                }
            }
            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
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

    public function testGetRequestersForFilterListsRequestersOfVisibleTickets(): void
    {
        global $DB;

        // Reproduces the production report: the board was full but the
        // requester dropdown was empty. The old query required the requester to
        // hold a profile (and a user row) inside the caller's active entities,
        // which multi-entity installations do not satisfy -- the profile sits in
        // the parent entity, or there is no profile row at all. The list must
        // come from the tickets the board shows, nothing else.
        $requester_id = self::insertKanbanTestUser('Requerente Sem Perfil');
        $this->assertGreaterThan(0, $requester_id);

        $profiles = $DB->request([
            'FROM'  => 'glpi_profiles_users',
            'WHERE' => ['users_id' => $requester_id],
        ])->count();
        $this->assertSame(0, (int)$profiles, 'The fixture must have no profile, that is the whole point');

        $ticket_id = self::insertKanbanTestTicket('Kanban requester filter ticket');
        $this->assertGreaterThan(0, $ticket_id);

        try {
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_id,
                'users_id'   => (int)Session::getLoginUserID(),
                'type'       => CommonITILActor::ASSIGN,
            ]);
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_id,
                'users_id'   => $requester_id,
                'type'       => CommonITILActor::REQUESTER,
            ]);

            $ids = array_column(PluginKanbanKanban::getRequestersForFilter(), 'id');
            $this->assertContains(
                $requester_id,
                $ids,
                'A requester of a visible ticket must be offered, profile or not'
            );
        } finally {
            $DB->delete('glpi_tickets_users', ['tickets_id' => $ticket_id]);
            $DB->delete('glpi_tickets', ['id' => $ticket_id]);
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

    /**
     * Regression test: GLPI 10 stores user input sanitized (numeric HTML entities
     * such as "&#62;" for ">") and the plugin must unsanitize it on output, while
     * GLPI 11 stores raw values and escapes them at render time. The fixture has
     * to reproduce the storage of the version under test, otherwise it would be
     * asserting GLPI 10's storage format on GLPI 11.
     */
    public function testTicketTitleAndContentAreUnsanitized(): void
    {
        global $DB;

        $tid = 0;

        $title   = 'Falha no backup > verificar & corrigir';
        $content = '<p>Falha no <b>backup</b> noturno &amp; nos logs.</p>';
        $glpi10  = version_compare((string)GLPI_VERSION, '11.0.0', '<');
        $stored_title   = $glpi10 ? \Glpi\Toolbox\Sanitizer::encodeHtmlSpecialChars($title) : $title;
        $stored_content = $glpi10 ? \Glpi\Toolbox\Sanitizer::encodeHtmlSpecialChars($content) : $content;

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;

        try {
            $now = date('Y-m-d H:i:s');
            $DB->insert('glpi_tickets', [
                'name' => $stored_title,
                'content' => $stored_content,
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
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_tickets',
                'WHERE' => ['name' => $stored_title],
            ]) as $row) {
                $tid = (int)$row['id'];
            }
            $this->assertGreaterThan(0, $tid);
            $DB->insert('glpi_tickets_users', ['tickets_id' => $tid, 'users_id' => 2, 'type' => CommonITILActor::ASSIGN]);

            $_SESSION['glpiactiveentities'] = [0];

            $detail = PluginKanbanKanban::getTicketDetail($tid);
            $this->assertNotNull($detail);
            $this->assertSame($title, $detail['title']);
            $this->assertSame($content, $detail['content']);

            $result = PluginKanbanKanban::getTicketsForKanban([], [], 100);
            $found = null;
            foreach ($result as $tickets) {
                foreach ($tickets as $ticket) {
                    if ((int)$ticket['id'] === $tid) {
                        $found = $ticket;
                    }
                }
            }
            $this->assertNotNull($found, 'Sanitized-title ticket must appear on the board');
            $this->assertSame($title, $found['title']);
            $this->assertSame($content, $found['content']);
        } finally {
            if ($tid > 0) {
                $DB->delete('glpi_tickets_users', ['tickets_id' => $tid]);
                $DB->delete('glpi_tickets', ['id' => $tid]);
            }
            if ($saved_entities !== null) {
                $_SESSION['glpiactiveentities'] = $saved_entities;
            } else {
                unset($_SESSION['glpiactiveentities']);
            }
        }
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
     * Regression test: group-assigned tickets must stay visible even when
     * $_SESSION['glpigroups'] is empty (stale session created before the
     * membership existed, or active entity not covering the group's entity).
     */
    public function testBoardShowsGroupTicketsWithStaleGroupSession(): void
    {
        global $DB;

        $group = 9306;
        $other_group = 9307;
        $t_group = 0;
        $t_other_group = 0;

        $saved_entities = $_SESSION['glpiactiveentities'] ?? null;
        $saved_groups = $_SESSION['glpigroups'] ?? null;

        try {
            $DB->insert('glpi_groups', ['id' => $group, 'name' => 'Kanban Stale Session Group', 'entities_id' => 0, 'groups_id' => 0]);
            $DB->insert('glpi_groups', ['id' => $other_group, 'name' => 'Kanban Stale Other Group', 'entities_id' => 0, 'groups_id' => 0]);
            $DB->insert('glpi_groups_users', ['groups_id' => $group, 'users_id' => 2]);

            $t_group = self::insertKanbanTestTicket('Kanban stale session group ticket');
            $t_other_group = self::insertKanbanTestTicket('Kanban stale session other group ticket');

            $DB->insert('glpi_groups_tickets', ['tickets_id' => $t_group, 'groups_id' => $group, 'type' => CommonITILActor::ASSIGN]);
            $DB->insert('glpi_groups_tickets', ['tickets_id' => $t_other_group, 'groups_id' => $other_group, 'type' => CommonITILActor::ASSIGN]);

            // Stale session: the board must not rely on $_SESSION['glpigroups'].
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpigroups'] = [];

            $result = PluginKanbanKanban::getTicketsForKanban([], [], 100);
            $ids = [];
            foreach ($result as $tickets) {
                foreach ($tickets as $t) {
                    $ids[] = (int)$t['id'];
                }
            }

            $this->assertContains($t_group, $ids, 'Group-assigned ticket must be visible even with an empty glpigroups session');
            $this->assertNotContains($t_other_group, $ids, 'Ticket assigned to a group the user does not belong to must stay hidden');

            $this->assertNotNull(PluginKanbanKanban::getTicketDetail($t_group));
            $this->assertNull(PluginKanbanKanban::getTicketDetail($t_other_group));
        } finally {
            foreach ([$t_group, $t_other_group] as $tid) {
                if ($tid > 0) {
                    $DB->delete('glpi_tickets_users', ['tickets_id' => $tid]);
                    $DB->delete('glpi_groups_tickets', ['tickets_id' => $tid]);
                    $DB->delete('glpi_tickets', ['id' => $tid]);
                }
            }
            $DB->delete('glpi_groups_users', ['groups_id' => [$group, $other_group]]);
            $DB->delete('glpi_groups', ['id' => [$group, $other_group]]);

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

    public function testGetBoardDataReportsRealTotalsAndHasMore(): void
    {
        global $DB;

        $_SESSION['glpiactiveentities'] = [0];
        unset($_SESSION['glpigroups']);

        $tickets = [];
        try {
            // Three tickets in the same status so a limit of 2 truncates the
            // column: that is the case the board used to hide.
            for ($i = 0; $i < 3; $i++) {
                $id = self::insertKanbanTestTicket('Kanban pagination ticket ' . $i);
                $tickets[] = $id;
                if ($id > 0) {
                    $DB->update('glpi_tickets', ['status' => Ticket::INCOMING], ['id' => $id]);
                }
            }
            $this->assertNotEmpty($tickets);

            $board = PluginKanbanKanban::getBoardData([], ['by' => 'date', 'order' => 'DESC'], 2);

            $this->assertIsArray($board);
            $this->assertArrayHasKey('statuses', $board);
            $this->assertArrayHasKey('totals', $board);
            $this->assertArrayHasKey('has_more', $board);

            $loaded = $board['statuses'][Ticket::INCOMING] ?? [];
            $total = $board['totals'][Ticket::INCOMING] ?? 0;
            $more = $board['has_more'][Ticket::INCOMING] ?? false;

            $this->assertLessThanOrEqual(2, count($loaded), 'The column must honour the requested limit');
            $this->assertGreaterThanOrEqual(count($loaded), $total, 'The total cannot be smaller than what is loaded');
            $this->assertSame(
                $total > count($loaded),
                (bool)$more,
                'has_more must be true exactly when the column was truncated'
            );

            // The second page continues where the first stopped.
            $page2 = PluginKanbanKanban::getBoardData(
                [],
                ['by' => 'date', 'order' => 'DESC'],
                2,
                2,
                Ticket::INCOMING
            );
            $loaded2 = $page2['statuses'][Ticket::INCOMING] ?? [];
            $first_ids = array_column($loaded, 'id');
            foreach ($loaded2 as $ticket) {
                $this->assertNotContains((int)$ticket['id'], $first_ids, 'A page must not repeat the previous page');
            }
        } finally {
            foreach ($tickets as $id) {
                if ($id > 0) {
                    $DB->delete('glpi_tickets', ['id' => $id]);
                }
            }
        }
    }

    public function testGetTicketsForKanbanStaysAWrapperOverGetBoardData(): void
    {
        $wrapper = PluginKanbanKanban::getTicketsForKanban([], ['by' => 'date', 'order' => 'DESC'], 5);
        $board = PluginKanbanKanban::getBoardData([], ['by' => 'date', 'order' => 'DESC'], 5);

        $this->assertSame(array_keys($board['statuses']), array_keys($wrapper));
    }

    public function testComputeMetricsUsesTotalsAndFlagsPartialData(): void
    {
        $statuses = [
            Ticket::INCOMING => [
                ['id' => 1, 'status' => Ticket::INCOMING, 'assigned_techs' => [], 'sla_progress' => ['status' => 'ok', 'percent' => 50]],
                ['id' => 2, 'status' => Ticket::INCOMING, 'assigned_techs' => [], 'sla_progress' => ['status' => 'no_sla', 'percent' => 0]],
            ],
        ];

        $with_totals = PluginKanbanKanban::computeMetrics($statuses, [Ticket::INCOMING => 7]);
        $this->assertSame(7, $with_totals['total'], 'The total is the server count, not the number of cards');
        $this->assertSame(2, $with_totals['cards']);
        $this->assertTrue($with_totals['partial'], 'A truncated board must say so');
        $this->assertSame(2, $with_totals['unassigned']);

        $without_totals = PluginKanbanKanban::computeMetrics($statuses);
        $this->assertSame(2, $without_totals['total']);
        $this->assertFalse($without_totals['partial']);
    }

    public function testCountVisibleTicketsInStatusMatchesTheColumnTotal(): void
    {
        global $DB;

        $_SESSION['glpiactiveentities'] = [0];
        unset($_SESSION['glpigroups']);

        $tickets = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $id = self::insertKanbanTestTicket('Kanban wip ticket ' . $i);
                $tickets[] = $id;
                if ($id > 0) {
                    $DB->update('glpi_tickets', ['status' => Ticket::PLANNED], ['id' => $id]);
                    // Same gate as the undo test: the board only shows tickets
                    // the user is responsible for, so the ticket has to be
                    // assigned or the column totals come out empty.
                    $DB->insert('glpi_tickets_users', [
                        'tickets_id' => $id,
                        'users_id'   => (int)Session::getLoginUserID(),
                        'type'       => CommonITILActor::ASSIGN,
                    ]);
                }
            }

            $board = PluginKanbanKanban::getBoardData([], ['by' => 'date', 'order' => 'DESC'], 1);
            $total_from_board = $board['totals'][Ticket::PLANNED] ?? 0;

            $this->assertSame(
                $total_from_board,
                PluginKanbanKanban::countVisibleTicketsInStatus(Ticket::PLANNED),
                'The work-in-progress count must be the same number the board shows'
            );
            $this->assertGreaterThanOrEqual(2, $total_from_board);
        } finally {
            foreach ($tickets as $id) {
                if ($id > 0) {
                    $DB->delete('glpi_tickets_users', ['tickets_id' => $id]);
                    $DB->delete('glpi_tickets', ['id' => $id]);
                }
            }
        }
    }

    public function testUndoStatusChangeRestoresThePreviousStatus(): void
    {
        global $DB;

        $ticket_id = self::insertKanbanTestTicket('Kanban undo ticket');
        $this->assertGreaterThan(0, $ticket_id);

        try {
            $DB->update('glpi_tickets', ['status' => Ticket::ASSIGNED], ['id' => $ticket_id]);
            // The board only ever shows tickets the user is responsible for,
            // and undo reuses that gate, so the ticket must be assigned.
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_id,
                'users_id'   => (int)Session::getLoginUserID(),
                'type'       => CommonITILActor::ASSIGN,
            ]);

            $result = PluginKanbanKanban::undoStatusChange($ticket_id, Ticket::INCOMING);
            $this->assertTrue($result['success'], 'Undo must put the ticket back: ' . (string)$result['error']);

            $ticket = new Ticket();
            $ticket->getFromDB($ticket_id);
            $this->assertSame(Ticket::INCOMING, (int)$ticket->fields['status']);
        } finally {
            if ($ticket_id > 0) {
                $DB->delete('glpi_tickets_users', ['tickets_id' => $ticket_id]);
                $DB->delete('glpi_tickets', ['id' => $ticket_id]);
            }
        }
    }

    public function testUndoStatusChangeRefusesAnUnknownStatus(): void
    {
        $result = PluginKanbanKanban::undoStatusChange(0, 999999);

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function testCheckWipLimitBlocksOnlyWhenConfiguredToBlock(): void
    {
        global $DB;

        $saved = \Config::getConfigurationValues('plugin:kanban');
        $ticket_id = self::insertKanbanTestTicket('Kanban wip limit ticket');
        $this->assertGreaterThan(0, $ticket_id);

        try {
            $DB->update('glpi_tickets', ['status' => Ticket::PLANNED], ['id' => $ticket_id]);
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_id,
                'users_id'   => (int)Session::getLoginUserID(),
                'type'       => CommonITILActor::ASSIGN,
            ]);

            // checkWipLimit returns null to allow the move and a message to
            // block it. The test owns the ticket in the column, so the limits can
            // be derived from the real count: hardcoding 1 only ever worked by
            // accident, and an assertNotNull on a column described as empty was
            // really asserting the blocked branch.
            $in_column = PluginKanbanKanban::countVisibleTicketsInStatus(Ticket::PLANNED);
            $this->assertGreaterThanOrEqual(
                1,
                $in_column,
                'The column must hold the ticket this test just created'
            );

            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit'       => $in_column + 1,
                'wip_block_exceed' => 1,
            ]);
            $this->assertSame($in_column + 1, PluginKanbanConfig::getWipLimit());
            $this->assertTrue(PluginKanbanConfig::getWipBlockExceed());
            $this->assertNull(
                PluginKanbanKanban::checkWipLimit(Ticket::PLANNED),
                'Below the limit the move is allowed'
            );

            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit'       => $in_column,
                'wip_block_exceed' => 1,
            ]);
            $this->assertNotNull(
                PluginKanbanKanban::checkWipLimit(Ticket::PLANNED),
                'At the limit the move is blocked'
            );

            \Config::setConfigurationValues('plugin:kanban', ['wip_limit' => 0, 'wip_block_exceed' => 1]);
            $this->assertSame(0, PluginKanbanConfig::getWipLimit());
            $this->assertNull(PluginKanbanKanban::checkWipLimit(Ticket::PLANNED), 'Zero disables the limit');

            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit'       => $in_column,
                'wip_block_exceed' => 0,
            ]);
            $this->assertNull(
                PluginKanbanKanban::checkWipLimit(Ticket::PLANNED),
                'With blocking turned off a full column is still fine'
            );
        } finally {
            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit' => $saved['wip_limit'] ?? 0,
                'wip_block_exceed' => $saved['wip_block_exceed'] ?? 0,
            ]);
            if ($ticket_id > 0) {
                $DB->delete('glpi_tickets_users', ['tickets_id' => $ticket_id]);
                $DB->delete('glpi_tickets', ['id' => $ticket_id]);
            }
        }
    }

    public function testWipLimitPerColumnOverridesGlobal(): void
    {
        $saved = \Config::getConfigurationValues('plugin:kanban');

        try {
            // Write through the plugin's own save() so the test covers the
            // round-trip: save() serializes the map with exportArrayToDB, which
            // produces a JSON object ({"3":2}), and load() has to decode it.
            PluginKanbanConfig::save([
                'wip_limit'  => 5,
                'wip_limits' => [Ticket::PLANNED => 2, Ticket::WAITING => 0],
            ]);

            $stored = \Config::getConfigurationValues('plugin:kanban');
            $this->assertStringStartsWith(
                '{',
                (string)($stored['wip_limits'] ?? ''),
                'The per-column map must be stored as a JSON object, not a list'
            );

            $this->assertSame(2, PluginKanbanConfig::getWipLimitForStatus(Ticket::PLANNED));
            $this->assertSame(
                0,
                PluginKanbanConfig::getWipLimitForStatus(Ticket::WAITING),
                'An explicit zero disables the limit for that column'
            );
            $this->assertSame(
                5,
                PluginKanbanConfig::getWipLimitForStatus(Ticket::INCOMING),
                'A column without its own limit falls back to the global value'
            );
            $this->assertSame(
                [Ticket::PLANNED => 2, Ticket::WAITING => 0],
                PluginKanbanConfig::getWipLimits()
            );
            $this->assertTrue(PluginKanbanConfig::hasWipLimits());
        } finally {
            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit'  => $saved['wip_limit'] ?? 0,
                'wip_limits' => $saved['wip_limits'] ?? exportArrayToDB([]),
            ]);
        }
    }

    public function testSanitizeWipLimitsDropsEmptyAndUnknownValues(): void
    {
        $this->assertSame(
            [
                Ticket::PLANNED => 2,
                Ticket::WAITING => 0,
                Ticket::SOLVED  => 0,
                Ticket::CLOSED  => 10000,
            ],
            PluginKanbanConfig::sanitizeWipLimits([
                Ticket::PLANNED  => '2',
                Ticket::WAITING  => '0',
                Ticket::INCOMING => '',
                99999            => 4,
                'abc'            => 1,
                Ticket::SOLVED   => '-3',
                Ticket::CLOSED   => '20000',
            ])
        );
    }

    public function testCheckWipLimitUsesTheColumnLimit(): void
    {
        global $DB;

        $saved = \Config::getConfigurationValues('plugin:kanban');
        $ticket_id = self::insertKanbanTestTicket('Kanban wip per column ticket');
        $this->assertGreaterThan(0, $ticket_id);

        try {
            $DB->update('glpi_tickets', ['status' => Ticket::PLANNED], ['id' => $ticket_id]);
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_id,
                'users_id'   => (int)Session::getLoginUserID(),
                'type'       => CommonITILActor::ASSIGN,
            ]);

            $in_column = PluginKanbanKanban::countVisibleTicketsInStatus(Ticket::PLANNED);

            // Global limit off, column limit full: the column itself blocks.
            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit'        => 0,
                'wip_limits'       => exportArrayToDB([Ticket::PLANNED => $in_column]),
                'wip_block_exceed' => 1,
            ]);
            $this->assertNotNull(
                PluginKanbanKanban::checkWipLimit(Ticket::PLANNED),
                'The per-column limit blocks the move even with no global limit'
            );
            $this->assertNull(
                PluginKanbanKanban::checkWipLimit(Ticket::INCOMING),
                'Columns without their own limit stay unlimited'
            );

            // An explicit zero on the column wins over the global limit.
            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit'        => 1,
                'wip_limits'       => exportArrayToDB([Ticket::PLANNED => 0]),
                'wip_block_exceed' => 1,
            ]);
            $this->assertNull(
                PluginKanbanKanban::checkWipLimit(Ticket::PLANNED),
                'Zero disables the limit for that column'
            );
        } finally {
            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit'        => $saved['wip_limit'] ?? 0,
                'wip_limits'       => $saved['wip_limits'] ?? exportArrayToDB([]),
                'wip_block_exceed' => $saved['wip_block_exceed'] ?? 0,
            ]);
            $DB->delete('glpi_tickets_users', ['tickets_id' => $ticket_id]);
            $DB->delete('glpi_tickets', ['id' => $ticket_id]);
        }
    }

    public function testConfigExposesTheNewBoardSettings(): void
    {
        $this->assertGreaterThanOrEqual(1, PluginKanbanConfig::getCardsPerColumn());
        $this->assertLessThanOrEqual(200, PluginKanbanConfig::getCardsPerColumn());
        $this->assertIsBool(PluginKanbanConfig::getWipBlockExceed());
        $this->assertIsArray(PluginKanbanConfig::getWipLimits());
        $this->assertIsBool(PluginKanbanConfig::hasWipLimits());
        $this->assertIsBool(PluginKanbanConfig::getEnableUndo());
    }

    /**
     * The configured column order has to survive the config storage round-trip,
     * preserving both the values and their sequence. The board itself renders
     * that order through KANBAN_STATUS_ORDER (see front/kanban.php), because a
     * JSON object keyed by integer status ids cannot keep a custom order.
     */
    public function testStatusOrderRoundTripsThroughConfig(): void
    {
        $saved = PluginKanbanConfig::load();

        try {
            $configured = [4, 3, 1, 2, 5, 6];
            PluginKanbanConfig::save(['status_order' => $configured]);
            $this->assertSame($configured, PluginKanbanConfig::getStatusOrder());
        } finally {
            PluginKanbanConfig::save(['status_order' => $saved['status_order'] ?? []]);
        }
    }

    /**
     * Every status the running GLPI version exposes must have a board color.
     * GLPI 11 adds APPROVAL (10); without an entry the column silently falls
     * back to the neutral color and loses its visual identity.
     */
    public function testEveryNativeStatusHasABoardColor(): void
    {
        $colors = PluginKanbanKanban::getStatusColors();
        $allowed = ['primary', 'secondary', 'success', 'info', 'warning', 'danger', 'dark', 'light'];

        foreach (Ticket::getAllStatusArray() as $id => $name) {
            $this->assertArrayHasKey($id, $colors, "Status $id ($name) has no board color");
            $this->assertContains($colors[$id], $allowed, "Status $id ($name) uses an unknown color");
        }

        if (defined('Ticket::APPROVAL')) {
            $this->assertSame('info', $colors[constant('Ticket::APPROVAL')]);
        }
    }

    /**
     * The board resolves requesters and technicians for a whole page at once.
     * This pins the batch result to the per-ticket result so removing the N+1
     * cannot silently change what a card shows.
     */
    public function testBatchActorLookupMatchesSingleTicketLookup(): void
    {
        global $DB;

        $suffix = uniqid('', true);
        $tech_a = self::insertKanbanTestUser('kanban_batch_tech_a_' . $suffix);
        $tech_b = self::insertKanbanTestUser('kanban_batch_tech_b_' . $suffix);
        $this->assertGreaterThan(0, $tech_a);
        $this->assertGreaterThan(0, $tech_b);

        $DB->update('glpi_users', ['realname' => 'Batch', 'firstname' => 'Tech A'], ['id' => $tech_a]);
        $DB->update('glpi_users', ['realname' => 'Batch', 'firstname' => 'Tech B'], ['id' => $tech_b]);

        $ticket_a = self::insertKanbanTestTicket('Kanban batch actors A ' . $suffix);
        $ticket_b = self::insertKanbanTestTicket('Kanban batch actors B ' . $suffix);
        $this->assertGreaterThan(0, $ticket_a);
        $this->assertGreaterThan(0, $ticket_b);

        try {
            $login_user = (int)Session::getLoginUserID();

            // Ticket A: two technicians, one requester.
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_a,
                'users_id'   => $tech_a,
                'type'       => CommonITILActor::ASSIGN,
            ]);
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_a,
                'users_id'   => $tech_b,
                'type'       => CommonITILActor::ASSIGN,
            ]);
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_a,
                'users_id'   => $login_user,
                'type'       => CommonITILActor::REQUESTER,
            ]);

            // Ticket B: a technician that is also the requester, so a leak
            // between the two maps would be visible.
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_b,
                'users_id'   => $tech_a,
                'type'       => CommonITILActor::ASSIGN,
            ]);
            $DB->insert('glpi_tickets_users', [
                'tickets_id' => $ticket_b,
                'users_id'   => $tech_b,
                'type'       => CommonITILActor::REQUESTER,
            ]);

            $techs = self::invokeKanbanPrivate('getAssignedTechniciansForTickets', [[$ticket_a, $ticket_b]]);
            $requesters = self::invokeKanbanPrivate('getRequesterNamesForTickets', [[$ticket_a, $ticket_b]]);

            $this->assertSame([$tech_a, $tech_b], array_column($techs[$ticket_a], 'id'));
            $this->assertSame([$tech_a], array_column($techs[$ticket_b], 'id'), 'Technicians must not leak across tickets');
            $this->assertSame('Batch Tech A', $techs[$ticket_b][0]['name']);
            $this->assertSame('Tech A', $techs[$ticket_b][0]['firstname']);
            $this->assertArrayHasKey('picture', $techs[$ticket_b][0]);

            $this->assertSame(getUserName($login_user), $requesters[$ticket_a]);
            $this->assertSame(getUserName($tech_b), $requesters[$ticket_b], 'Requester must not leak across tickets');

            // The single-ticket path (still used by the detail modal) has to
            // return exactly the same payload as the batch path.
            $this->assertSame(
                self::invokeKanbanPrivate('getAssignedTechnicians', [$ticket_a]),
                $techs[$ticket_a]
            );
            $this->assertSame(
                self::invokeKanbanPrivate('getTicketRequesterName', [$ticket_b]),
                $requesters[$ticket_b]
            );

            // An empty page must not build an invalid "IN ()" query.
            $this->assertSame([], self::invokeKanbanPrivate('getAssignedTechniciansForTickets', [[]]));
            $this->assertSame([], self::invokeKanbanPrivate('getRequesterNamesForTickets', [[]]));
        } finally {
            $DB->delete('glpi_tickets_users', ['tickets_id' => $ticket_a]);
            $DB->delete('glpi_tickets_users', ['tickets_id' => $ticket_b]);
            $DB->delete('glpi_tickets', ['id' => $ticket_a]);
            $DB->delete('glpi_tickets', ['id' => $ticket_b]);
            $DB->delete('glpi_users', ['id' => $tech_a]);
            $DB->delete('glpi_users', ['id' => $tech_b]);
        }
    }

    /**
     * The priority filter is a plain column restriction, but it must be applied
     * on the server for every status (and therefore for the column totals too).
     */
    public function testPriorityFilterReturnsOnlyRequestedPriority(): void
    {
        $low = 0;
        $high = 0;

        try {
            $low = self::insertKanbanTestTicket('Kanban priority filter low');
            $high = self::insertKanbanTestTicket('Kanban priority filter high');
            $this->assertGreaterThan(0, $low);
            $this->assertGreaterThan(0, $high);

            self::assignTicketToCurrentUser($low);
            self::assignTicketToCurrentUser($high);

            global $DB;
            $DB->update('glpi_tickets', ['priority' => 1], ['id' => $low]);
            $DB->update('glpi_tickets', ['priority' => 5], ['id' => $high]);

            $ids = self::boardTicketIds(['priority' => 5]);
            $this->assertContains($high, $ids);
            $this->assertNotContains($low, $ids, 'A different priority must not match the filter');

            // A value that cannot be a GLPI priority is ignored instead of
            // matching nothing: a malformed filter must not empty the board.
            $unfiltered = self::boardTicketIds(['priority' => 999]);
            $this->assertContains($low, $unfiltered);
            $this->assertContains($high, $unfiltered);
        } finally {
            self::deleteKanbanTestTickets([$low, $high]);
        }
    }

    /**
     * The SLA filter runs in SQL, so it must freeze/pause the clock exactly like
     * calculateSlaProgress(): otherwise the column total and the pagination
     * would disagree with the progress bar rendered on the card.
     */
    public function testSlaStateFilterMirrorsFrozenClockRules(): void
    {
        global $DB;

        $saved_now = $_SESSION['glpi_currenttime'] ?? null;
        $_SESSION['glpi_currenttime'] = '2026-08-02 12:00:00';

        $created = '2026-08-01 12:00:00';
        $past_deadline = '2026-08-02 11:00:00';
        $ids = [];

        try {
            $overdue = self::insertKanbanSlaTicket($ids, [
                'name' => 'Kanban SLA overdue',
                'status' => Ticket::ASSIGNED,
                'date_creation' => $created,
                'date_mod' => $created,
                'time_to_resolve' => $past_deadline,
            ]);
            $due_soon = self::insertKanbanSlaTicket($ids, [
                'name' => 'Kanban SLA due soon',
                'status' => Ticket::ASSIGNED,
                'date_creation' => $created,
                'date_mod' => $created,
                'time_to_resolve' => '2026-08-02 14:00:00',
            ]);
            $ontime = self::insertKanbanSlaTicket($ids, [
                'name' => 'Kanban SLA on time',
                'status' => Ticket::ASSIGNED,
                'date_creation' => $created,
                'date_mod' => $created,
                'time_to_resolve' => '2026-08-10 12:00:00',
            ]);
            $no_sla = self::insertKanbanSlaTicket($ids, [
                'name' => 'Kanban SLA absent',
                'status' => Ticket::ASSIGNED,
                'date_creation' => $created,
                'date_mod' => $created,
                'time_to_resolve' => null,
            ]);
            // Deadline already passed in real time, but the ticket stopped at
            // solvedate: the filter must use the frozen clock (on time).
            $frozen = self::insertKanbanSlaTicket($ids, [
                'name' => 'Kanban SLA frozen by solution',
                'status' => Ticket::SOLVED,
                'date_creation' => $created,
                'date_mod' => '2026-08-01 13:00:00',
                'solvedate' => '2026-08-01 13:00:00',
                'time_to_resolve' => $past_deadline,
            ]);
            // Deadline already passed, but the ticket is pending: the clock is
            // paused at begin_waiting_date, so it is not overdue either.
            $paused = self::insertKanbanSlaTicket($ids, [
                'name' => 'Kanban SLA paused by pending',
                'status' => Ticket::WAITING,
                'date_creation' => $created,
                'date_mod' => $created,
                'begin_waiting_date' => '2026-08-01 13:00:00',
                'time_to_resolve' => $past_deadline,
            ]);

            $overdue_ids = self::boardTicketIds(['sla_state' => 'overdue']);
            $this->assertContains($overdue, $overdue_ids);
            $this->assertNotContains($frozen, $overdue_ids, 'A solved ticket freezes at solvedate');
            $this->assertNotContains($paused, $overdue_ids, 'A pending ticket pauses at begin_waiting_date');

            $due_soon_ids = self::boardTicketIds(['sla_state' => 'due_soon']);
            $this->assertContains($due_soon, $due_soon_ids);
            $this->assertNotContains($overdue, $due_soon_ids, 'Overdue is not due soon');
            $this->assertNotContains($ontime, $due_soon_ids);

            $ontime_ids = self::boardTicketIds(['sla_state' => 'ontime']);
            $this->assertContains($ontime, $ontime_ids);
            $this->assertContains($frozen, $ontime_ids);
            $this->assertContains($paused, $ontime_ids);
            $this->assertNotContains($overdue, $ontime_ids);
            $this->assertNotContains($no_sla, $ontime_ids);

            $no_sla_ids = self::boardTicketIds(['sla_state' => 'no_sla']);
            $this->assertContains($no_sla, $no_sla_ids);
            $this->assertNotContains($ontime, $no_sla_ids);

            // The card payload uses the same reference: the frozen ticket's bar
            // is not at 100% even though its deadline is in the past.
            $board = PluginKanbanKanban::getBoardData(['sla_state' => 'ontime'], [], 100);
            $frozen_card = null;
            foreach ($board['statuses'][Ticket::SOLVED] ?? [] as $card) {
                if ((int)$card['id'] === $frozen) {
                    $frozen_card = $card;
                }
            }
            $this->assertNotNull($frozen_card);
            $this->assertSame('active', $frozen_card['sla_progress']['status']);
            $this->assertTrue($frozen_card['sla_progress']['frozen']);
        } finally {
            self::deleteKanbanTestTickets($ids);
            if ($saved_now === null) {
                unset($_SESSION['glpi_currenttime']);
            } else {
                $_SESSION['glpi_currenttime'] = $saved_now;
            }
        }
    }

    /**
     * A batch is not a transaction: every ticket gets its own result and one
     * refusal must not stop the tickets that were accepted.
     */
    public function testBatchActionKeepsResultPerTicket(): void
    {
        global $DB;

        $accepted = self::insertKanbanTestTicket('Kanban batch accepted');
        $refused  = self::insertKanbanTestTicket('Kanban batch refused');
        $missing  = 99999999;

        try {
            // Visibility on this board is actor based (assigned user/group), so
            // only the accepted ticket is reachable by the current session.
            self::assignTicketToCurrentUser($accepted);

            $result = PluginKanbanKanban::batchAction(
                'change_priority',
                [$accepted, $refused, $missing],
                ['priority' => 5]
            );

            $this->assertSame(3, $result['total']);
            $this->assertSame(1, $result['success_count']);
            $this->assertSame(2, $result['error_count']);

            $this->assertTrue($result['results'][(string)$accepted]['success']);
            $this->assertNull($result['results'][(string)$accepted]['error']);
            $this->assertFalse(
                $result['results'][(string)$refused]['success'],
                'A ticket outside the board visibility must be refused'
            );
            $this->assertNotNull($result['results'][(string)$refused]['error']);
            $this->assertFalse($result['results'][(string)$missing]['success']);
            $this->assertSame($missing, $result['results'][(string)$missing]['id']);

            $priority = $DB->request([
                'SELECT' => ['priority'],
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['id' => $accepted],
            ])->current()['priority'];
            $this->assertSame(5, (int)$priority, 'The accepted ticket must keep the change');

            $untouched = $DB->request([
                'SELECT' => ['priority'],
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['id' => $refused],
            ])->current()['priority'];
            $this->assertSame(3, (int)$untouched, 'The refused ticket must be left untouched');
        } finally {
            self::deleteKanbanTestTickets([$accepted, $refused]);
        }
    }

    /**
     * WIP rules stay per ticket inside a batch, and an unknown action is
     * reported per id instead of silently succeeding.
     */
    public function testBatchActionEnforcesWipAndRejectsUnknownAction(): void
    {
        $saved = \Config::getConfigurationValues('plugin:kanban');

        $ticket_a = self::insertKanbanTestTicket('Kanban batch wip A');
        $ticket_b = self::insertKanbanTestTicket('Kanban batch wip B');

        try {
            self::assignTicketToCurrentUser($ticket_a);
            self::assignTicketToCurrentUser($ticket_b);

            // Derive the limit from the real column count: a hardcoded value
            // only blocks when the suite happens to own the whole column.
            $in_target = PluginKanbanKanban::countVisibleTicketsInStatus(Ticket::ASSIGNED);
            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit'        => $in_target,
                'wip_block_exceed' => 1,
            ]);

            $blocked = PluginKanbanKanban::batchAction(
                'update_status',
                [$ticket_a, $ticket_b],
                ['status' => Ticket::ASSIGNED, 'extra' => []]
            );
            $this->assertSame(0, $blocked['success_count']);
            $this->assertSame(2, $blocked['error_count']);
            foreach ($blocked['results'] as $row) {
                $this->assertFalse($row['success']);
                $this->assertNotNull($row['error']);
            }

            $unknown = PluginKanbanKanban::batchAction('not_an_action', [$ticket_a]);
            $this->assertSame(0, $unknown['success_count']);
            $this->assertSame(1, $unknown['error_count']);
            $this->assertFalse($unknown['results'][(string)$ticket_a]['success']);
        } finally {
            \Config::setConfigurationValues('plugin:kanban', [
                'wip_limit'        => $saved['wip_limit'] ?? 0,
                'wip_block_exceed' => $saved['wip_block_exceed'] ?? 0,
            ]);
            self::deleteKanbanTestTickets([$ticket_a, $ticket_b]);
        }
    }

    /**
     * The batch de-duplicates ids and reuses assignToMe, so an already
     * assigned ticket is accepted instead of being added twice.
     */
    public function testBatchActionDeduplicatesAndAssignsToCurrentUser(): void
    {
        global $DB;

        $ticket = self::insertKanbanTestTicket('Kanban batch assign');

        try {
            self::assignTicketToCurrentUser($ticket);

            $result = PluginKanbanKanban::batchAction('assign_to_me', [$ticket, $ticket]);
            $this->assertSame(1, $result['total'], 'Repeated ids must be collapsed');
            $this->assertSame(1, $result['success_count']);
            $this->assertTrue($result['results'][(string)$ticket]['success']);

            $count = $DB->request([
                'FROM'  => 'glpi_tickets_users',
                'WHERE' => [
                    'tickets_id' => $ticket,
                    'users_id'   => (int)Session::getLoginUserID(),
                    'type'       => CommonITILActor::ASSIGN,
                ],
                'COUNT' => 'c',
            ])->current()['c'] ?? 0;
            $this->assertSame(1, (int)$count, 'assignToMe must not duplicate the technician');
        } finally {
            self::deleteKanbanTestTickets([$ticket]);
        }
    }

    /**
     * Call a private method of the model so tests can pin internal helpers
     * without widening the public API.
     *
     * @param string $method
     * @param array  $args
     * @return mixed
     */
    private static function invokeKanbanPrivate(string $method, array $args)
    {
        $reflection = new ReflectionMethod(PluginKanbanKanban::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $args);
    }

    /**
     * Board ticket ids matching the filters, flattened across every status.
     */
    private static function boardTicketIds(array $filters): array
    {
        $ids = [];
        foreach (PluginKanbanKanban::getTicketsForKanban($filters, ['by' => 'date', 'order' => 'DESC'], 200) as $tickets) {
            foreach ($tickets as $ticket) {
                $ids[] = (int)$ticket['id'];
            }
        }
        return $ids;
    }

    /**
     * Assign a ticket to the session user so the board visibility rule allows it.
     */
    private static function assignTicketToCurrentUser(int $ticket_id): void
    {
        global $DB;

        $DB->insert('glpi_tickets_users', [
            'tickets_id' => $ticket_id,
            'users_id'   => (int)Session::getLoginUserID(),
            'type'       => CommonITILActor::ASSIGN,
        ]);
    }

    /**
     * Insert a ticket with explicit SLA fields and register it for cleanup.
     */
    private static function insertKanbanSlaTicket(array &$ids, array $fields): int
    {
        global $DB;

        $name = (string)$fields['name'];
        unset($fields['name']);

        $id = self::insertKanbanTestTicket($name);
        $ids[] = $id;
        if ($id > 0) {
            $DB->update('glpi_tickets', $fields, ['id' => $id]);
            self::assignTicketToCurrentUser($id);
        }
        return $id;
    }

    /**
     * Remove every actor and the ticket rows created by a test.
     */
    private static function deleteKanbanTestTickets(array $ids): void
    {
        global $DB;

        foreach (array_filter(array_map('intval', $ids)) as $id) {
            $DB->delete('glpi_tickets_users', ['tickets_id' => $id]);
            $DB->delete('glpi_groups_tickets', ['tickets_id' => $id]);
            $DB->delete('glpi_tickets', ['id' => $id]);
        }
    }

    private static function insertKanbanTestUser(string $name): int
    {
        global $DB;

        $lookup = static function () use ($DB, $name): int {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_users',
                'WHERE' => ['name' => $name],
            ]) as $row) {
                return (int)$row['id'];
            }
            return 0;
        };

        // Reuse a user left by a previous run: GLPI 11 throws on a duplicate
        // insert where GLPI 10 only printed a SQL error, so rerunning the suite
        // against the same database would otherwise abort the test.
        $existing = $lookup();
        if ($existing > 0) {
            return $existing;
        }

        $DB->insert('glpi_users', [
            'name' => $name,
            'password' => Auth::getPasswordHash('kanban-test-pass'),
            'auths_id' => 1,
            'entities_id' => 0,
            'is_active' => 1,
            'is_deleted' => 0,
        ]);

        return $lookup();
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
