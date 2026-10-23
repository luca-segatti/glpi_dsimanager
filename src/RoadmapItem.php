<?php

namespace GlpiPlugin\Dsimanager;

use CommonDBTM;

/**
 * Module D - Roadmap. Contrairement aux autres itemtypes du plugin,
 * RoadmapItem n'utilise pas Search::show() : la page de gestion est une
 * grille hebdomadaire entièrement personnalisée (front/roadmap.php), pas
 * une liste GLPI classique. On garde quand même un itemtype standard pour
 * bénéficier du système de droits et de l'historique GLPI habituels.
 */
class RoadmapItem extends CommonDBTM
{
    public $dohistory = true;

    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_ROADMAP;

    public static function getTypeName($nb = 0)
    {
        return _n('Ligne Roadmap', 'Lignes Roadmap', $nb, 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-timeline';
    }

    /**
     * Utilisateurs sélectionnables comme "Ressource" : uniquement l'équipe
     * Roadmap (table glpi_plugin_dsimanager_team), pas tous les
     * utilisateurs GLPI - demande explicite, la liste complète n'a pas de
     * sens ici.
     */
    public static function getTeamUsersArray(): array
    {
        global $DB;
        $team = iterator_to_array($DB->request([
            'SELECT' => ['glpi_users.id AS users_id', 'glpi_users.firstname', 'glpi_users.realname', 'glpi_users.name AS login'],
            'FROM'   => 'glpi_plugin_dsimanager_team',
            'INNER JOIN' => [
                'glpi_users' => [
                    'FKEY' => [
                        'glpi_plugin_dsimanager_team' => 'users_id',
                        'glpi_users'                 => 'id',
                    ],
                ],
            ],
        ]));
        $out = [];
        foreach ($team as $member) {
            $out[$member['users_id']] = trim($member['firstname'] . ' ' . $member['realname']) ?: $member['login'];
        }
        return $out;
    }

    /**
     * Initiales de l'équipe (gérées depuis front/roadmap_team.php), par
     * users_id. Vide si non renseigné pour cette personne.
     */
    public static function getTeamInitialsArray(): array
    {
        global $DB;
        $rows = iterator_to_array($DB->request([
            'FROM' => 'glpi_plugin_dsimanager_team',
        ]));
        $out = [];
        foreach ($rows as $row) {
            $out[$row['users_id']] = $row['initials'] ?? '';
        }
        return $out;
    }

    /**
     * Ressources assignées à cette ligne (many-to-many, même principe que
     * les techniciens du Module A - voir Todo::getAssignedUsers()).
     */
    public function getAssignedUsers(): array
    {
        global $DB;
        $result = $DB->request([
            'FROM'  => 'glpi_plugin_dsimanager_roadmapitems_users',
            'WHERE' => ['plugin_dsimanager_roadmapitems_id' => $this->getID()],
        ]);
        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row['users_id'];
        }
        return $ids;
    }

    /**
     * Libellé compact des ressources assignées pour l'affichage en grille :
     * initiales séparées par une virgule si renseignées, sinon le nom
     * complet tronqué.
     */
    public function getResourcesLabel(): string
    {
        $initials = self::getTeamInitialsArray();
        $labels = [];
        foreach ($this->getAssignedUsers() as $users_id) {
            if (!empty($initials[$users_id])) {
                $labels[] = $initials[$users_id];
            } else {
                $labels[] = getUserName($users_id);
            }
        }
        return implode(', ', $labels);
    }

    public function post_addItem()
    {
        $this->updateAssignedUsers();
    }

    public function post_updateItem($history = true)
    {
        $this->updateAssignedUsers();
        $this->handleArchiveTransition();
    }

    /**
     * Détecte un changement d'état d'archivage (via $this->oldvalues,
     * renseigné par GLPI pendant update() pour les besoins de
     * l'historique natif) et horodate + journalise le changement.
     */
    private function handleArchiveTransition(): void
    {
        if (!isset($this->oldvalues['is_archived'])) {
            return;
        }

        global $DB;
        $now       = date('Y-m-d H:i:s');
        $users_id  = \Session::getLoginUserID();
        $new_value = (int) $this->fields['is_archived'];

        if ($new_value === 1) {
            $DB->update(self::getTable(), [
                'date_archived' => $now,
                'archived_by'   => $users_id,
            ], ['id' => $this->getID()]);
            $DB->insert('glpi_plugin_dsimanager_roadmaparchivelogs', [
                'plugin_dsimanager_roadmapitems_id' => $this->getID(),
                'action'                            => 'archive',
                'users_id'                          => $users_id,
                'date'                              => $now,
            ]);
        } else {
            $DB->update(self::getTable(), [
                'date_restored' => $now,
                'restored_by'   => $users_id,
            ], ['id' => $this->getID()]);
            $DB->insert('glpi_plugin_dsimanager_roadmaparchivelogs', [
                'plugin_dsimanager_roadmapitems_id' => $this->getID(),
                'action'                            => 'restore',
                'users_id'                          => $users_id,
                'date'                              => $now,
            ]);
        }
    }

