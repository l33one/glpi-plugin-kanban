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
 * Also serves as the upgrade hook: GLPI has no plugin_<name>_update(). When the
 * version in setup.php is ahead of the installed one, the plugin is flagged
 * NOTUPDATED and the "Upgrade" button posts action=install, so this function
 * runs again (see Plugin::install()). Everything below is idempotent - the
 * CREATE TABLE is guarded by tableExists() and addRight() re-applies the same
 * defaults - so upgrading from 1.2.2 gains the presets table with no extra code.
 *
 * @return boolean
 */
function plugin_kanban_install() {
    /** @var DBmysql $DB */
    global $DB;

    // Register the "plugin_kanban" right in glpi_profilerights.
    // Profiles able to update the config (super-admin) get READ access;
    // every other profile gets the right row with no access so that admins
    // can enable it later from the Profile form.
    $migration = new Migration(PLUGIN_KANBAN_VERSION);
    $migration->addRight(PluginKanbanKanban::$rightname, READ, ['config' => READ | UPDATE]);

    // Saved board views. is_private = 0 rows are visible to everyone who can
    // see the board; users_id = 0 marks a row that no longer has an owner (the
    // profile that created it was deleted) and hides it from the list.
    //
    // Migration exposes no createTable() in GLPI 10 nor in 11 - addField() only
    // ever emits ALTER TABLE, which needs the table to be there - so the DDL is
    // ours. addKey() goes through the pre-query queue, hence the keys are
    // declared inline; the else branch only repairs a table that predates them.
    if (!$DB->tableExists('glpi_kanban_filter_presets')) {
        $migration->addPreQuery(
            "CREATE TABLE `glpi_kanban_filter_presets` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(255) DEFAULT NULL,
                `filters` text,
                `is_private` tinyint(1) NOT NULL DEFAULT '1',
                `users_id` int unsigned NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `users_id` (`users_id`),
                KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
            PLUGIN_KANBAN_VERSION . ' create glpi_kanban_filter_presets'
        );
    } else {
        $migration->addKey('glpi_kanban_filter_presets', 'users_id');
        $migration->addKey('glpi_kanban_filter_presets', 'name');
    }

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

