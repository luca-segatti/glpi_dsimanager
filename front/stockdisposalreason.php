<?php

/**
 * Module Stock - liste « StockDisposalReason » (moteur de recherche natif GLPI).
 */

use GlpiPlugin\Dsimanager\StockDisposalReason;
use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockConfig;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, READ);

Html::header(StockDisposalReason::getTypeName(2), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);

Stock::showNavBar('config');

Stock::showConfigBackBar('', '', true, 'stockdisposalreason.form.php');

Search::show(StockDisposalReason::class);

Html::footer();
