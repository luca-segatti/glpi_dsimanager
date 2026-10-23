<?php

/**
 * Module Stock - liste « StockArticleCategory » (moteur de recherche natif GLPI).
 */

use GlpiPlugin\Dsimanager\StockArticleCategory;
use GlpiPlugin\Dsimanager\Stock;
use GlpiPlugin\Dsimanager\StockConfig;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG, READ);

Html::header(StockArticleCategory::getTypeName(2), $_SERVER['PHP_SELF'], 'dsimanager_stock', StockConfig::class);

Stock::showNavBar('config');

Stock::showConfigBackBar('', '', true, 'stockarticlecategory.form.php');

Search::show(StockArticleCategory::class);

Html::footer();
