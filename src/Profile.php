<?php

namespace GlpiPlugin\Dsimanager;

use CommonDBTM;
use CommonGLPI;
use Profile as GlpiProfile;

/**
 * Ajoute un onglet "Gestion DSI" sur la fiche Profil (Administration > Profils)
 * permettant de cocher, profil par profil, l'accès au plugin.
 *
 * Écrit avec des requêtes SQL directes sur glpi_profilerights plutôt que via
 * des méthodes de confort de la classe ProfileRight (comportement garanti,
 * facile à déboguer). Nommage getManagedRights() (et non getRights()) pour
 * ne pas entrer en collision avec la méthode native CommonDBTM::getRights().
 */
class Profile extends CommonDBTM
{
    public static function getManagedRights(): array
    {
        return [
            PLUGIN_DSIMANAGER_RIGHT_TODO => [
                'short' => __('Tâches (TODO)', 'dsimanager'),
                'long'  => __('Gestion des tâches - Module A', 'dsimanager'),
            ],
            PLUGIN_DSIMANAGER_RIGHT_DATA => [
                'short' => __('Projets DATA', 'dsimanager'),
                'long'  => __('Gestion des projets DATA - Module B', 'dsimanager'),
            ],
            PLUGIN_DSIMANAGER_RIGHT_CYBER => [
                'short' => __('Projets Cybersécurité', 'dsimanager'),
                'long'  => __('Gestion des projets Cybersécurité - Module C', 'dsimanager'),
            ],
            PLUGIN_DSIMANAGER_RIGHT_ROADMAP => [
                'short' => __('Roadmap', 'dsimanager'),
                'long'  => __('Gestion de la Roadmap - Module D', 'dsimanager'),
            ],
        ] + self::getStockRights();
    }

    /**
     * Droits du module Stock : contrairement aux modules A à D (une case
     * "accès complet"), chaque droit propose plusieurs niveaux cochables
     * indépendamment ('levels' => [bit => libellé]). La valeur enregistrée
     * est la somme des bits cochés.
     */
    public static function getStockRights(): array
    {
        $read     = [READ => __('Lecture', 'dsimanager')];
        $workflow = [
            READ                            => __('Lecture', 'dsimanager'),
            CREATE | UPDATE                 => __('Préparer (brouillon)', 'dsimanager'),
            PLUGIN_DSIMANAGER_STOCK_VALIDATE => __('Valider', 'dsimanager'),
        ];
        return [
            PLUGIN_DSIMANAGER_RIGHT_STOCK_CONTENT => [
                'short'  => __('Stock - Contenu', 'dsimanager'),
                'long'   => __('Stock - Contenu du stock', 'dsimanager'),
                'levels' => $read,
            ],
            PLUGIN_DSIMANAGER_RIGHT_STOCK_IN => [
                'short'  => __('Stock - Entrées', 'dsimanager'),
                'long'   => __('Stock - Commandes d\'entrée', 'dsimanager'),
                'levels' => $workflow,
            ],
            PLUGIN_DSIMANAGER_RIGHT_STOCK_OUT => [
                'short'  => __('Stock - Sorties', 'dsimanager'),
                'long'   => __('Stock - Commandes de sortie', 'dsimanager'),
                'levels' => $workflow,
            ],
            PLUGIN_DSIMANAGER_RIGHT_STOCK_TRANSFER => [
                'short'  => __('Stock - Transferts', 'dsimanager'),
                'long'   => __('Stock - Transferts et ajustements d\'inventaire', 'dsimanager'),
                'levels' => $workflow,
            ],
            PLUGIN_DSIMANAGER_RIGHT_STOCK_DISPOSAL => [
                'short'  => __('Stock - Suppressions', 'dsimanager'),
                'long'   => __('Stock - Suppressions (sortie définitive du parc, corbeille GLPI)', 'dsimanager'),
                'levels' => $workflow,
            ],
            PLUGIN_DSIMANAGER_RIGHT_STOCK_HISTORY => [
                'short'  => __('Stock - Historique', 'dsimanager'),
                'long'   => __('Stock - Historique des mouvements', 'dsimanager'),
                'levels' => $read,
            ],
            PLUGIN_DSIMANAGER_RIGHT_STOCK_STATS => [
                'short'  => __('Stock - Statistiques', 'dsimanager'),
                'long'   => __('Stock - Statistiques et cartes du tableau de bord', 'dsimanager'),
                'levels' => $read,
            ],
            PLUGIN_DSIMANAGER_RIGHT_STOCK_CONFIG => [
                'short'  => __('Stock - Configuration', 'dsimanager'),
                'long'   => __('Stock - Configuration (emplacements, articles, seuils, réglages)', 'dsimanager'),
                'levels' => [
                    READ                                => __('Lecture', 'dsimanager'),
                    CREATE | UPDATE | DELETE | PURGE    => __('Modification', 'dsimanager'),
                ],
            ],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === GlpiProfile::class) {
            return __('Gestion DSI', 'dsimanager');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === GlpiProfile::class) {
            self::showForProfile((int) $item->getField('id'));
        }
        return true;
    }

