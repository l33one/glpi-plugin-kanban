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
    // Profiles able to update the config (super-admin) get READ access;
    // every other profile gets the right row with no access so that admins
    // can enable it later from the Profile form.
    $migration = new Migration(PLUGIN_KANBAN_VERSION);
    $migration->addRight(PluginKanbanKanban::$rightname, READ, ['config' => READ | UPDATE]);
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

    // Remove config values stored through GLPI's configuration API, and clean
    // up the legacy kanban_config.php file (pre-security-fix releases persisted
    // the config as an includable PHP file).
    \Config::deleteConfigurationValues('plugin:kanban');
    $legacy_config = GLPI_CONFIG_DIR . '/kanban_config.php';
    if (is_file($legacy_config)) {
        @unlink($legacy_config);
    }

    return true;
}

