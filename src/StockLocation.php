<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;
use Html;

/**
 * Module Stock - Emplacement de stockage (Bureau IT, Stock Archives...).
 * Ajout d'un emplacement = simple ajout dans cette liste, sans code.
 * Chaque emplacement est rattaché à une Localisation GLPI native, inscrite
 * sur les actifs qui y entrent.
 */
class StockLocation extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dsimanager_stocklocations';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Emplacement de stock', 'Emplacements de stock', $nb, 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-building-warehouse';
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Nom', 'dsimanager') . "</td>";
        echo "<td>" . Html::input('name', ['value' => $this->fields['name'], 'required' => true]) . "</td>";
        echo "<td>" . __('Localisation GLPI associée', 'dsimanager') . "</td>";
        echo "<td>";
        \Location::dropdown([
            'name'   => 'locations_id',
            'value'  => $this->fields['locations_id'] ?? 0,
            'entity' => $this->fields['entities_id'] ?? 0,
        ]);
        echo "<p class='text-muted' style='margin:0;'>" . __('Inscrite sur les actifs qui entrent dans cet emplacement.', 'dsimanager') . "</p>";
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Actif', 'dsimanager') . "</td>";
        echo "<td>";
        echo "<input type='hidden' name='is_active' value='0'>";
        echo "<input type='checkbox' name='is_active' value='1'" . (($this->isNewID($ID) || $this->fields['is_active']) ? ' checked' : '') . ">";
        echo " <span class='text-muted'>" . __('Décoché : n\'est plus proposé pour de nouvelles commandes (l\'historique est conservé).', 'dsimanager') . "</span>";
        echo "</td>";
        echo "<td>" . __('Ordre d\'affichage', 'dsimanager') . "</td>";
        echo "<td><input type='number' name='ranking' value='" . (int) ($this->fields['ranking'] ?? 0) . "'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Commentaire', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        Html::textarea(['name' => 'comment', 'value' => $this->fields['comment'], 'rows' => 3]);
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons($options);
        return true;
    }

    /**
     * Un emplacement qui contient encore du stock ou qui apparaît dans
     * l'historique ne peut pas être supprimé (désactivation à la place).
     */
    public function pre_deleteItem()
    {
        global $DB;
        if (!parent::pre_deleteItem()) {
            return false;
        }
        $id = (int) $this->getID();
        $used = $DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_plugin_dsimanager_stockunits', 'WHERE' => ['plugin_dsimanager_stocklocations_id' => $id]])->current()['cpt']
              + $DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_plugin_dsimanager_stocklevels', 'WHERE' => ['plugin_dsimanager_stocklocations_id' => $id, 'quantity' => ['>', 0]]])->current()['cpt']
              + $DB->request(['COUNT' => 'cpt', 'FROM' => 'glpi_plugin_dsimanager_stockorders', 'WHERE' => ['OR' => ['stocklocations_id_from' => $id, 'stocklocations_id_to' => $id]]])->current()['cpt'];
        if ($used > 0) {
            \Session::addMessageAfterRedirect(
                __('Emplacement utilisé (stock ou historique) : il ne peut pas être supprimé. Décochez « Actif » pour le masquer.', 'dsimanager'),
                false,
                ERROR
            );
            return false;
        }
        return true;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();
        $tab[] = [
            'id'       => 900,
            'table'    => 'glpi_locations',
            'field'    => 'completename',
            'name'     => __('Localisation GLPI associée', 'dsimanager'),
            'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id'       => 901,
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Actif', 'dsimanager'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 902,
            'table'    => $this->getTable(),
            'field'    => 'ranking',
            'name'     => __('Ordre d\'affichage', 'dsimanager'),
            'datatype' => 'number',
        ];
        return $tab;
    }
}
