<?php

use GlpiPlugin\Dsimanager\Todo;

include('../../../inc/includes.php');

Session::checkRight(PLUGIN_DSIMANAGER_RIGHT_TODO, READ);

Html::header(
    Todo::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'dsimanager',
    Todo::class,
    'todo'
);

Search::show(Todo::class);

Html::footer();
