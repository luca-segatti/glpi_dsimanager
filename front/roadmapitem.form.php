<?php

use GlpiPlugin\Dsimanager\RoadmapItem;

include('../../../inc/includes.php');

$item = new RoadmapItem();

if (isset($_POST['add'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, CREATE);
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    Html::redirect(RoadmapItem::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, UPDATE);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, PURGE);
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST);
    global $DB;
    $DB->delete('glpi_plugin_dsimanager_roadmapcells', ['plugin_dsimanager_roadmapitems_id' => (int) $_POST['id']]);
    Html::redirect(Plugin::getWebDir('dsimanager') . '/front/roadmap.php');
} else {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, READ);
    Html::header(
        RoadmapItem::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'dsimanager',
        RoadmapItem::class,
        'roadmap'
    );
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
