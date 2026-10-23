<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;

/**
 * Dropdown "Service demandeur" pour les tâches (Module A).
 * Gérable directement depuis le formulaire de tâche via les icônes
 * "i" (commentaire) et "+" (ajout rapide) à côté du champ - pas besoin
 * d'une page de gestion séparée pour ce cas d'usage.
 */
class TodoRequestingService extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_TODO;

    public static function getTypeName($nb = 0)
    {
        return _n('Service demandeur', 'Services demandeurs', $nb, 'dsimanager');
    }
}
