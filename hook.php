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
    // No custom tables needed - uses native GLPI Ticket infrastructure
    // Register the plugin config page for Super-Admin access
    return true;
}

/**
 * Uninstall hook
 *
 * @return boolean
 */
function plugin_kanban_uninstall() {
    // No custom tables to drop - plugin uses native GLPI Ticket tables
    // Any plugin-specific config could be cleaned up here
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

