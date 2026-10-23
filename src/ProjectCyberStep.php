<?php

namespace GlpiPlugin\Dsimanager;

use CommonDBTM;
use CommonGLPI;

/**
 * Étapes d'un projet Cybersécurité (Module C), affichées et gérées dans un
 * onglet dédié sur la fiche ProjectCyber - même technique que l'onglet
 * Étapes du Module B (ProjectDataStep), déjà éprouvée dans ce plugin.
 */
class ProjectCyberStep extends CommonDBTM
{
    public static $rightname = PLUGIN_DSIMANAGER_RIGHT_CYBER;

    public static function getTypeName($nb = 0)
    {
        return _n('Étape', 'Étapes', $nb, 'dsimanager');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === ProjectCyber::class) {
            $count = countElementsInTable(self::getTable(), [
                'plugin_dsimanager_projectcybers_id' => $item->getID(),
            ]);
            return self::createTabEntry(self::getTypeName(2), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === ProjectCyber::class) {
            self::showForProject((int) $item->getID());
        }
        return true;
    }

    public static function showForProject(int $projectcybers_id)
    {
        global $DB;

        $canedit = \Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_CYBER, UPDATE);

        $steps = $DB->request([
            'FROM'  => ProjectCyberStep::getTable(),
            'WHERE' => ['plugin_dsimanager_projectcybers_id' => $projectcybers_id],
            'ORDER' => ['ranking ASC', 'id ASC'],
        ]);

        echo "<div class='firstbloc'>";
        // Un seul <form> pour tout le tableau : un <form> par <tr> est
        // invalide en HTML et les navigateurs le déplacent hors du tableau
        // au moment du rendu, ce qui déconnecte le bouton de ses champs et
        // empêche toute soumission - c'est ce qui causait le bouton muet.
        echo "<form method='post' action='" . \Plugin::getWebDir('dsimanager') . "/front/projectcyberstep.form.php'>";
        echo "<input type='hidden' name='plugin_dsimanager_projectcybers_id' value='{$projectcybers_id}'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th>" . __('Étape', 'dsimanager') . "</th>";
        echo "<th>" . __('Description', 'dsimanager') . "</th>";
        echo "<th>" . __('Avancement (%)', 'dsimanager') . "</th>";
        if ($canedit) {
            echo "<th></th>";
        }
        echo "</tr>";

        foreach ($steps as $step) {
            $id = (int) $step['id'];
            echo "<tr class='tab_bg_1'>";
            echo "<td>";
            if ($canedit) {
                echo "<input type='text' name='steps[{$id}][name]' value=\""
                   . htmlspecialchars($step['name']) . "\" style='width:100%;'>";
            } else {
                echo htmlspecialchars($step['name']);
            }
            echo "</td>";
            echo "<td>";
            if ($canedit) {
                echo "<input type='text' name='steps[{$id}][description]' value=\""
                   . htmlspecialchars($step['description'] ?? '') . "\" style='width:100%;'>";
            } else {
                echo htmlspecialchars($step['description'] ?? '');
            }
            echo "</td>";
            echo "<td>";
            if ($canedit) {
                echo "<input type='number' name='steps[{$id}][percent_done]' value='{$step['percent_done']}' "
                   . "min='0' max='100' style='width:80px;'>";
            } else {
                echo (int) $step['percent_done'] . "%";
            }
            echo "</td>";
            if ($canedit) {
                echo "<td>";
                echo "<button type='submit' name='delete_step' value='{$id}' class='btn btn-sm btn-danger'>"
                   . __('Supprimer') . "</button>";
                echo "</td>";
            }
            echo "</tr>";
        }

        if ($canedit) {
            echo "<tr class='tab_bg_2'>";
            echo "<td><input type='text' name='new_step[name]' placeholder='" . __('Nouvelle étape', 'dsimanager') . "' style='width:100%;'></td>";
            echo "<td><input type='text' name='new_step[description]' placeholder='" . __('Description', 'dsimanager') . "' style='width:100%;'></td>";
            echo "<td><input type='number' name='new_step[percent_done]' value='0' min='0' max='100' style='width:80px;'></td>";
            echo "<td></td>";
            echo "</tr>";
            echo "<tr><td colspan='4' class='center'>";
            echo "<button type='submit' name='save_steps' class='btn btn-primary'>" . __('Enregistrer les étapes', 'dsimanager') . "</button>";
            echo "</td></tr>";
        }

        echo "</table>";
        \Html::closeForm();
        echo "</div>";
    }

    /**
     * Recalcule les compteurs dénormalisés sur le projet parent
     * (progress_percent, steps_total, steps_done) après toute modification
     * des étapes. Appelée depuis front/projectcyberstep.form.php après
     * chaque ajout / modification / suppression.
     */
    public static function recomputeParentProgress(int $projectcybers_id): void
    {
        global $DB;

        $steps_total = 0;
        $steps_done  = 0;
        $sum_percent = 0;

        $result = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_dsimanager_projectcybers_id' => $projectcybers_id],
        ]);

        foreach ($result as $row) {
            $steps_total++;
            $sum_percent += (int) $row['percent_done'];
            if ((int) $row['percent_done'] >= 100) {
                $steps_done++;
            }
        }

        $progress_percent = $steps_total > 0 ? (int) round($sum_percent / $steps_total) : 0;

        $DB->update(ProjectCyber::getTable(), [
            'progress_percent' => $progress_percent,
            'steps_total'      => $steps_total,
            'steps_done'       => $steps_done,
        ], ['id' => $projectcybers_id]);
    }
}
