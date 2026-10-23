<?php

namespace GlpiPlugin\Dsimanager;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;

/**
 * Module Stock - Ligne de commande. Deux natures :
 *  - KIND_UNIT : un actif GLPI natif (itemtype + items_id), quantité 1 ;
 *  - KIND_QTY  : un article du catalogue (quantité libre).
 * Fournit aussi l'onglet "Mouvements de stock" sur les fiches des actifs.
 */
class StockOrderLine extends CommonDBTM
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY;

    public const KIND_UNIT = 1;
    public const KIND_QTY  = 2;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dsimanager_stockorderlines';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Mouvement de stock', 'Mouvements de stock', $nb, 'dsimanager');
    }

    /**
     * Valeurs actuelles d'un actif à figer dans une ligne de commande.
     */
    public static function snapshotAsset(string $itemtype, int $items_id): array
    {
        global $DB;
        $asset = new $itemtype();
        if (!$asset->getFromDB($items_id)) {
            return ['label' => '#' . $items_id, 'serial' => '', 'otherserial' => ''];
        }
        $idf = Stock::getIdentifierField($itemtype);
        return [
            'label'       => (string) ($asset->fields['name'] ?? ''),
            'serial'      => (string) ($asset->fields[$idf] ?? ''),
            'otherserial' => $DB->fieldExists($itemtype::getTable(), 'otherserial') ? (string) ($asset->fields['otherserial'] ?? '') : '',
        ];
    }

    // ------------------------------------------------------------------
    // Onglet "Mouvements de stock" sur les actifs natifs
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$withtemplate
            && StockOrder::canView()
            && isset(Stock::getUnitItemtypes()[$item->getType()])
            && !$item->isNewItem()
        ) {
            $nb = 0;
            if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
                $nb = count(self::getMovementsForAsset($item->getType(), (int) $item->getID()));
            }
            return self::createTabEntry(__('Mouvements de stock', 'dsimanager'), $nb, $item::getType(), 'ti ti-packages');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        self::showForAsset($item->getType(), (int) $item->getID());
        return true;
    }

    /**
     * Mouvements validés (et brouillons en cours) concernant un actif.
     */
    public static function getMovementsForAsset(string $itemtype, int $items_id): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'SELECT'     => [
                'o.id', 'o.type', 'o.status', 'o.date_order', 'o.date_validation',
                'o.stocklocations_id_from', 'o.stocklocations_id_to',
                'o.users_id_beneficiary', 'o.users_id_executor', 'o.comment',
                'l.label', 'l.serial',
            ],
            'FROM'       => self::getTable() . ' AS l',
            'INNER JOIN' => [
                StockOrder::getTable() . ' AS o' => [
                    'FKEY' => ['l' => 'plugin_dsimanager_stockorders_id', 'o' => 'id'],
                ],
            ],
            'WHERE'      => [
                'l.itemtype' => $itemtype,
                'l.items_id' => $items_id,
                'o.status'   => [StockOrder::STATUS_DRAFT, StockOrder::STATUS_VALIDATED],
            ],
            'ORDER'      => ['o.date_validation DESC', 'o.id DESC'],
        ]), false);
    }

    public static function showForAsset(string $itemtype, int $items_id): void
    {
        $base = \Plugin::getWebDir('dsimanager') . '/front';
        $loc  = Stock::getUnitLocation($itemtype, $items_id);

        echo "<div class='firstbloc'>";
        if ($loc > 0) {
            echo "<div class='alert alert-info'><i class='ti ti-packages'></i>&nbsp;"
               . sprintf(__('Actuellement en stock : %s', 'dsimanager'), "<strong>" . htmlspecialchars(Stock::getLocationName($loc)) . "</strong>")
               . "</div>";
        } else {
            echo "<p class='text-muted'>" . __('Cet actif n\'est pas en stock actuellement.', 'dsimanager') . "</p>";
        }

        $rows = self::getMovementsForAsset($itemtype, $items_id);
        if (!count($rows)) {
            echo "<p class='text-muted'>" . __('Aucun mouvement de stock pour cet actif.', 'dsimanager') . "</p></div>";
            return;
        }
        echo "<table class='tab_cadre_fixehov'>";
        echo "<tr><th>" . __('Commande', 'dsimanager') . "</th><th>" . __('Type', 'dsimanager') . "</th><th>" . __('Statut', 'dsimanager')
           . "</th><th>" . __('Date', 'dsimanager') . "</th><th>" . __('Emplacement', 'dsimanager') . "</th><th>" . __('Bénéficiaire', 'dsimanager')
           . "</th><th>" . __('Exécutant', 'dsimanager') . "</th><th>" . __('Commentaire', 'dsimanager') . "</th></tr>";
        foreach ($rows as $row) {
            $type = (int) $row['type'];
            $ref  = StockOrder::formatReference($type, (int) $row['id']);
            $date = $row['date_validation'] ? Html::convDateTime($row['date_validation']) : Html::convDate($row['date_order']);
            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='$base/stockorder.form.php?id=" . (int) $row['id'] . "'>" . $ref . "</a></td>";
            echo "<td>" . self::typeBadge($type) . "</td>";
            echo "<td>" . StockOrder::getStatusLabel((int) $row['status']) . "</td>";
            echo "<td>" . $date . "</td>";
            echo "<td>" . htmlspecialchars(self::formatLocations($type, (int) $row['stocklocations_id_from'], (int) $row['stocklocations_id_to'])) . "</td>";
            echo "<td>" . ($row['users_id_beneficiary'] ? htmlspecialchars(getUserName($row['users_id_beneficiary'])) : '') . "</td>";
            echo "<td>" . ($row['users_id_executor'] ? htmlspecialchars(getUserName($row['users_id_executor'])) : '') . "</td>";
            echo "<td>" . htmlspecialchars((string) $row['comment']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p class='mt-2'><a href='$base/stockorder.php?itemtype=" . urlencode($itemtype) . "&items_id=$items_id'>"
           . __('Voir dans l\'historique du stock', 'dsimanager') . "</a></p>";
        echo "</div>";
    }

    // ------------------------------------------------------------------
    // Petits helpers d'affichage partagés
    // ------------------------------------------------------------------

    public static function typeBadge(int $type): string
    {
        $color = StockOrder::getTypeColor($type);
        return "<span class='badge' style='background:$color;color:#fff;'>" . htmlspecialchars(StockOrder::getTypeLabel($type)) . "</span>";
    }

    public static function formatLocations(int $type, int $from, int $to): string
    {
        switch ($type) {
            case StockOrder::TYPE_IN:
            case StockOrder::TYPE_ADJUST:
                return Stock::getLocationName($to);
            case StockOrder::TYPE_OUT:
                return Stock::getLocationName($from);
            case StockOrder::TYPE_DISPOSAL:
                return $from > 0 ? Stock::getLocationName($from) : __('Parc (hors stock)', 'dsimanager');
            default:
                return Stock::getLocationName($from) . ' → ' . Stock::getLocationName($to);
        }
    }
}
