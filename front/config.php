<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!Session::haveRight('config', UPDATE)) {
   Html::displayRightError();
}

Html::header(
   __('Kanban Configuration', 'kanban'),
   $_SERVER['PHP_SELF'],
   "config",
   "pluginkanbanconfig"
);

$config = new PluginKanbanConfig();
$config->display();

Html::footer();
