<?php

namespace GlpiPlugin\Dsimanager;

use CommonGLPI;

/**
 * Module Stock - entrée de menu "Contenu" (page front/stock.php).
 * Pas de table : simple porteur de menu et de droit.
 */
class StockContent extends CommonGLPI
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_CONTENT;

    public static function getTypeName($nb = 0)
    {
        return __('Contenu du stock', 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-packages';
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (static::canView()) {
            $menu['title'] = __('Contenu', 'dsimanager');
            $menu['page']  = '/plugins/dsimanager/front/stock.php';
            $menu['icon']  = self::getIcon();
        }
        return $menu;
    }
}
