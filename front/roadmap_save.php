<?php

use GlpiPlugin\Dsimanager\RoadmapItem;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, UPDATE);

// NOTE : pas de Session::checkCSRF() manuel ici - GLPI 11 le vérifie
// automatiquement au niveau du noyau pour toute requête POST, à condition
// que le formulaire soit fermé avec Html::closeForm() (qui pose le jeton),
// ce qui est le cas dans roadmap.php et roadmap_team.php. Un appel manuel
// en plus provoquerait un rejet (jeton déjà consommé).

global $DB;

// --- Équipe : ajout d'un membre ---
if (isset($_POST['add_team_member']) && !empty($_POST['add_team_users_id'])) {
    $users_id = (int) $_POST['add_team_users_id'];
    $exists = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_plugin_dsimanager_team',
        'WHERE' => ['users_id' => $users_id],
    ])->current()['cpt'] > 0;
    if (!$exists) {
        $DB->insert('glpi_plugin_dsimanager_team', ['users_id' => $users_id]);
    }
    Html::back();
}

// --- Équipe : retrait d'un membre ---
if (isset($_POST['remove_team_member'])) {
    $DB->delete('glpi_plugin_dsimanager_team', ['id' => (int) $_POST['remove_team_member']]);
    Html::back();
}

// --- Équipe : enregistrement des initiales ---
if (isset($_POST['save_initials']) && isset($_POST['team_id'])) {
    $DB->update('glpi_plugin_dsimanager_team', [
        'initials' => trim($_POST['initials'] ?? ''),
    ], ['id' => (int) $_POST['team_id']]);
    Html::back();
}

// --- Congés : enregistrement global de la grille (jours + note) ---
if (isset($_POST['save_conges']) && isset($_POST['conges']) && is_array($_POST['conges'])) {
    foreach ($_POST['conges'] as $users_id => $weeks) {
        $users_id = (int) $users_id;
        foreach ($weeks as $week_date => $data) {
            $days_off = (int) ($data['days_off'] ?? 0);
            $note     = trim($data['note'] ?? '');
            $exists = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_plugin_dsimanager_conges',
                'WHERE' => ['users_id' => $users_id, 'week_date' => $week_date],
            ])->current()['cpt'] > 0;

            if ($days_off === 0 && $note === '') {
                if ($exists) {
                    $DB->delete('glpi_plugin_dsimanager_conges', ['users_id' => $users_id, 'week_date' => $week_date]);
                }
                continue;
            }

            if ($exists) {
                $DB->update('glpi_plugin_dsimanager_conges', ['days_off' => $days_off, 'note' => $note], ['users_id' => $users_id, 'week_date' => $week_date]);
            } else {
                $DB->insert('glpi_plugin_dsimanager_conges', ['users_id' => $users_id, 'week_date' => $week_date, 'days_off' => $days_off, 'note' => $note]);
            }
        }
    }
    Html::back();
}

// --- Roadmap : suppression (gérée désormais depuis la fiche dédiée
// roadmapitem.form.php, action "purge" - conservé ici uniquement pour ne
// pas casser un éventuel lien existant vers cette action) ---
if (isset($_POST['delete_item'])) {
    $item = new RoadmapItem();
    $item->delete(['id' => (int) $_POST['delete_item']]);
    $DB->delete('glpi_plugin_dsimanager_roadmapcells', ['plugin_dsimanager_roadmapitems_id' => (int) $_POST['delete_item']]);
    Html::back();
}

// --- Roadmap : restauration rapide depuis la page des projets archivés
// (même mécanisme que la case à cocher du formulaire - passe par
// update(), donc RoadmapItem::handleArchiveTransition() horodate et
// journalise normalement). ---
if (isset($_POST['restore_item'])) {
    $item = new RoadmapItem();
    $item->update([
        'id'          => (int) $_POST['restore_item'],
        'is_archived' => 0,
    ]);
    Html::back();
}

