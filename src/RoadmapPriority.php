<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;

/**
 * Dropdown "Priorité" pour les lignes de la Roadmap (Module D).
 */
class RoadmapPriority extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_ROADMAP;

    public static function getTypeName($nb = 0)
    {
        return _n('Priorité', 'Priorités', $nb, 'dsimanager');
    }
}
