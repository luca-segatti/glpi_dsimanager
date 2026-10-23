<?php

use GlpiPlugin\Dsimanager\RoadmapWeekCategory;
use GlpiPlugin\Dsimanager\RoadmapItem;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, READ);

Html::header(
    RoadmapWeekCategory::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'dsimanager',
    RoadmapItem::class,
    'roadmap'
);

echo "<div class='firstbloc'><a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmapweekcategory.form.php' class='btn btn-primary btn-sm'>" . __('Ajouter', 'dsimanager') . "</a></div>";

Search::show(RoadmapWeekCategory::class);

Html::footer();
