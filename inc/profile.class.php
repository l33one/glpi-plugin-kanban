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

/**
 * Adds a "Kanban" tab to the profile form so that admins can grant/revoke
 * the right to view the Kanban page per profile.
 */
class PluginKanbanProfile extends Profile {

   /** Right used to view/edit profiles (tab content gating) */
   public static $rightname = 'profile';

   /**
    * Get tab title
    *
    * @param integer $nb Number of items
    * @return string
    */
   public static function getTypeName($nb = 0) {
      return __('Kanban', 'kanban');
   }

   /**
    * Get the tab name displayed on the profile form
    *
    * @param CommonGLPI $item          Profile item
    * @param integer    $withtemplate  Template mode
    * @return string
    */
   public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item instanceof Profile) {
         return self::createTabEntry(__('Kanban', 'kanban'));
      }
      return '';
   }

   /**
    * Display the tab content on the profile form
    *
    * @param CommonGLPI $item          Profile item
    * @param integer    $tabnum        Tab number
    * @param integer    $withtemplate  Template mode
    * @return boolean
    */
   public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      if ($item instanceof Profile) {
         $profile = new self();
         $profile->showForm($item->getID());
      }
      return true;
   }

   /**
    * Show the Kanban right matrix for a given profile
    *
    * @param integer $profiles_id Profile ID
    * @param boolean $openform    Whether to open the HTML form
    * @param boolean $closeform   Whether to close the HTML form
    * @return boolean
    */
   public function showForm($profiles_id = 0, $openform = true, $closeform = true) {
      if (!self::canView()) {
         return false;
      }

      $profile = new Profile();
      $profile->getFromDB($profiles_id);

      $can_edit = Session::haveRight(self::$rightname, UPDATE);

      echo "<div class='spaced'>";
      if ($openform && $can_edit) {
         echo "<form method='post' action='" . $profile::getFormURL() . "' data-track-changes='true'>";
      }

      $rights = [
         [
            'itemtype' => PluginKanbanKanban::class,
            'label'    => __('Kanban board', 'kanban'),
            'field'    => PluginKanbanKanban::$rightname,
         ],
      ];

      $profile->displayRightsChoiceMatrix($rights, [
         'canedit'       => $can_edit,
         'default_class' => 'tab_bg_2',
         'title'         => __('Kanban', 'kanban'),
      ]);

      if ($can_edit && $closeform) {
         echo "<div class='center'>";
         echo Html::hidden('id', ['value' => $profiles_id]);
         echo Html::submit(_sx('button', 'Save'), [
            'name'  => 'update',
            'class' => 'btn btn-primary mt-2',
         ]);
         echo "</div>";
         Html::closeForm();
      }
      echo "</div>";
      return true;
   }
}
