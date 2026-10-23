<?php

namespace GlpiPlugin\Dsimanager;

use CommonDBTM;
use Session;
use Html;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;

/**
 * Module A - Gestion des tâches (TODO)
 */
class Todo extends CommonDBTM
{
    /**
     * L'onglet natif Item_Ticket a été retiré : il faisait doublon avec
     * l'onglet "Tickets liés" maison (TodoTicket) et n'apportait rien
     * d'utilisable en pratique (pas de formulaire d'ajout côté élément,
     * voir README section Dette technique). Log::class est repris
     * explicitement car l'historique n'est pas ajouté automatiquement dès
     * que defineTabs() est surchargé.
     */
    public function defineTabs($options = [])
    {
        $ong = [];
        $this->addDefaultFormTab($ong)
             ->addStandardTab(\Log::class, $ong, $options);
        return $ong;
    }

    // Active l'historique automatique des modifications de champs (glpi_logs).
    public $dohistory = true;

    // Gère les entités si tu utilises plusieurs entités dans ton GLPI.
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_TODO;

    public const STATUS_TODO       = 1;
    public const STATUS_INPROGRESS = 2;
    public const STATUS_WAITING    = 3;
    public const STATUS_PARTIAL    = 5;
    public const STATUS_DONE       = 4;
    public const STATUS_CANCELLED  = 6;