    private static function getRightValue(int $profiles_id, string $right_name): int
    {
        global $DB;

        $value = 0;
        $iterator = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => $right_name,
            ],
        ]);
        foreach ($iterator as $row) {
            $value = (int) $row['rights'];
        }
        return $value;
    }

    private static function setRightValue(int $profiles_id, string $right_name, int $value): void
    {
        global $DB;

        $exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_profilerights',
            'WHERE' => [
                'profiles_id' => $profiles_id,
                'name'        => $right_name,
            ],
        ])->current()['cpt'] > 0;

        if ($exists) {
            $DB->update('glpi_profilerights', ['rights' => $value], [
                'profiles_id' => $profiles_id,
                'name'        => $right_name,
            ]);
        } else {
            $DB->insert('glpi_profilerights', [
                'profiles_id' => $profiles_id,
                'name'        => $right_name,
                'rights'      => $value,
            ]);
        }
    }

    public static function showForProfile(int $profiles_id)
    {
        $canedit = \Session::haveRight('profile', UPDATE);

        echo "<div class='firstbloc'>";
        echo "<form method='post' action='" . \Plugin::getWebDir('dsimanager') . "/front/profile_rights.form.php'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . __('Droits Gestion DSI', 'dsimanager') . "</th></tr>";

        foreach (self::getManagedRights() as $right_name => $right_infos) {
            $current_value = self::getRightValue($profiles_id, $right_name);
            $disabled = $canedit ? '' : "disabled='disabled'";

            echo "<tr class='tab_bg_1'>";
            echo "<td>" . $right_infos['long'] . "</td>";
            echo "<td>";
            if (isset($right_infos['levels'])) {
                foreach ($right_infos['levels'] as $bits => $label) {
                    $checked = ($current_value & $bits) === $bits ? "checked='checked'" : '';
                    echo "<label style='margin-right:15px;'>";
                    echo "<input type='checkbox' name='_right_{$right_name}[{$bits}]' value='1' {$checked} {$disabled}>";
                    echo "&nbsp;" . $label . "</label>";
                }
            } else {
                $checked = ($current_value & READ) === READ ? "checked='checked'" : '';
                echo "<input type='checkbox' name='_right_{$right_name}' value='1' {$checked} {$disabled}>";
                echo "&nbsp;" . __('Accès complet au module (lecture, création, modification, suppression)', 'dsimanager');
            }
            echo "</td>";
            echo "</tr>";
        }

        echo "<tr class='tab_bg_2'>";
        echo "<td colspan='2' class='center'>";
        echo "<input type='hidden' name='id' value='{$profiles_id}'>";
        echo "<input type='hidden' name='profiles_id' value='{$profiles_id}'>";
        echo "<input type='hidden' name='_dsimanager_rights_submitted' value='1'>";
        if ($canedit) {
            echo "<input type='submit' name='update' class='submit' value=\"" . __('Enregistrer') . "\">";
        }
        echo "</td></tr>";
        echo "</table>";
        \Html::closeForm();
        echo "</div>";
    }

    public static function saveForProfile(int $profiles_id, array $input)
    {
        if (!\Session::haveRight('profile', UPDATE)) {
            return false;
        }

        foreach (self::getManagedRights() as $right_name => $right_infos) {
            // ALLSTANDARDRIGHT = lecture + création + modification + suppression
            // (pas seulement READ) : sinon les utilisateurs peuvent voir la
            // liste des tâches mais rien y faire (pas de bouton "Ajouter",
            // pas d'édition possible).
            if (isset($right_infos['levels'])) {
                // Somme des niveaux cochés. Un niveau autre que "Lecture"
                // implique la lecture (impossible de préparer une commande
                // qu'on ne peut pas voir).
                $value   = 0;
                $checked = $input["_right_{$right_name}"] ?? [];
                if (is_array($checked)) {
                    foreach (array_keys($right_infos['levels']) as $bits) {
                        if (!empty($checked[$bits])) {
                            $value |= (int) $bits;
                        }
                    }
                }
                if ($value !== 0) {
                    $value |= READ;
                }
            } else {
                $value = isset($input["_right_{$right_name}"]) ? ALLSTANDARDRIGHT : 0;
            }
            self::setRightValue($profiles_id, $right_name, $value);
        }

        return true;
    }
}
