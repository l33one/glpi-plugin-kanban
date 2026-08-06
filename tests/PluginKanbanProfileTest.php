<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for PluginKanbanProfile (profile tab / permission configuration).
 */
class PluginKanbanProfileTest extends TestCase
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

    public function testClassExtendsProfile(): void
    {
        $this->assertTrue(is_subclass_of(PluginKanbanProfile::class, Profile::class));
    }

    public function testTypeNameIsNotEmpty(): void
    {
        $this->assertNotSame('', PluginKanbanProfile::getTypeName());
    }

    public function testGetTabNameForNonProfileItemIsEmpty(): void
    {
        $profile = new PluginKanbanProfile();
        $this->assertSame('', $profile->getTabNameForItem(new CommonDBTM()));
    }

    public function testGetTabNameForProfileItemIsNotEmpty(): void
    {
        $profile = new PluginKanbanProfile();
        $this->assertNotSame('', $profile->getTabNameForItem(new Profile()));
    }

    public function testDisplayTabContentForItemReturnsTrueForProfile(): void
    {
        ob_start();
        try {
            $result = PluginKanbanProfile::displayTabContentForItem(new Profile());
        } finally {
            ob_end_clean();
        }
        $this->assertTrue($result);
    }

    public function testRightIsExposedOnKanbanClass(): void
    {
        $this->assertSame('plugin_kanban', PluginKanbanKanban::$rightname);
        $rights = PluginKanbanKanban::getRights();
        $this->assertArrayHasKey(READ, $rights);
    }
}
