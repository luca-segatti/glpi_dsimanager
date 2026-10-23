<?php

use GlpiPlugin\Dsimanager\RoadmapPriority;

include('../../../inc/includes.php');

$item = new RoadmapPriority();

if (isset($_POST['add'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, CREATE);
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    Html::redirect(RoadmapPriority::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, UPDATE);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, PURGE);
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST);
    Html::redirect(Plugin::getWebDir('dsimanager') . '/front/roadmappriority.php');
} else {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, READ);
    Html::header(
        RoadmapPriority::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'dsimanager',
        \GlpiPlugin\Dsimanager\RoadmapItem::class,
        'roadmap'
    );
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