    private function updateAssignedUsers(): void
    {
        global $DB;

        if (!isset($_POST['_users_id']) && !isset($this->input['_users_id'])) {
            return;
        }
        $users_ids = $_POST['_users_id'] ?? $this->input['_users_id'] ?? [];
        if (!is_array($users_ids)) {
            $users_ids = [$users_ids];
        }

        $DB->delete('glpi_plugin_dsimanager_roadmapitems_users', [
            'plugin_dsimanager_roadmapitems_id' => $this->getID(),
        ]);

        foreach ($users_ids as $users_id) {
            $users_id = (int) $users_id;
            if ($users_id > 0) {
                $DB->insert('glpi_plugin_dsimanager_roadmapitems_users', [
                    'plugin_dsimanager_roadmapitems_id' => $this->getID(),
                    'users_id'                          => $users_id,
                ]);
            }
        }
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        global $DB;
        $types      = iterator_to_array($DB->request(['FROM' => RoadmapType::getTable()]));
        $priorities = iterator_to_array($DB->request(['FROM' => RoadmapPriority::getTable()]));
        $statuses   = iterator_to_array($DB->request(['FROM' => RoadmapStatus::getTable()]));
        $team_users = self::getTeamUsersArray();

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Type', 'dsimanager') . "</td>";
        echo "<td>";
        \Dropdown::showFromArray('plugin_dsimanager_roadmaptypes_id', array_column($types, 'name', 'id'), [
            'value'                => $this->fields['plugin_dsimanager_roadmaptypes_id'],
            'display_emptychoice'  => true,
        ]);
        echo "</td>";
        echo "<td>" . __('Priorité', 'dsimanager') . "</td>";
        echo "<td>";
        \Dropdown::showFromArray('plugin_dsimanager_roadmappriorities_id', array_column($priorities, 'name', 'id'), [
            'value'                => $this->fields['plugin_dsimanager_roadmappriorities_id'],
            'display_emptychoice'  => true,
        ]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Ressource(s)', 'dsimanager') . "</td>";
        echo "<td>";
        if (count($team_users)) {
            // Select multiple HTML natif plutôt que le widget JS de GLPI -
            // même correction que Todo::showForm(), après deux tentatives
            // infructueuses avec Dropdown::show(...)/showFromArray(...,
            // 'multiple' => true) qui réinitialisaient la présélection à
            // l'ouverture. Liste déjà restreinte à l'équipe, donc pas
            // besoin de recherche-en-tapant ici de toute façon.
            $assigned = $this->isNewID($ID) ? [] : $this->getAssignedUsers();
            echo "<select name='_users_id[]' multiple size='5' style='width:100%;'>";
            foreach ($team_users as $uid => $label) {
                $sel = in_array($uid, $assigned) ? 'selected' : '';
                echo "<option value='{$uid}' {$sel}>" . htmlspecialchars($label) . "</option>";
            }
            echo "</select>";
        } else {
            echo "<span class='text-muted'>" . __('Aucun membre dans l\'équipe -', 'dsimanager') . " ";
            echo "<a href='" . \Plugin::getWebDir('dsimanager') . "/front/roadmap_team.php'>" . __('en ajouter', 'dsimanager') . "</a></span>";
        }
        echo "</td>";
        echo "<td>" . __('Statut', 'dsimanager') . "</td>";
        echo "<td>";
        \Dropdown::showFromArray('plugin_dsimanager_roadmapstatuses_id', array_column($statuses, 'name', 'id'), [
            'value'                => $this->fields['plugin_dsimanager_roadmapstatuses_id'],
            'display_emptychoice'  => true,
        ]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Budget', 'dsimanager') . "</td>";
        echo "<td>";
        $budget_value = $this->fields['budget'] !== null ? htmlspecialchars((string) $this->fields['budget']) : '';
        echo "<input type='number' step='0.01' name='budget' value='{$budget_value}' required>";
        echo "</td>";
        echo "<td colspan='2'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Description', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        echo "<input type='text' name='description' value=\"" . htmlspecialchars($this->fields['description'] ?? '') . "\" style='width:100%;'>";
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Détails', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        \Html::textarea([
            'name'  => 'details',
            'value' => $this->fields['details'] ?? '',
            'rows'  => 6,
        ]);
        echo "</td>";
        echo "</tr>";

        if (!$this->isNewID($ID)) {
            echo "<tr class='tab_bg_1'>";
            echo "<td>" . __('Archivage', 'dsimanager') . "</td>";
            echo "<td colspan='3'>";
            echo "<label><input type='hidden' name='is_archived' value='0'>";
            echo "<input type='checkbox' name='is_archived' value='1'"
               . ($this->fields['is_archived'] ? " checked" : "") . "> "
               . __('Archiver ce projet (masqué de la grille, restaurable depuis "Projets archivés")', 'dsimanager')
               . "</label>";
            echo "</td>";
            echo "</tr>";
        }

        $this->showFormButtons($options);

        if (!$this->isNewID($ID)) {
            echo "<div class='firstbloc' style='margin-top:15px;'>";
            echo "<h3>" . __('Remplissage rapide de la Roadmap', 'dsimanager') . "</h3>";
            echo "<p class='text-muted'>" . __('Remplit toutes les semaines de la plage choisie avec la catégorie sélectionnée, sans avoir à ouvrir la grille.', 'dsimanager') . "</p>";
            echo "<form method='post' action='" . \Plugin::getWebDir('dsimanager') . "/front/roadmap_save.php'>";
            echo "<input type='hidden' name='bulk_fill_project_id' value='" . (int) $ID . "'>";
            echo "<label>" . __('Catégorie', 'dsimanager') . " ";
            $bulk_categories = iterator_to_array($DB->request(['FROM' => RoadmapWeekCategory::getTable(), 'ORDER' => ['ranking ASC', 'name ASC']]));
            \Dropdown::showFromArray('bulk_fill_category_id', array_column($bulk_categories, 'name', 'id'), [
                'display_emptychoice' => true,
                'emptylabel'          => __('(vide)', 'dsimanager'),
            ]);
            echo "</label> ";
            echo "<label>" . __('Du', 'dsimanager') . " <input type='date' name='bulk_fill_date_from' required></label> ";
            echo "<label>" . __('Au', 'dsimanager') . " <input type='date' name='bulk_fill_date_to' required></label> ";
            echo "<button type='submit' name='bulk_fill_apply' class='btn btn-primary btn-sm'>" . __('Appliquer', 'dsimanager') . "</button>";
            \Html::closeForm();
            echo "</div>";

            echo "<div class='firstbloc' style='margin-top:15px;'>";

            if ($this->fields['is_archived'] && $this->fields['date_archived']) {
                echo "<p class='text-muted'>" . sprintf(
                    __('Archivé le %s par %s', 'dsimanager'),
                    \Html::convDateTime($this->fields['date_archived']),
                    $this->fields['archived_by'] ? getUserName($this->fields['archived_by']) : '?'
                ) . "</p>";
            }

            $log = $this->getArchiveLog();
            if (count($log)) {
                echo "<p><strong>" . __('Historique archivage / restauration', 'dsimanager') . "</strong></p>";
                echo "<ul>";
                foreach ($log as $entry) {
                    $action_label = $entry['action'] === 'archive' ? __('Archivé', 'dsimanager') : __('Restauré', 'dsimanager');
                    echo "<li>{$action_label} " . __('le', 'dsimanager') . " " . \Html::convDateTime($entry['date'])
                       . " " . __('par', 'dsimanager') . " " . htmlspecialchars(getUserName($entry['users_id'])) . "</li>";
                }
                echo "</ul>";
            }
            echo "</div>";

            echo "<p class='text-muted' style='margin-top:10px;'>";
            echo "<a href='" . \Plugin::getWebDir('dsimanager') . "/front/roadmap.php'>" . __('← Retour à la grille Roadmap', 'dsimanager') . "</a>";
            echo " " . __('(les cases hebdomadaires peuvent aussi se remplir directement depuis la grille)', 'dsimanager');
            echo "</p>";
        }

        return true;
    }

    /**
     * Historique des archivages/restaurations de ce projet (qui, quand),
     * du plus récent au plus ancien.
     */
    public function getArchiveLog(): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => 'glpi_plugin_dsimanager_roadmaparchivelogs',
            'WHERE' => ['plugin_dsimanager_roadmapitems_id' => $this->getID()],
            'ORDER' => ['date DESC'],
        ]));
    }

    /**
     * Appelée directement par GLPI pour construire l'entrée de sous-menu
     * de ce module (voir Todo::getMenuContent() pour le mécanisme complet).
     * Pointe vers la grille personnalisée plutôt que vers une recherche
     * standard.
     */
    public static function getMenuContent()
    {
        $menu = [];

        if (static::canView()) {
            $menu['title'] = __('Roadmap', 'dsimanager');
            $menu['page']  = '/plugins/dsimanager/front/roadmap.php';
            $menu['icon']  = self::getIcon();
            $menu['links'] = [
                'search' => '/plugins/dsimanager/front/roadmap.php',
            ];
            if (static::canCreate()) {
                $menu['links']['add'] = '/plugins/dsimanager/front/roadmapitem.form.php';
            }
        }

        return $menu;
    }
}
