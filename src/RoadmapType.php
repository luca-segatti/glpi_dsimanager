<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;

/**
 * Dropdown "Type" pour les lignes de la Roadmap (Module D).
 * Gérable depuis Configuration > Listes déroulantes, ou via le bouton "+"
 * dans le formulaire.
 */
class RoadmapType extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_ROADMAP;

    public static function getTypeName($nb = 0)
    {
        return _n('Type de projet', 'Types de projet', $nb, 'dsimanager');
    }
}
