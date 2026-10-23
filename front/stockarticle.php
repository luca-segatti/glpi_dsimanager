<?php

/**
 * Module Stock - liste « StockArticle » (moteur de recherche natif GLPI).
 */

use GlpiPlugin\Dsimanager\StockArticle;
use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockConfig;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, READ);

Html::header(StockArticle::getTypeName(2), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);

Stock::showNavBar('config');

Stock::showConfigBackBar('', '', true, 'stockarticle.form.php');

Search::show(StockArticle::class);

Html::footer();
