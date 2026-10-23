<?php

use GlpiPlugin\Dsimanager\ProjectCyberStep;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_CYBER, UPDATE);

// NOTE : pas de Session::checkCSRF() manuel ici - GLPI 11 le vérifie
// automatiquement au niveau du noyau pour toute requête POST, à condition
// que le formulaire soit fermé avec Html::closeForm() (qui pose le jeton) -
// c'est le cas ici. Un appel manuel en plus provoquerait un rejet (jeton
// déjà consommé par la vérification automatique).

$projectcybers_id = (int) ($_POST['plugin_dsimanager_projectcybers_id'] ?? 0);

if ($projectcybers_id <= 0) {
    Html::back();
}

// Suppression d'une étape (bouton "Supprimer" de la ligne concernée,
// value = id de l'étape).
if (isset($_POST['delete_step'])) {
    $step = new ProjectCyberStep();
    $step->delete(['id' => (int) $_POST['delete_step']]);
    ProjectCyberStep::recomputeParentProgress($projectcybers_id);
    Html::back();
}

// Enregistrement global : mise à jour de toutes les étapes existantes
// postées (steps[id][...]) + ajout d'une nouvelle étape si son titre est
// renseigné (new_step[...]).
if (isset($_POST['save_steps'])) {
    if (isset($_POST['steps']) && is_array($_POST['steps'])) {
        foreach ($_POST['steps'] as $id => $data) {
            $step = new ProjectCyberStep();
            $step->update([
                'id'           => (int) $id,
                'name'         => $data['name'] ?? '',
                'description'  => $data['description'] ?? '',
                'percent_done' => (int) ($data['percent_done'] ?? 0),
            ]);
        }
    }

    if (!empty($_POST['new_step']['name'])) {
        $step = new ProjectCyberStep();
        $step->add([
            'plugin_dsimanager_projectcybers_id' => $projectcybers_id,
            'name'                             => $_POST['new_step']['name'],
            'description'                      => $_POST['new_step']['description'] ?? '',
            'percent_done'                     => (int) ($_POST['new_step']['percent_done'] ?? 0),
            'ranking'                          => 0,
        ]);
    }

    ProjectCyberStep::recomputeParentProgress($projectcybers_id);
}

Html::back();
