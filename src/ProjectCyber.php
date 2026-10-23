<?php

namespace GlpiPlugin\Dsimanager;

use CommonDBTM;
use Html;
use Dropdown;

/**
 * Module C - Projets Cybersécurité (même structure que le Module B, avec deux champs supplémentaires : Impacts/Risques et Résultats attendus/obtenus).
 *
 * Le pourcentage d'avancement global (progress_percent) et les compteurs
 * d'étapes (steps_total / steps_done) sont dénormalisés directement sur
 * cette table plutôt que calculés à la volée dans le moteur de recherche :
 * plus simple et plus fiable qu'un champ virtuel, au prix d'un recalcul
 * (voir ProjectCyberStep::recomputeParentProgress()) à chaque modification
 * d'étape. Le moteur de recherche GLPI n'a ainsi qu'une colonne normale à
 * lire, triable/filtrable nativement.
 */
class ProjectCyber extends CommonDBTM
{
    public $dohistory = true;

    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_CYBER;

    public const STATUS_NOT_STARTED       = 1;
    public const STATUS_WAITING_VALIDATION = 2;
    public const STATUS_BLOCKED           = 3;
    public const STATUS_DONE              = 4;

    public static function getStatuses(): array
    {
        return [
            self::STATUS_NOT_STARTED        => __('Pas commencé', 'dsimanager'),
            self::STATUS_WAITING_VALIDATION => __('Attente validation', 'dsimanager'),
            self::STATUS_BLOCKED            => __('Bloqué', 'dsimanager'),
            self::STATUS_DONE               => __('Terminé', 'dsimanager'),
        ];
    }

    public static function getStatusName(int $status): string
    {
        return self::getStatuses()[$status] ?? '';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Projet Cybersécurité', 'Projets Cybersécurité', $nb, 'dsimanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-lock';
    }

    /**
     * Appelée directement par GLPI pour construire l'entrée de sous-menu
     * de ce module (voir Todo::getMenuContent() pour le mécanisme complet,
     * et hook.php pour le titre de la catégorie parente).
     */
    public static function getMenuContent()
    {
        $menu = [];

        if (static::canView()) {
            $menu['title'] = __('Projets Cybersécurité', 'dsimanager');
            $menu['page']  = '/plugins/dsimanager/front/projectcyber.php';
            $menu['icon']  = self::getIcon();
            $menu['links'] = [
                'search' => '/plugins/dsimanager/front/projectcyber.php',
            ];
            if (static::canCreate()) {
                $menu['links']['add'] = '/plugins/dsimanager/front/projectcyber.form.php';
            }
        }

        return $menu;
    }

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
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Technicien', 'dsimanager'),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'deadline',
            'name'     => __('Date limite', 'dsimanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'         => 6,
            'table'      => $this->getTable(),
            'field'      => 'status',
            'name'       => __('Statut', 'dsimanager'),
            'datatype'   => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];

        $tab[] = [
            'id'       => 9,
            'table'    => 'glpi_plugin_dsimanager_roadmappriorities',
            'field'    => 'name',
            'name'     => __('Priorité', 'dsimanager'),
            'itemtype' => RoadmapPriority::class,
            'datatype' => 'specific',
        ];