    public static function getTypeName($nb = 0)
    {
        return _n('Tâche', 'Tâches', $nb, 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    /**
     * Appelée directement par GLPI (Html::generateMenuSession(), via le hook
     * Glpi\Plugin\Hooks::MENU_TOADD déclaré dans setup.php) pour construire
     * l'entrée de sous-menu correspondant à ce module.
     *
     * Le titre de la catégorie parente ("Gestion DSI") est fixé séparément
     * via le hook Glpi\Plugin\Hooks::REDEFINE_MENUS (voir hook.php), qui
     * s'exécute APRÈS generateMenuSession() - confirmé en lisant
     * Html::header() sur ce GLPI - et écrase spécifiquement ce champ sans
     * toucher au titre de cette entrée de sous-menu.
     */
    public static function getMenuContent()
    {
        $menu = [];

        if (static::canView()) {
            $menu['title'] = __('TODO', 'dsimanager');
            $menu['page']  = '/plugins/dsimanager/front/todo.php';
            $menu['icon']  = self::getIcon();
            $menu['links'] = [
                'search' => '/plugins/dsimanager/front/todo.php',
            ];
            if (static::canCreate()) {
                $menu['links']['add'] = '/plugins/dsimanager/front/todo.form.php';
            }
        }

        return $menu;
    }

    /**
     * Libellés des statuts - centralisés ici pour être réutilisés
     * dans le formulaire, la recherche et l'affichage liste.
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_TODO       => __('À faire', 'dsimanager'),
            self::STATUS_INPROGRESS => __('En cours', 'dsimanager'),
            self::STATUS_WAITING    => __('En attente', 'dsimanager'),
            self::STATUS_PARTIAL    => __('Partiel', 'dsimanager'),
            self::STATUS_DONE       => __('Terminé', 'dsimanager'),
            self::STATUS_CANCELLED  => __('Annulé', 'dsimanager'),
        ];
    }

    public static function getStatusName(int $status): string
    {
        $statuses = self::getStatuses();
        return $statuses[$status] ?? '';
    }

    /**
     * Colonnes disponibles dans le moteur de recherche natif GLPI
     * (tri, filtre avancé, export CSV... tout est gratuit ici).
     */
    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(),
        ];

        $tab[] = [
            'id'            => 1,
            'table'         => $this->getTable(),
            'field'         => 'name',
            'name'          => __('Titre', 'dsimanager'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID', 'dsimanager'),
            'datatype' => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'description',
            'name'     => __('Description', 'dsimanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Statut', 'dsimanager'),
            'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => 'glpi_plugin_dsimanager_todocategories',
            'field'    => 'name',
            'name'     => __('Catégorie', 'dsimanager'),
            'datatype' => 'dropdown', // 'dropdown' : liste dans Actions > Modifier (correctif 2.4.0)
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => 'glpi_plugin_dsimanager_todorequestingservices',
            'field'    => 'name',
            'name'     => __('Service demandeur', 'dsimanager'),
            'datatype' => 'dropdown', // 'dropdown' : liste dans Actions > Modifier (correctif 2.4.0)
        ];

        $tab[] = [
            'id'            => 6,
            'table'         => 'glpi_users',
            'field'         => 'name',
            'linkfield'     => 'users_id',
            'name'          => __('Technicien(s) assigné(s)', 'dsimanager'),
            'datatype'      => 'dropdown',
            'right'         => 'all',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'joinparams'    => [
                'beforejoin' => [
                    'table'      => 'glpi_plugin_dsimanager_todos_users',
                    'joinparams' => [
                        'jointype' => 'child',
                    ],
                ],
            ],
        ];

        $tab[] = [
            'id'       => 19,
            'table'    => $this->getTable(),
            'field'    => 'date_mod',
            'name'     => __('Dernière modification', 'dsimanager'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => 20,
            'table'    => $this->getTable(),
            'field'    => 'date_status_change',
            'name'     => __('Dernier changement de statut', 'dsimanager'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => 121,
            'table'    => $this->getTable(),
            'field'    => 'date_creation',
            'name'     => __('Date de création', 'dsimanager'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        return $tab;
    }

    /**
     * Rendu spécifique de la colonne "status" dans les résultats de recherche
     * (affiche le libellé au lieu de l'entier stocké en base).
     */
    /**
     * Couleurs de fond par statut, utilisées uniquement pour l'affichage
     * dans la liste (pas de code couleur pour les statuts non listés ici :
     * "À faire" et "Partiel" restent affichés sans couleur particulière).
     */
    public static function getStatusColor(int $status): ?string
    {
        return [
            self::STATUS_DONE      => '#2fb344', // vert
            self::STATUS_INPROGRESS => '#f1c40f', // jaune
            self::STATUS_WAITING   => '#f76707', // orange
            self::STATUS_CANCELLED => '#d63939', // rouge
        ][$status] ?? null;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'status':
                $status = (int) $values['status'];
                $label  = self::getStatusName($status);
                $color  = self::getStatusColor($status);
                if ($color === null) {
                    return $label;
                }
                $text_color = ($status === self::STATUS_INPROGRESS) ? '#000' : '#fff';
                return "<span style='background-color: {$color}; color: {$text_color}; padding: 2px 8px; "
                     . "border-radius: 4px; display: inline-block;'>" . htmlspecialchars($label) . "</span>";
            case 'name':
                // Colonne "Catégorie" (jointure sur glpi_plugin_dsimanager_todocategories).
                // Le champ "Titre" (id=1) utilise datatype 'itemlink', qui ne
                // passe pas par ce switch : pas de collision possible ici.
                return htmlspecialchars($values['name'] ?? '');
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Champ de recherche spécifique pour le filtre avancé sur le statut
     * (affiche un dropdown des statuts plutôt qu'un champ texte libre).
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        switch ($field) {
            case 'status':
                $options['display'] = false;
                return Dropdown::showFromArray($name, self::getStatuses(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Avant sauvegarde : on horodate le changement de statut uniquement
     * quand le statut change réellement (pas à chaque édition de champ).
     */
    public function prepareInputForUpdate($input)
    {
        if (isset($input['status']) && $input['status'] != $this->fields['status']) {
            $input['date_status_change'] = date('Y-m-d H:i:s');
        }
        return $input;
    }

    public function prepareInputForAdd($input)
    {
        $input['date_status_change'] = date('Y-m-d H:i:s');
        return $input;
    }

    /**
     * Sauvegarde les techniciens assignés (relation many-to-many)
     * après la création ou modification de la tâche.
     */
    public function post_addItem()
    {
        $this->updateAssignedUsers();
    }

    public function post_updateItem($history = true)
    {
        $this->updateAssignedUsers();
    }

    private function updateAssignedUsers()
    {
        global $DB;

        if (!isset($_POST['_users_id']) && !isset($this->input['_users_id'])) {
            return;
        }
        $users_ids = $_POST['_users_id'] ?? $this->input['_users_id'] ?? [];
        if (!is_array($users_ids)) {
            $users_ids = [$users_ids];
        }

        $DB->delete('glpi_plugin_dsimanager_todos_users', [
            'plugin_dsimanager_todos_id' => $this->getID(),
        ]);

        foreach ($users_ids as $users_id) {
            $users_id = (int) $users_id;
            if ($users_id > 0) {
                $DB->insert('glpi_plugin_dsimanager_todos_users', [
                    'plugin_dsimanager_todos_id' => $this->getID(),
                    'users_id'                  => $users_id,
                ]);
            }
        }
    }

    public function getAssignedUsers(): array
    {
        global $DB;

        $result = $DB->request([
            'FROM'  => 'glpi_plugin_dsimanager_todos_users',
            'WHERE' => ['plugin_dsimanager_todos_id' => $this->getID()],
        ]);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row['users_id'];
        }
        return $ids;
    }

    /**
     * Formulaire d'édition de la tâche (PHP natif, pas de dépendance Twig
     * additionnelle à enregistrer : plus simple à maintenir pour ce module).
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Titre', 'dsimanager') . "</td>";
        echo "<td>";
        echo Html::input('name', ['value' => $this->fields['name']]);
        echo "</td>";
        echo "<td>" . __('Statut', 'dsimanager') . "</td>";
        echo "<td>";
        Dropdown::showFromArray('status', self::getStatuses(), [
            'value' => $this->fields['status'],
        ]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Catégorie', 'dsimanager') . "</td>";
        echo "<td>";
        Dropdown::show(TodoCategory::class, [
            'name'  => 'plugin_dsimanager_todocategories_id',
            'value' => $this->fields['plugin_dsimanager_todocategories_id'],
        ]);
        echo "</td>";
        echo "<td>" . __('Service demandeur', 'dsimanager') . "</td>";
        echo "<td>";
        Dropdown::show(TodoRequestingService::class, [
            'name'  => 'plugin_dsimanager_todorequestingservices_id',
            'value' => $this->fields['plugin_dsimanager_todorequestingservices_id'],
        ]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Technicien(s) assigné(s)', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        // Select multiple HTML natif plutôt que le widget JS de GLPI :
        // après deux tentatives infructueuses avec Dropdown::show(...,
        // 'multiple' => true) (présélection qui se réinitialisait à
        // l'ouverture), je préfère un mécanisme simple et entièrement
        // sous contrôle plutôt que de continuer à deviner le
        // fonctionnement interne du widget JS de GLPI. Contrepartie :
        // pas de recherche-en-tapant, juste une liste déroulante classique.
        // Liste restreinte à l'équipe Roadmap (demande explicite), pas
        // tous les utilisateurs GLPI.
        $assigned   = $this->isNewID($ID) ? [] : $this->getAssignedUsers();
        $team_users = RoadmapItem::getTeamUsersArray();
        if (count($team_users)) {
            echo "<select name='_users_id[]' multiple size='6' style='width:100%;'>";
            foreach ($team_users as $uid => $label) {
                $sel = in_array($uid, $assigned) ? 'selected' : '';
                echo "<option value='{$uid}' {$sel}>" . htmlspecialchars($label) . "</option>";
            }
            echo "</select>";
        } else {
            echo "<span class='text-muted'>" . __('Aucun membre dans l\'équipe Roadmap -', 'dsimanager') . " ";
            echo "<a href='" . \Plugin::getWebDir('dsimanager') . "/front/roadmap_team.php'>" . __('en ajouter', 'dsimanager') . "</a></span>";
        }
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Description', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        Html::textarea([
            'name'  => 'description',
            'value' => $this->fields['description'],
            'rows'  => 6,
        ]);
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons($options);
        return true;
    }
}
