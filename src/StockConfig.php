<?php

namespace GlpiPlugin\Dsimanager;

use CommonGLPI;

/**
 * Module Stock - entrée de menu "Configuration" (page front/stockconfig.php).
 */
class StockConfig extends CommonGLPI
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG;

    public static function getTypeName($nb = 0)
    {
        return __('Configuration du stock', 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-settings';
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (static::canView()) {
            $menu['title'] = __('Configuration', 'dsimanager');
            $menu['page']  = '/plugins/dsimanager/front/stockconfig.php';
            $menu['icon']  = self::getIcon();
        }
        return $menu;
    }
}
