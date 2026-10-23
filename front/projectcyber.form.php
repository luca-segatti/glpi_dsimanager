<?php

use GlpiPlugin\Dsimanager\ProjectCyber;

include('../../../inc/includes.php');

$item = new ProjectCyber();

if (isset($_POST['add'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_CYBER, CREATE);
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    Html::redirect(ProjectCyber::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_CYBER, UPDATE);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_CYBER, PURGE);
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST);
    Html::redirect(Plugin::getWebDir('dsimanager') . '/front/projectcyber.php');
} else {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_CYBER, READ);
    Html::header(
        ProjectCyber::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'dsimanager',
        ProjectCyber::class,
        'projectcyber'
    );
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
