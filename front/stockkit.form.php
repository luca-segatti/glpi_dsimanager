<?php

/**
 * Module Stock - fiche « StockKit » (ajout / modification / suppression).
 */

use GlpiPlugin\Dsimanager\StockKit;
use GlpiPlugin\Dsimanager\StockConfig;

include('../../../inc/includes.php');

$item = new StockKit();

if (isset($_POST['add'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, CREATE);
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    Html::redirect(StockKit::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['add_kit_item']) || isset($_POST['delete_kit_item'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE);
    $kid = (int) ($_POST['id'] ?? 0);
    if (!$item->getFromDB($kid)) {
        Html::back();
    }
    if (isset($_POST['add_kit_item'])) {
        $err = StockKit::addItem($kid, $_POST);
        if ($err !== '') {
            Session::addMessageAfterRedirect(htmlspecialchars($err), false, ERROR);
        }
    } else {
        StockKit::deleteItem($kid, (int) $_POST['delete_kit_item']);
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, PURGE);
    $item->check($_POST['id'], PURGE);
    if ($item->delete($_POST, true)) {
        Html::redirect(Plugin::getWebDir('dsimanager') . '/front/stockkit.php');
    }
    Html::back();
} else {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, READ);
    Html::header(StockKit::getTypeName(1), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);
    \GlpiPlugin\Dsimanager\Stock::showNavBar('config');
    // Retour rapide vers la liste (et ajout d'un autre élément depuis la fiche).
    \GlpiPlugin\Dsimanager\Stock::showConfigBackBar('stockkit.php', StockKit::getTypeName(2), !empty($_GET['id']), 'stockkit.form.php');
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
