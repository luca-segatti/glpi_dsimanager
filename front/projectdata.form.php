<?php

use GlpiPlugin\Dsimanager\ProjectData;

include('../../../inc/includes.php');

$item = new ProjectData();

if (isset($_POST['add'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_DATA, CREATE);
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    Html::redirect(ProjectData::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_DATA, UPDATE);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_DATA, PURGE);
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST);
    Html::redirect(Plugin::getWebDir('dsimanager') . '/front/projectdata.php');
} else {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_DATA, READ);
    Html::header(
        ProjectData::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'dsimanager',
        ProjectData::class,
        'projectdata'
    );
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