// --- Roadmap : remplissage rapide d'une plage de semaines pour un projet
// donné (outil sur la fiche projet, voir RoadmapItem::showForm()). ---
if (isset($_POST['bulk_fill_apply'])) {
    $project_id  = (int) ($_POST['bulk_fill_project_id'] ?? 0);
    $category_id = (int) ($_POST['bulk_fill_category_id'] ?? 0);
    $date_from   = $_POST['bulk_fill_date_from'] ?? '';
    $date_to     = $_POST['bulk_fill_date_to'] ?? '';

    if ($project_id > 0 && $date_from !== '' && $date_to !== '') {
        $monday_of = function (string $date): string {
            $dt  = new DateTime($date);
            $dow = (int) $dt->format('N');
            if ($dow > 1) {
                $dt->modify('-' . ($dow - 1) . ' days');
            }
            return $dt->format('Y-m-d');
        };

        $from = $monday_of($date_from);
        $to   = $monday_of($date_to);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $cursor = new DateTime($from);
        $end    = new DateTime($to);
        while ($cursor <= $end) {
            $week_date = $cursor->format('Y-m-d');
            $exists = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_plugin_dsimanager_roadmapcells',
                'WHERE' => ['plugin_dsimanager_roadmapitems_id' => $project_id, 'week_date' => $week_date],
            ])->current()['cpt'] > 0;

            if ($category_id === 0) {
                if ($exists) {
                    $DB->delete('glpi_plugin_dsimanager_roadmapcells', ['plugin_dsimanager_roadmapitems_id' => $project_id, 'week_date' => $week_date]);
                }
            } elseif ($exists) {
                $DB->update('glpi_plugin_dsimanager_roadmapcells', ['plugin_dsimanager_roadmapweekcategories_id' => $category_id], ['plugin_dsimanager_roadmapitems_id' => $project_id, 'week_date' => $week_date]);
            } else {
                $DB->insert('glpi_plugin_dsimanager_roadmapcells', [
                    'plugin_dsimanager_roadmapitems_id'          => $project_id,
                    'week_date'                                 => $week_date,
                    'plugin_dsimanager_roadmapweekcategories_id' => $category_id,
                ]);
            }
            $cursor->modify('+7 days');
        }
    }
    Html::back();
}

// --- Roadmap : enregistrement global (cases hebdomadaires uniquement).
// Tous les autres champs (type, description, détails, priorité,
// ressource(s), statut, budget, ordre) se modifient désormais sur la
// fiche dédiée (roadmapitem.form.php) - la grille ne poste plus que les
// cases de la grille hebdomadaire.
if (isset($_POST['save_roadmap'])) {
    if (isset($_POST['cells']) && is_array($_POST['cells'])) {
        foreach ($_POST['cells'] as $item_id => $weeks) {
            $item_id = (int) $item_id;
            foreach ($weeks as $week_date => $category_id) {
                $category_id = (int) $category_id;
                $exists = $DB->request([
                    'COUNT' => 'cpt',
                    'FROM'  => 'glpi_plugin_dsimanager_roadmapcells',
                    'WHERE' => ['plugin_dsimanager_roadmapitems_id' => $item_id, 'week_date' => $week_date],
                ])->current()['cpt'] > 0;

                if ($category_id === 0) {
                    if ($exists) {
                        $DB->delete('glpi_plugin_dsimanager_roadmapcells', ['plugin_dsimanager_roadmapitems_id' => $item_id, 'week_date' => $week_date]);
                    }
                    continue;
                }

                if ($exists) {
                    $DB->update('glpi_plugin_dsimanager_roadmapcells', ['plugin_dsimanager_roadmapweekcategories_id' => $category_id], ['plugin_dsimanager_roadmapitems_id' => $item_id, 'week_date' => $week_date]);
                } else {
                    $DB->insert('glpi_plugin_dsimanager_roadmapcells', [
                        'plugin_dsimanager_roadmapitems_id'           => $item_id,
                        'week_date'                                  => $week_date,
                        'plugin_dsimanager_roadmapweekcategories_id' => $category_id,
                    ]);
                }
            }
        }
    }

    Html::back();
}

Html::back();
