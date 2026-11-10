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

define('PLUGIN_KANBAN_VERSION', '1.4.1');

/**
 * Init the hooks of the plugin
 *
 * @return void
 */
function plugin_init_kanban() {
   global $PLUGIN_HOOKS;

   $PLUGIN_HOOKS['csrf_compliant']['kanban'] = true;

   if (Plugin::isPluginActive('kanban')) {
      // GLPI only loads locales/<glpilanguage>.mo, so a regional variant such as
      // es_AR or fr_CA would otherwise fall back to English. Load the matching
      // base catalog under the active locale before any label is translated.
      plugin_kanban_load_locale_fallback();

      // Register the plugin under the Helpdesk menu
      $PLUGIN_HOOKS['menu_toadd']['kanban'] = [
         'helpdesk' => 'PluginKanbanMenu',
      ];

      // Load CSS and JS assets in the plugin page
      $PLUGIN_HOOKS['add_css']['kanban']      = ['public/css/kanban.css'];
      $PLUGIN_HOOKS['add_javascript']['kanban'] = ['public/js/kanban.utils.js', 'public/js/kanban.js'];

      // Saved board views live in their own table (created by the install
      // migration and declared here so GLPI installs/updates it).
      $PLUGIN_HOOKS['use_tables']['kanban'] = [
         'glpi_kanban_filter_presets',
         'glpi_kanban_filter_defaults',
      ];

      // Register the config page so the "Configure" button appears on the plugins list
      $PLUGIN_HOOKS['config_page']['kanban'] = 'front/config.php';

      // Set the plugin version constant (must be before registerClass to avoid
      // breaking the plugin init if a class fails to load).
      $PLUGIN_HOOKS['plugin_version']['kanban'] = PLUGIN_KANBAN_VERSION;

      // Register the profile tab so the Kanban view right can be configured per profile
      try {
         Plugin::registerClass('PluginKanbanProfile', ['addtabon' => ['Profile']]);
      } catch (\Throwable $e) {
         // Non-fatal: profile tab won't work but the board will still load.
         // Log it so a genuine class-loading failure is not silently hidden.
         error_log('[kanban] Plugin::registerClass(PluginKanbanProfile) failed: ' . $e->getMessage());
      }

      // Register the config class
      try {
         Plugin::registerClass('PluginKanbanConfig');
      } catch (\Throwable $e) {
         // Non-fatal: config page won't work but the board will still load.
         // Log it so a genuine class-loading failure is not silently hidden.
         error_log('[kanban] Plugin::registerClass(PluginKanbanConfig) failed: ' . $e->getMessage());
      }

      // Saved board views: CommonDBTM, so GLPI can show the object itself.
      // A read failure must not hide the board, so it is logged, not thrown.
      try {
         Plugin::registerClass('PluginKanbanFilterPreset');
      } catch (\Throwable $e) {
         error_log('[kanban] Plugin::registerClass(PluginKanbanFilterPreset) failed: ' . $e->getMessage());
      }
   }
}

/**
 * Load a base catalog for regional language variants
 *
 * GLPI resolves plugin translations by the exact session language only
 * (e.g. es_AR looks for locales/es_AR.mo) and silently falls back to
 * en_GB.mo when that file is absent. The plugin ships one catalog per
 * language, so regional variants reuse it: es_* -> es_ES, fr_* -> fr_FR,
 * pt_* -> pt_BR. Plugin::loadLang() is called again with the base language
 * forced, registering the catalog under the language actually in use.
 *
 * @return void
 */
function plugin_kanban_load_locale_fallback() {
   global $TRANSLATE;

   if (!is_object($TRANSLATE) || empty($_SESSION['glpilanguage'])) {
      return;
   }

   $current = $_SESSION['glpilanguage'];

   // Base catalog used for each language family. Languages not listed here
   // are left untouched so GLPI keeps its native resolution.
   $base_catalogs = [
      'es' => 'es_ES',
      'fr' => 'fr_FR',
      'pt' => 'pt_BR',
   ];

   $family = explode('_', $current)[0];
   if (!isset($base_catalogs[$family])) {
      return;
   }

   $base = $base_catalogs[$family];
   if ($current === $base) {
      return;
   }

   $locales_dir = __DIR__ . '/locales/';
   // Keep an exact catalog when one exists for the variant; only fall back
   // to the base catalog when the requested locale has no file of its own.
   if (is_file($locales_dir . $current . '.mo') || !is_file($locales_dir . $base . '.mo')) {
      return;
   }

   Plugin::loadLang('kanban', $base, $current);

   // The English fallback may already be cached for this locale, either in
   // memory (this request) or in the shared cache (previous requests), and a
   // cached text domain is never refreshed on its own. Drop both entries so the
   // catalog registered above is read on the next lookup.
   if (method_exists($TRANSLATE, 'clearCache')) {
      $TRANSLATE->clearCache('kanban', $current);
   }

   $reflection = new ReflectionObject($TRANSLATE);
   if ($reflection->hasProperty('messages')) {
      $property = $reflection->getProperty('messages');
      $property->setAccessible(true);
      $messages = $property->getValue($TRANSLATE);
      if (isset($messages['kanban'][$current])) {
         unset($messages['kanban'][$current]);
         $property->setValue($TRANSLATE, $messages);
      }
   }

   // Resolve the domain once so the freshly registered catalog is merged and
   // stored in the shared cache; later lookups reuse it instead of the
   // English fallback loaded before this point.
   $TRANSLATE->translate('Kanban', 'kanban', $current);
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
