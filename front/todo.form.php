<?php

use GlpiPlugin\Dsimanager\Todo;

include('../../../inc/includes.php');

$item = new Todo();

if (isset($_POST['add'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_TODO, CREATE);
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    Html::redirect(Todo::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_TODO, UPDATE);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_TODO, PURGE);
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST);
    Html::redirect(Plugin::getWebDir('dsimanager') . '/front/todo.php');
} else {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_TODO, READ);
    Html::header(
        Todo::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'dsimanager',
        Todo::class,
        'todo'
    );
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