        // Colonne d'avancement affichée directement dans la vue Liste,
        // sans avoir à ouvrir la fiche détaillée du projet (exigence du
        // cahier des charges pour ce module).
        $tab[] = [
            'id'              => 5,
            'table'           => $this->getTable(),
            'field'           => 'progress_percent',
            'name'            => __('Avancement', 'dsimanager'),
            'datatype'        => 'specific',
            'massiveaction'   => false,
            'additionalfields' => ['steps_total', 'steps_done'],
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
            'id'       => 121,
            'table'    => $this->getTable(),
            'field'    => 'date_creation',
            'name'     => __('Date de création', 'dsimanager'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'impacts_risks',
            'name'     => __('Impacts / Risques', 'dsimanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'expected_results',
            'name'     => __('Résultats attendus / obtenus', 'dsimanager'),
            'datatype' => 'text',
        ];

        return $tab;
    }

    /**
     * Rendu de la colonne "Avancement" dans la liste : une mini barre de
     * progression + le détail "X/Y étapes", visible sans ouvrir la fiche.
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'status':
                return self::getStatusName((int) $values['status']);
            case 'name':
                // Colonne "Priorité" (jointure sur glpi_plugin_dsimanager_roadmappriorities).
                // Le champ "Titre" (id=1) utilise datatype 'itemlink', qui ne
                // passe pas par ce switch : pas de collision possible ici.
                return htmlspecialchars($values['name'] ?? '');
            case 'progress_percent':
                $percent     = (int) ($values['progress_percent'] ?? 0);
                $steps_total = (int) ($values['steps_total'] ?? 0);
                $steps_done  = (int) ($values['steps_done'] ?? 0);

                if ($steps_total === 0) {
                    return "<span class='text-muted'>" . __('Aucune étape', 'dsimanager') . "</span>";
                }

                return "<div style='min-width: 120px;'>"
                     . "<div style='background:#e9ecef; border-radius:4px; height:14px; overflow:hidden;'>"
                     . "<div style='background:#2fb344; width:{$percent}%; height:100%;'></div>"
                     . "</div>"
                     . "<small>{$steps_done}/{$steps_total} " . __('étapes', 'dsimanager') . " — {$percent}%</small>"
                     . "</div>";
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        switch ($field) {
            case 'status':
                $options['display'] = false;
                return Dropdown::showFromArray($name, self::getStatuses(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Titre', 'dsimanager') . "</td>";
        echo "<td>";
        echo Html::input('name', ['value' => $this->fields['name']]);
        echo "</td>";
        echo "<td>" . __('Technicien', 'dsimanager') . "</td>";
        echo "<td>";
        // Restreint à l'équipe Roadmap (demande explicite), pas tous les
        // utilisateurs GLPI.
        $team_users = RoadmapItem::getTeamUsersArray();
        if (count($team_users)) {
            Dropdown::showFromArray('users_id', $team_users, [
                'value'                => $this->fields['users_id'],
                'display_emptychoice'  => true,
            ]);
        } else {
            echo "<span class='text-muted'>" . __('Aucun membre dans l\'équipe Roadmap -', 'dsimanager') . " ";
            echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_team.php'>" . __('en ajouter', 'dsimanager') . "</a></span>";
        }
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Date limite', 'dsimanager') . "</td>";
        echo "<td>";
        Html::showDateField('deadline', ['value' => $this->fields['deadline']]);
        echo "</td>";
        echo "<td>" . __('Statut', 'dsimanager') . "</td>";
        echo "<td>";
        Dropdown::showFromArray('status', self::getStatuses(), [
            'value' => $this->fields['status'],
        ]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Priorité', 'dsimanager') . "</td>";
        echo "<td>";
        global $DB;
        $priorities = iterator_to_array($DB->request(['FROM' => 'glpi_plugin_dsimanager_roadmappriorities']));
        Dropdown::showFromArray('plugin_dsimanager_roadmappriorities_id', array_column($priorities, 'name', 'id'), [
            'value'               => $this->fields['plugin_dsimanager_roadmappriorities_id'],
            'display_emptychoice' => true,
        ]);
        echo "</td>";
        echo "<td colspan='2'></td>";
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

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Impacts / Risques', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        Html::textarea([
            'name'  => 'impacts_risks',
            'value' => $this->fields['impacts_risks'],
            'rows'  => 4,
        ]);
        echo "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Résultats attendus / obtenus', 'dsimanager') . "</td>";
        echo "<td colspan='3'>";
        Html::textarea([
            'name'  => 'expected_results',
            'value' => $this->fields['expected_results'],
            'rows'  => 4,
        ]);
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons($options);

        if (!$this->isNewID($ID)) {
            echo "<p class='text-muted' style='margin-top: 10px;'>"
               . __('Les étapes du projet se gèrent dans l\'onglet ci-dessus, une fois le projet enregistré.', 'dsimanager')
               . "</p>";
        }

        return true;
    }
}
