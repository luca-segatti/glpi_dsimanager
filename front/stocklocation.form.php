<?php

/**
 * Module Stock - fiche « StockLocation » (ajout / modification / suppression).
 */

use GlpiPlugin\Dsimanager\StockLocation;
use GlpiPlugin\Dsimanager\StockConfig;

include('../../../inc/includes.php');

$item = new StockLocation();

if (isset($_POST['add'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, CREATE);
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    Html::redirect(StockLocation::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, PURGE);
    $item->check($_POST['id'], PURGE);
    if ($item->delete($_POST, true)) {
        Html::redirect(Plugin::getWebDir('dsimanager') . '/front/stocklocation.php');
    }
    Html::back();
} else {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, READ);
    Html::header(StockLocation::getTypeName(1), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);
    \GlpiPlugin\Dsimanager\Stock::showNavBar('config');
    // Retour rapide vers la liste (et ajout d'un autre élément depuis la fiche).
    \GlpiPlugin\Dsimanager\Stock::showConfigBackBar('stocklocation.php', StockLocation::getTypeName(2), !empty($_GET['id']), 'stocklocation.form.php');
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
