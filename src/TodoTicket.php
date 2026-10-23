<?php

namespace GlpiPlugin\Dsimanager;

use CommonDBTM;
use CommonGLPI;
use Ticket;

/**
 * Lien simple "maison" entre une tâche (Todo) et un ou plusieurs tickets,
 * affiché et géré dans un onglet dédié - même technique que l'onglet
 * Étapes du Module B (un seul <form> par tableau, contrôleur dédié pour
 * le traitement).
 *
 * Choisi après vérification que l'intégration native GLPI (Item_Ticket,
 * voir Todo::defineTabs()) ne propose pas de formulaire d'ajout côté
 * élément lié, et que l'onglet "Éléments associés" n'est pas disponible
 * côté ticket sur cette installation (voir README, Dette technique).
 */
class TodoTicket extends CommonDBTM
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_TODO;

    /**
     * Nom de classe sans underscore ("TodoTicket") : la dérivation
     * automatique de GLPI ne produit pas le même nom que la table
     * réellement créée dans hook.php (glpi_plugin_dsimanager_todos_tickets,
     * qui imite la convention native Item_Ticket -> glpi_items_tickets).
     * On fixe donc explicitement le nom de table pour éviter toute
     * divergence - c'est ce décalage qui provoquait une erreur SQL (table
     * introuvable) dès le chargement de la fiche tâche.
     */
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_dsimanager_todos_tickets';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Ticket lié', 'Tickets liés', $nb, 'dsimanager');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === Todo::class) {
            $count = countElementsInTable(self::getTable(), [
                'plugin_dsimanager_todos_id' => $item->getID(),
            ]);
            return self::createTabEntry(self::getTypeName(2), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === Todo::class) {
            self::showForTodo((int) $item->getID());
        }
        return true;
    }

    public static function showForTodo(int $todos_id)
    {
        global $DB;

        $canedit = \Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_TODO, UPDATE);

        $links = $DB->request([
            'SELECT' => ['glpi_plugin_dsimanager_todos_tickets.id', 'glpi_tickets.id AS tickets_id', 'glpi_tickets.name', 'glpi_tickets.status'],
            'FROM'   => 'glpi_plugin_dsimanager_todos_tickets',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'FKEY' => [
                        'glpi_plugin_dsimanager_todos_tickets' => 'tickets_id',
                        'glpi_tickets'                        => 'id',
                    ],
                ],
            ],
            'WHERE' => ['glpi_plugin_dsimanager_todos_tickets.plugin_dsimanager_todos_id' => $todos_id],
        ]);

        echo "<div class='firstbloc'>";
        echo "<form method='post' action='" . \Plugin::getWebDir('dsimanager') . "/front/todoticket.form.php'>";
        echo "<input type='hidden' name='plugin_dsimanager_todos_id' value='{$todos_id}'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th>" . __('Ticket', 'dsimanager') . "</th>";
        echo "<th>" . __('Statut', 'dsimanager') . "</th>";
        if ($canedit) {
            echo "<th></th>";
        }
        echo "</tr>";

        foreach ($links as $link) {
            echo "<tr class='tab_bg_1'>";
            echo "<td><a href='/front/ticket.form.php?id=" . (int) $link['tickets_id'] . "'>#"
               . (int) $link['tickets_id'] . " - " . htmlspecialchars($link['name']) . "</a></td>";
            echo "<td>" . htmlspecialchars(Ticket::getStatus((int) $link['status'])) . "</td>";
            if ($canedit) {
                echo "<td><button type='submit' name='unlink_ticket' value='" . (int) $link['id']
                   . "' class='btn btn-sm btn-danger'>" . __('Délier', 'dsimanager') . "</button></td>";
            }
            echo "</tr>";
        }

        if ($canedit) {
            echo "<tr class='tab_bg_2'>";
            echo "<td>";
            \Dropdown::show('Ticket', [
                'name'   => 'new_tickets_id',
                'entity' => $_SESSION['glpiactive_entity'] ?? 0,
            ]);
            echo "</td>";
            echo "<td colspan='2'>";
            echo "<button type='submit' name='link_ticket' class='btn btn-sm btn-success'>" . __('Lier', 'dsimanager') . "</button>";
            echo "</td>";
            echo "</tr>";
        }

        echo "</table>";
        \Html::closeForm();
        echo "</div>";
    }
}
