<?php

namespace GlpiPlugin\Dsimanager;

use CommonDropdown;

/**
 * Dropdown "Catégorie de tâche" (ex: Nouvel utilisateur, Maintenance...).
 * Gérable depuis Configuration > Listes déroulantes une fois le plugin actif,
 * ou directement depuis le formulaire de tâche (bouton "+").
 */
class TodoCategory extends CommonDropdown
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_TODO;

    public static function getTypeName($nb = 0)
    {
        return _n('Catégorie de tâche', 'Catégories de tâche', $nb, 'dsimanager');
    }
}
