<?php

use GlpiPlugin\Dsimanager\RoadmapItem;
use GlpiPlugin\Dsimanager\RoadmapType;
use GlpiPlugin\Dsimanager\RoadmapPriority;
use GlpiPlugin\Dsimanager\RoadmapStatus;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, READ);

$canedit = Session::haveRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, UPDATE);

global $DB;

$types      = iterator_to_array($DB->request(['FROM' => RoadmapType::getTable()]));
$priorities = iterator_to_array($DB->request(['FROM' => RoadmapPriority::getTable()]));
$statuses   = iterator_to_array($DB->request(['FROM' => RoadmapStatus::getTable()]));

$items = iterator_to_array($DB->request([
    'FROM'  => RoadmapItem::getTable(),
    'WHERE' => ['is_archived' => 1],
    'ORDER' => ['date_archived DESC'],
]));

Html::header(
    __('Projets Roadmap archivés', 'dsimanager'),
    $_SERVER['PHP_SELF'],
    'dsimanager',
    RoadmapItem::class,
    'roadmap'
);

echo "<div class='firstbloc'>";
echo "<a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmap.php' class='btn btn-outline-secondary btn-sm'>"
   . __('← Retour à la Roadmap', 'dsimanager') . "</a>";
echo "</div>";

echo "<div class='firstbloc'>";
if (!count($items)) {
    echo "<p class='text-muted'>" . __('Aucun projet archivé pour le moment.', 'dsimanager') . "</p>";
} else {
    echo "<table class='tab_cadre_fixe'>";
    echo "<tr><th>" . __('Description', 'dsimanager') . "</th>";
    echo "<th>" . __('Type', 'dsimanager') . "</th>";
    echo "<th>" . __('Statut', 'dsimanager') . "</th>";
    echo "<th>" . __('Archivé le', 'dsimanager') . "</th>";
    echo "<th>" . __('Archivé par', 'dsimanager') . "</th>";
    if ($canedit) {
        echo "<th></th>";
    }
    echo "</tr>";

    foreach ($items as $item) {
        $id = (int) $item['id'];
        $type_name   = $types[$item['plugin_dsimanager_roadmaptypes_id']]['name'] ?? '';
        $status_name = $statuses[$item['plugin_dsimanager_roadmapstatuses_id']]['name'] ?? '';

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . htmlspecialchars($item['description']) . "</td>";
        echo "<td>" . htmlspecialchars($type_name) . "</td>";
        echo "<td>" . htmlspecialchars($status_name) . "</td>";
        echo "<td>" . ($item['date_archived'] ? Html::convDateTime($item['date_archived']) : '') . "</td>";
        echo "<td>" . ($item['archived_by'] ? htmlspecialchars(getUserName($item['archived_by'])) : '') . "</td>";
        if ($canedit) {
            echo "<td>";
            echo "<form method='post' action='" . Plugin::getWebDir('dsimanager') . "/front/roadmap_save.php' style='display:inline;'>";
            echo "<input type='hidden' name='restore_item' value='{$id}'>";
            echo "<button type='submit' class='btn btn-sm btn-success'>" . __('Restaurer', 'dsimanager') . "</button>";
            Html::closeForm();
            echo " <a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmapitem.form.php?id={$id}' class='btn btn-sm btn-outline-primary'>"
               . __('Voir la fiche', 'dsimanager') . "</a>";
            echo "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

Html::footer();
