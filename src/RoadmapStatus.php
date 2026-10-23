<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;

/**
 * Dropdown "Statut" pour les lignes de la Roadmap (Module D).
 */
class RoadmapStatus extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_ROADMAP;

    /**
     * Fixé explicitement par précaution : la pluralisation anglaise de
     * "Status" en "Statuses" est un cas moins immédiat que les pluriels
     * réguliers déjà validés ailleurs dans ce plugin (ex. Category ->
     * Categories) - après le bug rencontré sur TodoTicket, je préfère ne
     * pas laisser planer le doute plutôt que de vérifier après coup.
     */
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dsimanager_roadmapstatuses';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Statut de projet (Roadmap)', 'Statuts de projet (Roadmap)', $nb, 'dsimanager');
    }
}
