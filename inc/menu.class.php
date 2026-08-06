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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginKanbanMenu extends CommonGLPI {

   /**
    * Get menu icon
    * 
    * @return string
    */
   public static function getIcon() {
      return 'ti ti-layout-kanban';
   }

   /**
    * Define the menu details for GLPI menu entry.
    * This registers the plugin under the "Assistance" section of the GLPI menu
    * and provides a sub-item to open the native GLPI ticket list.
    *
    * @return array
    */
   public static function getMenuContent() {
      $menu = [];

      if (PluginKanbanKanban::canView() && Ticket::canView()) {
         $menu['title'] = __('Kanban', 'kanban');
         $menu['page']  = '/plugins/kanban/front/kanban.php';
         $menu['icon']  = 'ti ti-layout-kanban';

         // Sub-menu options displayed when hovering/expanding the menu item
         $menu['options'] = [
            'kanban' => [
               'title' => __('Kanban Board', 'kanban'),
               'page'  => '/plugins/kanban/front/kanban.php',
               'icon'  => 'ti ti-layout-kanban',
            ],
            'tickets' => [
               'title' => __('Open in GLPI', 'kanban'),
               'page'  => '/front/ticket.php',
               'icon'  => 'ti ti-external-link',
            ],
         ];
      }

      return $menu;
   }
}
