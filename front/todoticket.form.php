<?php

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_TODO, UPDATE);

// NOTE : pas de Session::checkCSRF() manuel ici - GLPI 11 le vérifie
// automatiquement au niveau du noyau pour toute requête POST, à condition
// que le formulaire soit fermé avec Html::closeForm() (qui pose le jeton) -
// c'est le cas ici. Un appel manuel en plus provoquerait un rejet (jeton
// déjà consommé par la vérification automatique).

global $DB;

$todos_id = (int) ($_POST['plugin_dsimanager_todos_id'] ?? 0);

if ($todos_id <= 0) {
    Html::back();
}

if (isset($_POST['link_ticket']) && !empty($_POST['new_tickets_id'])) {
    $tickets_id = (int) $_POST['new_tickets_id'];
    $exists = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_plugin_dsimanager_todos_tickets',
        'WHERE' => [
            'plugin_dsimanager_todos_id' => $todos_id,
            'tickets_id'                => $tickets_id,
        ],
    ])->current()['cpt'] > 0;

    if (!$exists) {
        $DB->insert('glpi_plugin_dsimanager_todos_tickets', [
            'plugin_dsimanager_todos_id' => $todos_id,
            'tickets_id'                => $tickets_id,
        ]);
    }
} elseif (isset($_POST['unlink_ticket'])) {
    $DB->delete('glpi_plugin_dsimanager_todos_tickets', [
        'id' => (int) $_POST['unlink_ticket'],
    ]);
}

Html::back();
