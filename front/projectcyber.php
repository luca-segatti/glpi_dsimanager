<?php

use GlpiPlugin\Dsimanager\ProjectCyber;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_CYBER, READ);

Html::header(
    ProjectCyber::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'dsimanager',
    ProjectCyber::class,
    'projectcyber'
);

Search::show(ProjectCyber::class);

Html::footer();
