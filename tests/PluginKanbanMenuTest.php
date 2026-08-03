<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for PluginKanbanMenu (menu integration with GLPI).
 */
class PluginKanbanMenuTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $user = new User();
        if ($user->getFromDB(2)) {
            $auth = new Auth();
            $auth->auth_succeded = true;
            $auth->user = $user;
            Session::init($auth);
        }
    }

    public function testGetIconReturnsExpectedIcon(): void
    {
        $this->assertSame('ti ti-layout-kanban', PluginKanbanMenu::getIcon());
    }

    public function testGetMenuContentReturnsArray(): void
    {
        $menu = PluginKanbanMenu::getMenuContent();
        $this->assertIsArray($menu);
    }

    public function testGetMenuContentHasRequiredKeys(): void
    {
        $menu = PluginKanbanMenu::getMenuContent();
        if (Ticket::canView()) {
            $this->assertArrayHasKey('title', $menu);
            $this->assertArrayHasKey('page', $menu);
            $this->assertArrayHasKey('icon', $menu);
            $this->assertArrayHasKey('options', $menu);
            $this->assertArrayHasKey('kanban', $menu['options']);
            $this->assertArrayHasKey('tickets', $menu['options']);
            $this->assertSame('/plugins/kanban/front/kanban.php', $menu['page']);
        } else {
            $this->assertSame([], $menu);
        }
    }
}
