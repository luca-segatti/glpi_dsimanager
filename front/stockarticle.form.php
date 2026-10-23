<?php

/**
 * Module Stock - fiche « StockArticle » (ajout / modification / suppression).
 */

use GlpiPlugin\Dsimanager\StockArticle;
use GlpiPlugin\Dsimanager\StockConfig;

include('../../../inc/includes.php');

$item = new StockArticle();

if (isset($_POST['add'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, CREATE);
    $item->check(-1, CREATE, $_POST);
    $newID = $item->add($_POST);
    Html::redirect(StockArticle::getFormURLWithID($newID));
} elseif (isset($_POST['update'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['add_component']) || isset($_POST['delete_component'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, UPDATE);
    $bid = (int) ($_POST['id'] ?? 0);
    if (isset($_POST['add_component'])) {
        $err = StockArticle::addComponent($bid, (int) ($_POST['component_id'] ?? 0), (int) ($_POST['component_qty'] ?? 1));
        if ($err !== '') {
            Session::addMessageAfterRedirect(htmlspecialchars($err), false, ERROR);
        }
    } else {
        StockArticle::deleteComponent($bid, (int) $_POST['delete_component']);
    }
    Html::back();
} elseif (isset($_POST['purge'])) {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, PURGE);
    $item->check($_POST['id'], PURGE);
    if ($item->delete($_POST, true)) {
        Html::redirect(Plugin::getWebDir('dsimanager') . '/front/stockarticle.php');
    }
    Html::back();
} else {
    Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, READ);
    Html::header(StockArticle::getTypeName(1), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);
    \GlpiPlugin\Dsimanager\Stock::showNavBar('config');
    // Retour rapide vers la liste (et ajout d'un autre élément depuis la fiche).
    \GlpiPlugin\Dsimanager\Stock::showConfigBackBar('stockarticle.php', StockArticle::getTypeName(2), !empty($_GET['id']), 'stockarticle.form.php');
    $item->display(['id' => $_GET['id'] ?? 0]);
    Html::footer();
}
