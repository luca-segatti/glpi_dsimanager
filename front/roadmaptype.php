<?php

use GlpiPlugin\Dsimanager\RoadmapType;
use GlpiPlugin\Dsimanager\RoadmapItem;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_ROADMAP, READ);

Html::header(
    RoadmapType::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'dsimanager',
    RoadmapItem::class,
    'roadmap'
);

echo "<div class='firstbloc'><a href='" . Plugin::getWebDir('dsimanager') . "/front/roadmaptype.form.php' class='btn btn-primary btn-sm'>" . __('Ajouter', 'dsimanager') . "</a></div>";

Search::show(RoadmapType::class);

Html::footer();
