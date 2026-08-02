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
   // Safe initialization: no custom tables needed as we represent native Tickets
   return true;
}

/**
 * Uninstall hook
 *
 * @return boolean
 */
function plugin_kanban_uninstall() {
   // Clean up resources if any were created
   return true;
}
