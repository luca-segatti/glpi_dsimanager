<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;

/**
 * Module Stock - Catégorie d'article quantitatif (périphériques, câbles...).
 */
class StockArticleCategory extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dsimanager_stockarticlecategories';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Catégorie d\'article (stock)', 'Catégories d\'article (stock)', $nb, 'dsimanager');
    }
}
