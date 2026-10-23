<?php

namespace GlpiPlugin\Dsimanager;

use CommonGLPI;

/**
 * Module Stock (v2.4.0) - entrée de menu "Sorti du parc"
 * (page front/stockdisposed.php).
 */
class StockDisposed extends CommonGLPI
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_CONTENT;

    public static function getTypeName($nb = 0)
    {
        return __('Matériel sorti du parc', 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-trash-x';
    }

    public static function canView(): bool
    {
        return Stock::canViewDisposed();
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (static::canView()) {
            $menu['title'] = __('Sorti du parc', 'dsimanager');
            $menu['page']  = '/plugins/dsimanager/front/stockdisposed.php';
            $menu['icon']  = self::getIcon();
        }
        return $menu;
    }
}
