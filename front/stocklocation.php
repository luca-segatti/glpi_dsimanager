<?php

/**
 * Module Stock - liste « StockLocation » (moteur de recherche natif GLPI).
 */

use GlpiPlugin\Dsimanager\StockLocation;
use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockConfig;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, READ);

Html::header(StockLocation::getTypeName(2), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);

Stock::showNavBar('config');

Stock::showConfigBackBar('', '', true, 'stocklocation.form.php');

Search::show(StockLocation::class);

Html::footer();
