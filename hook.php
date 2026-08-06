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

/**
 * Install hook
 *
 * @return boolean
 */
function plugin_kanban_install() {
    // Register the "plugin_kanban" right in glpi_profilerights.
    // Profiles able to update the config (super-admin) get full access;
    // every other profile gets the right row with no access so that admins
    // can enable it later from the Profile form.
    $migration = new Migration(PLUGIN_KANBAN_VERSION);
    $migration->addRight(PluginKanbanKanban::$rightname, ALLSTANDARDRIGHT, ['config' => READ | UPDATE]);
    $migration->executeMigration();

    return true;
}

/**
 * Uninstall hook
 *
 * @return boolean
 */
function plugin_kanban_uninstall() {
    // Remove the plugin right from glpi_profilerights
    ProfileRight::deleteProfileRights([PluginKanbanKanban::$rightname]);

    return true;
}

/**
 * Get configuration page for the plugin
 *
 * @param string $name Configuration option name
 * @return mixed
 */
function plugin_kanban_get_config($name) {
    static $config = null;

    if ($config === null) {
        $config = [];
        // Default maximum tickets per status column
        $config['max_tickets_per_status'] = 200;
    }

    return $config[$name] ?? null;
}

/**
 * Set configuration page for the plugin
 *
 * @param string $name Configuration option name
 * @param mixed  $value Configuration option value
 * @return boolean
 */
function plugin_kanban_set_config($name, $value) {
    // No persistent config storage implemented yet
    return false;
}

