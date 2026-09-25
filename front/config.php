<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

// Plugin configuration persists real settings; only profiles able to update
// the GLPI configuration (super-admin) may access it.
if (!Session::haveRight('config', UPDATE)) {
   Html::displayRightError();
}

Html::header(
   __('Kanban Configuration', 'kanban'),
   $_SERVER['PHP_SELF']
);

$config = new PluginKanbanConfig();
$config->display();

Html::footer();
