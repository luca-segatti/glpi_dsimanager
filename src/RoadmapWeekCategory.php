<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;
use Html;

/**
 * Dropdown "Catégorie de semaine" : c'est ce qui remplit chaque case de la
 * grille Roadmap (Module D), avec une couleur pour le repérage visuel.
 * Formulaire personnalisé (au lieu du formulaire générique de
 * CommonDropdown) pour ajouter le champ couleur.
 */
class RoadmapWeekCategory extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_ROADMAP;

    public static function getTypeName($nb = 0)
    {
        return _n('Catégorie de semaine (Roadmap)', 'Catégories de semaine (Roadmap)', $nb, 'dsimanager');
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Nom', 'dsimanager') . "</td>";
        echo "<td>";
        echo Html::input('name', ['value' => $this->fields['name']]);
        echo "</td>";
        echo "<td>" . __('Couleur', 'dsimanager') . "</td>";
        echo "<td>";
        echo "<input type='color' name='color' value=\"" . htmlspecialchars($this->fields['color'] ?: '#6c757d') . "\">";
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Ordre d\'affichage', 'dsimanager') . "</td>";
        echo "<td>";
        echo "<input type='number' name='ranking' value='" . (int) ($this->fields['ranking'] ?? 0) . "'>";
        echo "<p class='text-muted' style='margin:0;'>" . __('Les catégories s\'affichent triées par cet ordre croissant (pas par date de création). Espacer les valeurs de 10 en 10 facilite l\'insertion d\'une nouvelle catégorie entre deux existantes.', 'dsimanager') . "</p>";
        echo "</td>";
        echo "<td colspan='2'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Commentaire', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        Html::textarea([
            'name'  => 'comment',
            'value' => $this->fields['comment'],
            'rows'  => 3,
        ]);
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons($options);
        return true;
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'       => 900,
            'table'    => $this->getTable(),
            'field'    => 'ranking',
            'name'     => __('Ordre d\'affichage', 'dsimanager'),
            'datatype' => 'number',
        ];

        return $tab;
    }
}
