<?php

use GlpiPlugin\Dsimanager\RoadmapItem;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, READ);

$canedit = Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, UPDATE);

global $DB;

$team = iterator_to_array($DB->request([
    'SELECT' => ['glpi_plugin_dsimanager_team.id AS team_id', 'glpi_plugin_dsimanager_team.initials', 'glpi_users.id AS users_id', 'glpi_users.firstname', 'glpi_users.realname', 'glpi_users.name AS login'],
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

Html::header(
    __('Équipe Roadmap', 'dsimanager'),
    $_SERVER['PHP_SELF'],
    'dsimanager',
    RoadmapItem::class,
    'roadmap'
);

echo "<div class='firstbloc'>";
echo "<p>" . __('Cette équipe est partagée entre les pages Roadmap et Roadmap Admin (bandeau congés). Les initiales servent à identifier la ressource sur la grille Roadmap.', 'dsimanager') . "</p>";
echo "<table class='tab_cadre_fixe'><tr><th>" . __('Membre', 'dsimanager') . "</th>";
echo "<th>" . __('Initiales', 'dsimanager') . "</th>";
if ($canedit) {
    echo "<th></th>";
}
echo "</tr>";

// Un <form> par ligne (initiales + retrait dans le même formulaire, deux
// boutons distincts) plutôt qu'un grand formulaire englobant tout le
// tableau : évite tout risque d'imbrication de <form>.
foreach ($team as $member) {
    $label = trim($member['firstname'] . ' ' . $member['realname']) ?: $member['login'];
    echo "<tr class='tab_bg_1'>";
    if ($canedit) {
        echo "<td colspan='3' style='padding:0;'>";
        echo "<form method='post' action='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_save.php' class='form-inline' style='display:flex; align-items:center; gap:8px; padding:6px;'>";
        echo "<input type='hidden' name='team_id' value='{$member['team_id']}'>";
        echo "<span style='flex:1;'>" . htmlspecialchars($label) . "</span>";
        echo "<input type='text' name='initials' value=\"" . htmlspecialchars($member['initials'] ?? '') . "\" maxlength='10' style='width:70px;' placeholder='" . __('ex: JD', 'dsimanager') . "'>";
        echo "<button type='submit' name='save_initials' class='btn btn-sm btn-primary'>" . __('Enregistrer', 'dsimanager') . "</button>";
        echo "<button type='submit' name='remove_team_member' value='{$member['team_id']}' class='btn btn-sm btn-danger'>" . __('Retirer', 'dsimanager') . "</button>";
        Html::closeForm();
        echo "</td>";
    } else {
        echo "<td>" . htmlspecialchars($label) . "</td>";
        echo "<td>" . htmlspecialchars($member['initials'] ?? '') . "</td>";
    }
    echo "</tr>";
}

if ($canedit) {
    echo "<tr class='tab_bg_2'><td colspan='3'>";
    echo "<form method='post' action='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_save.php' class='form-inline'>";
    Dropdown::show('User', ['name' => 'add_team_users_id', 'right' => 'all']);
    echo "<button type='submit' name='add_team_member' class='btn btn-sm btn-success'>" . __('Ajouter à l\'équipe', 'dsimanager') . "</button>";
    Html::closeForm();
    echo "</td></tr>";
}

echo "</table>";
echo "</div>";

Html::footer();
