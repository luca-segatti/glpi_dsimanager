<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;

/**
 * Module Stock (v2.4.0) - Motif de sortie du parc (recyclage, restitution
 * leasing, non repris dans un rachat de société...). Liste modifiable.
 */
class StockDisposalReason extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dsimanager_stockdisposalreasons';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Motif de sortie du parc', 'Motifs de sortie du parc', $nb, 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-trash-x';
    }

    public static function getReasons(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'name ASC']) as $row) {
            $out[(int) $row['id']] = $row['name'];
        }
        return $out;
    }

    /** Un motif présent dans l'historique ne se supprime pas. */
    public function pre_deleteItem()
    {
        global $DB;
        if (!parent::pre_deleteItem()) {
            return false;
        }
        $used = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => StockOrder::getTable(),
            'WHERE' => ['plugin_dsimanager_stockdisposalreasons_id' => (int) $this->getID()],
        ])->current()['cpt'];
        if ($used > 0) {
            \Session::addMessageAfterRedirect(__('Motif utilisé dans l\'historique : il ne peut pas être supprimé.', 'dsimanager'), false, ERROR);
            return false;
        }
        return true;
    }
}
