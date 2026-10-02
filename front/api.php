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

include ('../../../inc/includes.php');

// Ensure user is logged in
Session::checkLoginUser();

global $CFG_GLPI;

// CSRF protection for POSTs is enforced by GLPI itself before this script runs:
//   - GLPI 10:  inc/includes.php runs Session::checkCSRF($_POST) on every POST.
//   - GLPI 11:  the HTTP kernel's CheckCsrfListener validates every body request.
// Those checks consume the single-use CSRF token (GLPI >= 10.0.8), so calling
// Session::checkCSRF() again here would always fail with "action not allowed".
// The rights, ticket visibility and entity gates inside PluginKanbanApi and
// each model method are the authorization layer on top of GLPI's own CSRF
// validation.

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if (!is_string($action)) {
   $action = '';
}

$api = new PluginKanbanApi();
$api->handle(trim($action));