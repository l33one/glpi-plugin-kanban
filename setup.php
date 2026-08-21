<?php
/*
 * -------------------------------------------------------------------------
 *  GLPI Kanban Plugin
 *  Copyright (C) 2026 by Leewan Meneses.
 *
 *  https://github.com/l33one/glpi-plugin-kanban
 *  -------------------------------------------------------------------------
 *
 *  LICENSE
 *
 *  This file is part of GLPI Kanban Plugin.
 *
 *  Kanban Plugin is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  Kanban Plugin is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with Kanban Plugin. If not, see <http://www.gnu.org/licenses/>.
 *
 *  --------------------------------------------------------------------------
 *
 *    @package   Plugin kanban
 *    @author    Leewan Meneses
 *    @copyright Copyright (c) 2026 Leewan Meneses
 *    @license   GPL-2.0+
 *    @link      https://github.com/l33one/glpi-plugin-kanban
 *    @since     2026
 *
 *  --------------------------------------------------------------------------
 */

define('PLUGIN_KANBAN_VERSION', '1.2.0');

/**
 * Init the hooks of the plugin
 *
 * @return void
 */
function plugin_init_kanban() {
   global $PLUGIN_HOOKS;

   $PLUGIN_HOOKS['csrf_compliant']['kanban'] = true;

   if (Plugin::isPluginActive('kanban')) {
      // Register the plugin under the Helpdesk menu
      $PLUGIN_HOOKS['menu_toadd']['kanban'] = [
         'helpdesk' => 'PluginKanbanMenu',
      ];

      // Register the plugin under the Setup menu for configuration
      $PLUGIN_HOOKS['menu_toadd']['kanban']['config'] = 'PluginKanbanConfig';

      // Load CSS and JS assets in the plugin page
      $PLUGIN_HOOKS['add_css']['kanban']      = ['public/css/kanban.css'];
      $PLUGIN_HOOKS['add_javascript']['kanban'] = ['public/js/kanban.js'];

      // Declare that this plugin does not use custom database tables
      $PLUGIN_HOOKS['use_tables']['kanban'] = [];

      // Set the plugin version constant (must be before registerClass to avoid
      // breaking the plugin init if a class fails to load).
      $PLUGIN_HOOKS['plugin_version']['kanban'] = PLUGIN_KANBAN_VERSION;

      // Register the profile tab so the Kanban view right can be configured per profile
      try {
         Plugin::registerClass('PluginKanbanProfile', ['addtabon' => ['Profile']]);
      } catch (\Throwable $e) {
         // Non-fatal: profile tab won't work but the board will still load
      }

      // Register the config class
      try {
         Plugin::registerClass('PluginKanbanConfig');
      } catch (\Throwable $e) {
         // Non-fatal: config page won't work but the board will still load
      }
   }
}

/**
 * Get the name and details of the plugin
 *
 * @return array
 */
function plugin_version_kanban() {
   return [
      'name'           => __('Kanban', 'kanban'),
      'version'        => PLUGIN_KANBAN_VERSION,
      'author'         => 'Leewan Meneses',
      'license'        => 'GPLv2+',
      'homepage'       => 'https://github.com/l33one/glpi-plugin-kanban',
      'requirements'   => [
         'glpi' => [
            'min' => '10.0.0',
         ],
         'php' => [
            'min' => '8.0.0',
         ]
      ]
   ];
}

/**
 * Check prerequisites for installation
 *
 * @return boolean
 */
function plugin_kanban_check_prereq() {
   // Check if the plugin directory is named correctly
   if (!is_dir(GLPI_ROOT . '/plugins/kanban')) {
      echo __('The plugin directory must be named "kanban"', 'kanban');
      return false;
   }
   return true;
}

/**
 * Check configuration
 *
 * @param boolean $verbose Verbose mode
 * @return boolean
 */
function plugin_kanban_check_config($verbose = false) {
   return true;
}
