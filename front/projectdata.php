<?php

use GlpiPlugin\Dsimanager\ProjectData;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_DATA, READ);

Html::header(
    ProjectData::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'dsimanager',
    ProjectData::class,
    'projectdata'
);

Search::show(ProjectData::class);

Html::footer();
