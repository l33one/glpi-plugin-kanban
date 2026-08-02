<?php
/*
 -------------------------------------------------------------------------
 Livechat plugin for GLPI
 Copyright (C) 2020 by the livechat Development Team.

 https://github.com/pluginsGLPI/livechat
 -------------------------------------------------------------------------

 LICENSE

 This file is part of Livechat.

 Livechat is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Livechat is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with Livechat. If not, see <http://www.gnu.org/licenses/>.

------------------------------------------------------------------------

   @package   Plugin kanban
   @author    Leewan Meneses
   @co-author
   @copyright Copyright (c) 2009-2016 Barcode plugin Development team
   @license   GPL-2.0+
   @link      https://github.com/l33one/
   @since     2026


 --------------------------------------------------------------------------
 */

define('PLUGIN_KANBAN_VERSION', '1.0.0');

/**
 * Init the hooks of the plugin
 *
 * @return void
 */
function plugin_init_kanban() {
   global $PLUGIN_HOOKS;

   $PLUGIN_HOOKS['csrf_compliant']['kanban'] = true;

   if (Plugin::isPluginActive('kanban')) {
      // Add to Assistance menu (internal key is 'helpdesk')
      $PLUGIN_HOOKS['menu_toadd']['kanban'] = ['helpdesk' => 'PluginKanbanMenu'];
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
      'homepage'       => '',
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
