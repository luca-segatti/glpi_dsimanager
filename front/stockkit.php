<?php

/**
 * Module Stock - liste « StockKit » (moteur de recherche natif GLPI).
 */

use GlpiPlugin\Dsimanager\StockKit;
use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockConfig;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, READ);

Html::header(StockKit::getTypeName(2), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);

Stock::showNavBar('config');

Stock::showConfigBackBar('', '', true, 'stockkit.form.php');

Search::show(StockKit::class);

Html::footer();
